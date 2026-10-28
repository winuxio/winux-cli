<?php

namespace App\Commands\Postgres;

use App\Classes\Settings;
use App\Services\Postgres;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{error, info, select};

class DefaultPostgres extends Command
{
	protected $signature = 'postgres:default {version? : Postgres major version, e.g. 16}';

	protected $description = 'Set the Postgres version new projects get their database on';

	public function handle(): int
	{
		$installed = Postgres::installedVersions();
		$version = $this->argument('version') ?? select('Default Postgres version', $installed, default: Postgres::defaultVersion());

		if (!in_array($version, $installed, true)) {
			error("Postgres $version isn't installed. Run `winux service:install postgres $version` first.");
			return self::FAILURE;
		}

		Settings::set('postgres.default', $version);
		info("New projects will use Postgres $version (port " . Postgres::make($version)->getPort() . ').');

		return self::SUCCESS;
	}
}
