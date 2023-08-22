<?php

namespace App\Commands\Postgres;

use App\Services\Postgres;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{alert, error, info};

class CreateDatabase extends Command
{
	/**
	 * The signature of the command.
	 *
	 * @var string
	 */
	protected $signature = 'postgres:create {database}';

	/**
	 * The description of the command.
	 *
	 * @var string
	 */
	protected $description = 'Create a new Postgres database (and optional user)';

	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function handle()
	{
		$db_name = $this->argument('database');
		// Convert hyphens to underscores
		$db_name = str_replace('-', '_', $db_name);
		// Convert the db name to a valid database name, (only lowercase letters and underscores)
		$db_name = preg_replace('/[^a-z0-9_]/', '', $db_name);

		$db_user = $db_name;
		$db_pass = \Illuminate\Support\Str::password(16, true, true, false);

		$postgres = Postgres::make();
		info('Latest PostgreSQL version installed: ' . $postgres->version->value);

		if (!$postgres->createDatabase($db_name)) {
			alert("Failed to create the database: $db_name");
			return 1;
		}

		info('Database created successfully!');

		if (!$postgres->createUser($db_user, $db_pass)) {
			alert("Failed to create the user: $db_user");
			return 1;
		}

		info('User created successfully!');

		if (!$postgres->grant($db_name, $db_user)) {
			alert("Failed to grant privileges on $db_name to $db_user");
			return 1;
		}

		info('Privileges granted successfully!');

		$this->newLine();
		info('Database: ' . $db_name . PHP_EOL . 'User: ' . $db_user . PHP_EOL . 'Password: ' . $db_pass);

		return 0;
	}

	/**
	 * Define the command's schedule.
	 *
	 * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
	 * @return void
	 */
	public function schedule(Schedule $schedule): void
	{
		// $schedule->command(static::class)->everyMinute();
	}
}
