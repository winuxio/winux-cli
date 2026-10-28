<?php

namespace App\Commands\Php;

use App\Classes\AptRepository;
use App\Services\PhpFpm;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{error, info, multiselect};

class InstallPhp extends Command
{
	protected $signature = 'php:install {versions?* : One or more versions, e.g. 8.3 8.4}';

	protected $description = 'Install PHP-FPM versions (with common extensions) from ondrej/sury';

	public function handle(): int
	{
		if (!AptRepository::ensurePhp()) {
			return self::FAILURE;
		}

		$versions = $this->argument('versions');
		if (empty($versions)) {
			$installed = PhpFpm::installedVersions();
			$versions = multiselect(
				label: 'Which PHP versions do you want to install?',
				options: array_values(array_diff(PhpFpm::availableVersions(), $installed)),
				required: true,
			);
		}

		foreach ($versions as $version) {
			if (!PhpFpm::isValidVersion($version)) {
				error("'$version' isn't a PHP version (expected something like 8.4).");
				return self::FAILURE;
			}
		}

		$failed = false;
		foreach ($versions as $version) {
			if (!PhpFpm::make($version)->install()) {
				$failed = true;
			}
		}

		if (!$failed) {
			info('Installed PHP ' . implode(', ', $versions) . '.');
		}

		return $failed ? self::FAILURE : self::SUCCESS;
	}
}
