<?php

namespace App\Commands\Postgres;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

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

		$this->info("DB Values: $db_name, $db_user, $db_pass");

		/*
			Need to effectively run this

			```sql
			create database DBNAME;
			create user DBUSER with encrypted password 'PASSWORD';
			grant all privileges on database DBNAME to DBUSER;
			```

			** If we're on PostgreSQL15, we'll also need to run this:
			
			`GRANT ALL ON SCHEMA public TO DBUSER`
		*/
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
