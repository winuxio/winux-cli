<?php

namespace App\Commands;

use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{error, info, spin};

class SelfUpdate extends Command
{
	protected $signature = 'self-update {--check : Only report whether an update is available}';

	protected $description = 'Update winux to the latest GitHub release';

	public function handle(): int
	{
		$binary = \Phar::running(false);
		if ($binary === '') {
			error('self-update only works for the built winux binary. Use `git pull` in a source checkout.');
			return self::FAILURE;
		}

		$repository = config('winux.github.repository');
		$release = $this->latestRelease($repository);
		if ($release === null) {
			error("Couldn't fetch the latest release of $repository.");
			return self::FAILURE;
		}

		$current = ltrim((string) config('app.version'), 'v');
		$latest = ltrim($release['tag_name'], 'v');

		if (version_compare($latest, $current, '<=')) {
			info("winux is up to date ($current).");
			return self::SUCCESS;
		}

		info("Update available: $current → $latest");
		if ($this->option('check')) {
			return self::SUCCESS;
		}

		$asset = collect($release['assets'] ?? [])->firstWhere('name', config('winux.github.asset'));
		if (!$asset) {
			error("Release $latest has no '" . config('winux.github.asset') . "' asset.");
			return self::FAILURE;
		}

		$tmp = tempnam(sys_get_temp_dir(), 'winux-');
		$contents = spin(fn () => @file_get_contents($asset['browser_download_url'], context: $this->httpContext()), "Downloading winux $latest...");
		if ($contents === false || file_put_contents($tmp, $contents) === false) {
			error('Download failed.');
			return self::FAILURE;
		}
		chmod($tmp, 0755);

		// Sanity check the download before replacing ourselves
		if (Process::run(['php', $tmp, '--version'])->failed()) {
			@unlink($tmp);
			error('The downloaded binary failed to run; not updating.');
			return self::FAILURE;
		}

		$replace = is_writable($binary) && is_writable(dirname($binary))
			? Process::run(['mv', $tmp, $binary])
			: Process::run(['sudo', 'install', '-m', '0755', $tmp, $binary]);

		if ($replace->failed()) {
			error("Couldn't replace $binary: " . $replace->errorOutput());
			return self::FAILURE;
		}

		@unlink($tmp);
		info("Updated winux to $latest.");
		return self::SUCCESS;
	}

	protected function latestRelease(string $repository): ?array
	{
		$json = @file_get_contents("https://api.github.com/repos/$repository/releases/latest", context: $this->httpContext());
		return $json ? json_decode($json, true) : null;
	}

	protected function httpContext()
	{
		return stream_context_create(['http' => [
			'header' => "User-Agent: winux-cli\r\nAccept: application/vnd.github+json\r\n",
			'follow_location' => 1,
			'timeout' => 60,
		]]);
	}
}
