<?php

namespace App\Commands\Postgres;

use App\Classes\Project;
use App\Services\Postgres;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{confirm, error, info};

class DropDatabase extends Command
{
	protected $signature = 'postgres:delete
		{database : Database name (its same-named user is dropped too)}
		{--pg= : Postgres version (defaults to `winux postgres:default`)}
		{--force : Skip the confirmation}';

	protected $description = 'Drop a Postgres database and its user';

	public function handle(): int
	{
		$dbName = Project::databaseName($this->argument('database'));
		$postgres = Postgres::make($this->option('pg'));

		if (!$this->option('force') && !confirm("Drop database '$dbName' and user '$dbName' from {$postgres->label()}?", false)) {
			return self::FAILURE;
		}

		if (!$postgres->dropAll($dbName)) {
			error('Failed to drop some (or all) database resources.');
			return self::FAILURE;
		}

		info("Dropped $dbName.");
		return self::SUCCESS;
	}
}
