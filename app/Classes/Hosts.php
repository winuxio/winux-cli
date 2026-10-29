<?php

namespace App\Classes;

use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\{error, info, note};

/**
 * Manages 127.0.0.1 entries for domains that don't resolve locally on their own
 * (anything outside the *.local.winux.io wildcard). Entries are tagged with '# winux'.
 * On WSL both the Linux and Windows hosts files are updated, since the browser runs on Windows.
 */
class Hosts
{
	public const MARKER = '# winux';

	public static function isValidDomain(string $domain): bool
	{
		return (bool) preg_match('/^(?=.{1,253}$)(\*\.)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/i', $domain);
	}

	/**
	 * True when the domain is covered by winux's public wildcard DNS (*.local.winux.io -> 127.0.0.1).
	 */
	public static function coveredByWildcard(string $domain): bool
	{
		return str_ends_with(strtolower($domain), '.' . config('winux.nginx.domain'));
	}

	/**
	 * @param string[] $domains
	 */
	public static function add(array $domains): bool
	{
		return static::update($domains, []);
	}

	/**
	 * @param string[] $domains
	 */
	public static function remove(array $domains): bool
	{
		return static::update([], $domains);
	}

	protected static function update(array $add, array $remove): bool
	{
		if (empty($add) && empty($remove)) {
			return true;
		}

		$success = static::updateLinux($add, $remove);

		if (Platform::isWsl()) {
			$success = static::updateWindows($add, $remove) && $success;
		}

		return $success;
	}

	/**
	 * Adds/removes winux-tagged entries, preserving everything else (and line endings).
	 */
	public static function rewrite(string $contents, array $add, array $remove): string
	{
		$eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";
		$managed = array_map('strtolower', [...$add, ...$remove]);

		$lines = collect(preg_split('/\r?\n/', rtrim($contents, "\r\n")))
			->reject(function ($line) use ($managed) {
				if (!str_ends_with(rtrim($line), static::MARKER)) {
					return false;
				}
				$fields = preg_split('/\s+/', trim($line));
				return in_array(strtolower($fields[1] ?? ''), $managed, true);
			})
			->values()
			->all();

		foreach ($add as $domain) {
			$lines[] = "127.0.0.1\t$domain " . static::MARKER;
		}

		return implode($eol, $lines) . $eol;
	}

	protected static function updateLinux(array $add, array $remove): bool
	{
		$current = @file_get_contents('/etc/hosts');
		if ($current === false) {
			error('Unable to read /etc/hosts.');
			return false;
		}

		$updated = static::rewrite($current, $add, $remove);
		if ($updated === $current) {
			return true;
		}

		$process = Process::input($updated)->run(['sudo', 'tee', '/etc/hosts']);
		if ($process->failed()) {
			error('Failed to update /etc/hosts: ' . $process->errorOutput());
			return false;
		}

		info('Updated /etc/hosts.');
		return true;
	}

	protected static function windowsHostsPath(): string
	{
		return trim(Process::run(['wslpath', '-u', 'C:\\Windows\\System32\\drivers\\etc\\hosts'])->output());
	}

	protected static function updateWindows(array $add, array $remove): bool
	{
		$path = static::windowsHostsPath();
		$current = $path ? @file_get_contents($path) : false;
		if ($current === false) {
			error('Unable to read the Windows hosts file.');
			return false;
		}

		$updated = static::rewrite($current, $add, $remove);
		if ($updated === $current) {
			return true;
		}

		// The new contents go in a file under the Windows %TEMP%, which the elevated PowerShell copies
		// into place. (Passing them inline breaks Windows' 32K command line limit for larger hosts files.)
		$staged = static::stageForWindows($updated);
		if ($staged === null) {
			return false;
		}
		[$linuxTemp, $windowsTemp] = $staged;

		note('Windows will ask for permission to update its hosts file.');
		$process = Process::timeout(300)->run(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', static::windowsCopyCommand($windowsTemp)]);

		$copyError = trim((string) @file_get_contents("$linuxTemp.err"));
		@unlink($linuxTemp);
		@unlink("$linuxTemp.err");

		clearstatcache();
		if (@file_get_contents($path) === $updated) {
			info('Updated the Windows hosts file.');
			return true;
		}

		if ($process->exitCode() === static::UAC_DECLINED) {
			error("The Windows hosts file was not updated: PowerShell couldn't elevate (was the UAC prompt declined?). " . trim($process->errorOutput()));
		} else {
			error('Failed to update the Windows hosts file: ' . ($copyError ?: trim($process->errorOutput()) ?: 'it still has its old contents.'));
		}

		return false;
	}

	/** Exit code the copy command uses when Windows' UAC prompt is declined (ERROR_CANCELLED) */
	public const UAC_DECLINED = 1223;

	/**
	 * Writes the contents to a new file in the Windows %TEMP% directory.
	 *
	 * @return array{0: string, 1: string}|null [Linux path, Windows path]
	 */
	protected static function stageForWindows(string $contents): ?array
	{
		$windowsDir = trim(Process::run(['cmd.exe', '/d', '/c', 'echo %TEMP%'])->output());
		$linuxDir = $windowsDir !== '' ? trim(Process::run(['wslpath', '-u', $windowsDir])->output()) : '';

		if ($linuxDir === '' || !is_dir($linuxDir)) {
			error("Couldn't find the Windows temp directory to stage the new hosts file in.");
			return null;
		}

		$name = 'winux-hosts-' . bin2hex(random_bytes(6));
		if (@file_put_contents("$linuxDir/$name", $contents) === false) {
			error("Couldn't write the new hosts file to $linuxDir.");
			return null;
		}

		return ["$linuxDir/$name", rtrim($windowsDir, '\\') . '\\' . $name];
	}

	/**
	 * A PowerShell command that copies the staged file over the hosts file in an elevated PowerShell
	 * (Windows shows a UAC prompt). It exits with UAC_DECLINED if the prompt is declined, and writes
	 * any copy error to "<staged file>.err".
	 */
	public static function windowsCopyCommand(string $windowsSource): string
	{
		$source = "'" . str_replace("'", "''", $windowsSource) . "'";
		$errorFile = "'" . str_replace("'", "''", $windowsSource . '.err') . "'";

		$inner = 'try { Copy-Item -LiteralPath ' . $source . ' -Destination (Join-Path $env:SystemRoot "System32\drivers\etc\hosts") -Force -ErrorAction Stop; exit 0 } '
			. 'catch { Set-Content -LiteralPath ' . $errorFile . ' -Value $_.Exception.Message; exit 1 }';
		$encoded = base64_encode(mb_convert_encoding($inner, 'UTF-16LE', 'UTF-8'));

		return "try { \$p = Start-Process powershell.exe -Verb RunAs -Wait -PassThru -WindowStyle Hidden -ArgumentList '-NoProfile','-EncodedCommand','$encoded'; exit \$p.ExitCode } "
			. 'catch { [Console]::Error.WriteLine($_.Exception.Message); exit ' . static::UAC_DECLINED . ' }';
	}
}
