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

		// The hosts file needs admin rights: run an elevated PowerShell (Windows shows a UAC prompt)
		$payload = base64_encode($updated);
		$inner = '$p = Join-Path $env:SystemRoot "System32\drivers\etc\hosts"; '
			. "[IO.File]::WriteAllText(\$p, [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('$payload')))";
		$encoded = base64_encode(mb_convert_encoding($inner, 'UTF-16LE', 'UTF-8'));
		$outer = "Start-Process powershell.exe -Verb RunAs -Wait -WindowStyle Hidden -ArgumentList '-NoProfile','-EncodedCommand','$encoded'";

		note('Windows will ask for permission to update its hosts file.');
		Process::timeout(300)->run(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', $outer]);

		clearstatcache();
		if (@file_get_contents($path) !== $updated) {
			error('The Windows hosts file was not updated (was the UAC prompt declined?).');
			return false;
		}

		info('Updated the Windows hosts file.');
		return true;
	}
}
