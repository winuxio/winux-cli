<?php

namespace App\Commands;

use App\Classes\Releases;
use App\Classes\Settings;
use App\Classes\UpdateCheck;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use Throwable;

use function Laravel\Prompts\{error, info, note, spin, warning};

class SelfUpdate extends Command
{
	protected $signature = 'self-update
		{target? : A specific version to install, e.g. 1.2.0 (can be older than the current one)}
		{--check : Only report whether an update is available}
		{--rollback : Go back to the version that was installed before the last update}
		{--channel= : Switch update channel: stable or beta (remembered)}';

	protected $description = 'Update winux to the latest release';

	public function handle(): int
	{
		if ($channel = $this->option('channel')) {
			if (!in_array($channel, Releases::CHANNELS, true)) {
				error('The channel must be one of: ' . implode(', ', Releases::CHANNELS));
				return self::FAILURE;
			}
			Settings::set('update.channel', $channel);
			UpdateCheck::store(['checked_at' => 0]);
			info("Update channel set to $channel.");
		}

		$binary = Releases::binary();
		if ($binary === null) {
			error('self-update only works for the built winux binary. Use `git pull` in a source checkout.');
			return self::FAILURE;
		}

		if ($this->option('rollback')) {
			return $this->rollback($binary);
		}

		$current = Releases::current();
		$channel = Releases::channel();

		try {
			$releases = spin(fn () => Releases::all(), 'Checking for updates...');
		} catch (Throwable $e) {
			error("Couldn't fetch winux releases from GitHub: {$e->getMessage()}");
			return self::FAILURE;
		}

		if ($target = $this->argument('target')) {
			$release = Releases::find($releases, $target);
			if ($release === null) {
				error("winux $target isn't a published release.");
				return self::FAILURE;
			}
		} else {
			$release = Releases::latest($releases, $channel);
			UpdateCheck::store(['checked_at' => time(), 'latest' => $release['version'] ?? null]);

			// Dev builds have no comparable version, so any release counts as an update
			if ($release === null || ($current !== null && !Releases::isNewer($release['version'], $current))) {
				info("winux is up to date ($current, $channel channel).");
				return self::SUCCESS;
			}
		}

		if ($release['version'] === $current) {
			info("winux $current is already installed.");
			return self::SUCCESS;
		}

		$downgrade = $current !== null && !Releases::isNewer($release['version'], $current);
		info(($downgrade ? 'Downgrade' : 'Update') . ': ' . ($current ?? config('app.version')) . " → {$release['version']}");

		if (!$downgrade && $current !== null) {
			$this->showNotes(Releases::between($releases, $current, $release['version'], $channel));
		}

		if ($this->option('check')) {
			$this->line('  Run <options=bold>winux self-update</> to install it.');
			return self::SUCCESS;
		}

		try {
			[$download, $verified] = spin(fn () => Releases::download($release), "Downloading winux {$release['version']}...");
		} catch (Throwable $e) {
			error("Download failed: {$e->getMessage()}");
			return self::FAILURE;
		}

		if (!$verified) {
			warning("Release {$release['version']} has no published checksum, so the download can't be verified.");
		}

		if (!$this->runs($download, $release['version'])) {
			@unlink($download);
			error('The downloaded binary failed to run; nothing was changed.');
			return self::FAILURE;
		}

		if (!@copy($binary, Releases::previousBinary())) {
			warning("Couldn't keep a copy of the current version, so --rollback won't be available.");
		}

		UpdateCheck::store(['latest' => null]);

		return $this->replace($binary, $download, "Updated winux to {$release['version']}.");
	}

	protected function rollback(string $binary): int
	{
		$previous = Releases::previousBinary();
		if (!is_file($previous)) {
			error('There is no previous version to roll back to.');
			return self::FAILURE;
		}

		$version = $this->versionOf($previous);
		if ($version === null) {
			error("The saved previous version ($previous) doesn't run.");
			return self::FAILURE;
		}

		// Swap, so a second --rollback undoes the first
		$restore = tempnam(sys_get_temp_dir(), 'winux-');
		copy($previous, $restore);
		chmod($restore, 0755);
		copy($binary, $previous);

		UpdateCheck::store(['checked_at' => 0]);

		return $this->replace($binary, $restore, "Rolled back to winux $version.");
	}

	/**
	 * Replaces the running PHAR and exits straight away: once the file changes,
	 * this process can't load anything else from it.
	 */
	protected function replace(string $binary, string $new, string $message): int
	{
		$result = is_writable($binary) && is_writable(dirname($binary))
			? Process::run(['mv', $new, $binary])
			: Process::run(['sudo', 'install', '-m', '0755', $new, $binary]);

		@unlink($new);

		if ($result->failed()) {
			error("Couldn't replace $binary: " . trim($result->errorOutput()));
			return self::FAILURE;
		}

		info($message);

		if ($binary !== \Phar::running(false)) {
			return self::SUCCESS;
		}

		exit(self::SUCCESS);
	}

	protected function runs(string $path, string $version): bool
	{
		return $this->versionOf($path) === $version;
	}

	protected function versionOf(string $path): ?string
	{
		$result = Process::run([PHP_BINARY, $path, '--version', '--no-ansi']);
		if ($result->failed() || !preg_match('/v?(\d+\.\d+\.\d+[\w.\-]*)/', $result->output(), $match)) {
			return null;
		}

		return Releases::normalize($match[1]);
	}

	/**
	 * Release notes for each version the update brings, condensed for the terminal.
	 */
	protected function showNotes(array $releases): void
	{
		$lines = collect($releases)->flatMap(function (array $release) {
			$body = collect(preg_split('/\R/', $release['notes']))
				// release-please repeats the version as a heading; we print our own
				->reject(fn ($line) => preg_match('/^#{1,2}\s/', $line))
				->map(fn ($line) => preg_replace(
					// [text](url) → text, drop trailing commit hashes and **bold**, "* item" → "• item"
					['/\[([^\]]+)\]\([^)]+\)/', '/\s*\(\w{7,40}\)$/', '/\*\*/', '/^#+\s*/', '/^(\s*)[*-]\s+/'],
					['$1', '', '', '', '$1• '],
					rtrim($line)
				))
				->reject(fn ($line) => trim($line) === '');

			return ["<options=bold>{$release['version']}</>", ...$body->all(), ''];
		});

		if ($lines->isEmpty()) {
			return;
		}

		$limit = 30;
		foreach ($lines->take($limit) as $line) {
			$this->line("  $line");
		}

		if ($lines->count() > $limit) {
			$this->line('  <fg=gray>…and more: ' . ($releases[0]['url'] ?? '') . '</>');
		}
	}
}
