<?php

namespace App\Commands\Php;

use App\Classes\AptRepository;
use App\Classes\Site;
use App\Services\PhpFpm;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{note, table, warning};

class ListPhp extends Command
{
	protected $signature = 'php:list';

	protected $description = 'List installed and available PHP versions';

	public function handle(): int
	{
		$installed = PhpFpm::installedVersions();
		$available = PhpFpm::availableVersions();
		$default = PhpFpm::defaultVersion();
		$sitesByVersion = collect(Site::all())->groupBy(fn (Site $site) => $site->phpVersion());

		$versions = PhpFpm::sortVersions([...$installed, ...$available]);

		table(
			['Version', 'Installed', 'FPM', 'Default', 'Sites'],
			collect($versions)->map(function ($version) use ($installed, $default, $sitesByVersion) {
				$isInstalled = in_array($version, $installed, true);
				return [
					$version,
					$isInstalled ? 'yes' : '-',
					$isInstalled ? (PhpFpm::make($version)->isActive() ? 'running' : 'stopped') : '-',
					$version === $default ? '*' : '',
					(string) count($sitesByVersion->get($version, [])),
				];
			})->all()
		);

		if (!AptRepository::hasPhp()) {
			warning('The ondrej/sury PHP repository is not configured; only your distro\'s PHP is available.');
			note('Run `winux php:install <version>` to add it automatically.');
		}

		return self::SUCCESS;
	}
}
