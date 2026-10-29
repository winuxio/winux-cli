<?php

namespace App\Commands\Site;

use App\Commands\Concerns\ResolvesSite;
use App\Services\PhpFpm;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{confirm, error, info, note, select, warning};

class SetSitePhp extends Command
{
	use ResolvesSite;

	protected $signature = 'site:php
		{version? : PHP version to use, e.g. 8.4}
		{--site= : Site name or domain (defaults to the project in the current directory)}
		{--force : Switch even if the project\'s Composer dependencies don\'t support that version}';

	protected $description = "Change the PHP version a site's requests are served by";

	public function handle(): int
	{
		$site = $this->resolveSite($this->option('site'));
		if (!$site) {
			return self::FAILURE;
		}

		$current = $site->phpVersion();
		if ($current === null) {
			error("{$site->configFile} doesn't use php-fpm; nothing to change.");
			return self::FAILURE;
		}

		$installed = PhpFpm::installedVersions();
		$version = $this->argument('version') ?? select(
			label: "PHP version for {$site->domain} (currently $current)",
			options: $installed,
			default: $current,
		);

		if (!PhpFpm::isValidVersion($version)) {
			error("'$version' isn't a PHP version (expected something like 8.4).");
			return self::FAILURE;
		}

		if ($version === $current) {
			info("{$site->domain} already uses PHP $version.");
			return self::SUCCESS;
		}

		$fpm = PhpFpm::make($version);
		if (!in_array($version, $installed, true)) {
			warning("PHP $version FPM isn't installed.");
			if (!confirm("Install PHP $version now?", true) || !$fpm->install()) {
				return self::FAILURE;
			}
		}

		// Switching to a PHP the locked dependencies don't support makes every request a 500
		if ($issues = $site->platformIssues($version)) {
			warning("{$site->domain}'s Composer dependencies don't support PHP $version:");
			$this->line($issues);
			note("Switching anyway makes every request fail until you run `composer update` (with PHP $version) in {$site->projectPath()}.");

			if (!$this->option('force') && !confirm("Switch to PHP $version anyway?", false)) {
				error("{$site->domain} still uses PHP $current. Pass --force to switch anyway.");
				return self::FAILURE;
			}
		}

		if (!$fpm->isActive() && !$fpm->start()) {
			return self::FAILURE;
		}

		if (!$site->setPhpVersion($version)) {
			return self::FAILURE;
		}

		info("{$site->domain} now uses PHP $version (was $current).");
		return self::SUCCESS;
	}
}
