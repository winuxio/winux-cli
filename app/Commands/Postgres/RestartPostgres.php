<?php

namespace App\Commands\Postgres;

use App\Services\Postgres;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{error, info};

class RestartPostgres extends Command
{
	protected $signature = 'postgres:restart {version? : Only restart this version (default: all installed)}';

	protected $description = 'Restart Postgres';

	public function handle(): int
	{
		$version = $this->argument('version');
		$services = $version ? [Postgres::make($version)] : Postgres::instances();

		$failed = false;
		foreach ($services as $postgres) {
			if ($postgres->restart()) {
				info("{$postgres->label()} restarted.");
			} else {
				error("Failed to restart {$postgres->label()}.");
				$failed = true;
			}
		}

		return $failed ? self::FAILURE : self::SUCCESS;
	}
}
