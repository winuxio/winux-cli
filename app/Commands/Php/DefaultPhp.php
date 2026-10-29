<?php

namespace App\Commands\Php;

use App\Classes\Settings;
use App\Services\PhpFpm;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{confirm, error, info, select};

class DefaultPhp extends Command
{
	protected $signature = 'php:default
		{version? : PHP version, e.g. 8.4}
		{--cli : Also make it the system `php` CLI (update-alternatives)}';

	protected $description = 'Set the PHP version new sites use (and optionally the php CLI)';

	public function handle(): int
	{
		$installed = PhpFpm::installedVersions();
		$version = $this->argument('version') ?? select('Default PHP version', $installed, default: PhpFpm::defaultVersion());

		if (!in_array($version, $installed, true)) {
			error("PHP $version isn't installed. Run `winux php:install $version` first.");
			return self::FAILURE;
		}

		// Checked before anything is saved, so a refused --cli doesn't leave the default half-changed
		$cliAllowed = version_compare($version, '8.3', '>=');
		if ($this->option('cli') && !$cliAllowed) {
			error("winux itself needs PHP 8.3+, so the `php` CLI can't be switched to $version. Nothing was changed.");
			return self::FAILURE;
		}

		Settings::set('php.default', $version);
		info("New sites will use PHP $version.");

		$cli = $this->option('cli') || ($cliAllowed && $this->input->isInteractive() && confirm("Also switch the `php` CLI to $version?", false));

		if ($cli) {
			$process = Process::run(['sudo', 'update-alternatives', '--set', 'php', "/usr/bin/php$version"]);
			if ($process->failed()) {
				error('Failed to switch the php CLI: ' . $process->errorOutput());
				return self::FAILURE;
			}
			info("`php` now runs PHP $version.");
		}

		return self::SUCCESS;
	}
}
