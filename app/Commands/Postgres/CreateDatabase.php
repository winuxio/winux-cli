<?php

namespace App\Commands\Postgres;

use App\Classes\Project;
use App\Services\Postgres;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{alert, error, info, table};

class CreateDatabase extends Command
{
	protected $signature = 'postgres:create
		{database : Database name (also used as the username)}
		{--pg= : Postgres version (defaults to `winux postgres:default`)}';

	protected $description = 'Create a Postgres database and an owning user';

	public function handle(): int
	{
		$dbName = Project::databaseName($this->argument('database'));
		$password = Postgres::generatePassword();
		$postgres = Postgres::make($this->option('pg'));

		info("Using {$postgres->label()} on port {$postgres->getPort()}.");

		// Never silently adopt (and reset the password of) a database or user something else may be using
		if ($existing = $postgres->existing($dbName)) {
			error('The ' . implode(' and the ', $existing) . " already exist on {$postgres->label()}. Pick another name, or drop them first with `winux postgres:delete $dbName`.");
			return self::FAILURE;
		}

		if (!$postgres->createAll($dbName, password: $password)) {
			alert("Failed to create the database/user: $dbName");
			return self::FAILURE;
		}

		table(['Key', 'Value'], [
			['DB_PORT', (string) $postgres->getPort()],
			['DB_DATABASE', $dbName],
			['DB_USERNAME', $dbName],
			['DB_PASSWORD', $password],
		]);

		return self::SUCCESS;
	}
}
