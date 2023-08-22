<?php

namespace App\Commands\Postgres;

use App\Enums\Versions\PostgresVersion;
use App\Services\Postgres;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;

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
		$db_user = $db_name;
		$db_pass = \Illuminate\Support\Str::password(16, true, true, false);

		$postgres = Postgres::make();
		$this->info('Latest PostgreSQL version installed: ' . $postgres->version->value);

		if (!$postgres->createDatabase($db_name)) {
			$this->error('Failed to create the database.');
			return 1;
		}

		$this->info('Database created successfully!');

		if (!$postgres->createUser($db_user, $db_pass)) {
			$this->error('Failed to create the user.');
			return 1;
		}

		$this->info('User created successfully!');

		if (!$postgres->grant($db_name, $db_user)) {
			$this->error('Failed to grant privileges to the user.');
			return 1;
		}

		$this->info('Privileges granted successfully!');

		$this->newLine();
		$this->info('Database: ' . $db_name . PHP_EOL . 'User: ' . $db_user . PHP_EOL . 'Password: ' . $db_pass);

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
