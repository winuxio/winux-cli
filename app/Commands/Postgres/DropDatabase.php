<?php

namespace App\Commands\Postgres;

use App\Services\Postgres;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class DropDatabase extends Command
{
	/**
	 * The signature of the command.
	 *
	 * @var string
	 */
	protected $signature = 'postgres:drop {database}';

	/**
	 * The description of the command.
	 *
	 * @var string
	 */
	protected $description = 'Drop a Postgres database (and user of the same name)';

	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function handle()
	{
		$db_name = $this->argument('database');
		$db_user = $db_name;

		$postgres = Postgres::make();
		if (!$postgres->dropDatabase($db_name)) {
			$this->error('Failed to drop the database.');
		} else {
			$this->info('Database dropped successfully!');
		}

		if (!$postgres->dropUser($db_user)) {
			$this->error('Failed to drop the user.');
		} else {
			$this->info('User dropped successfully!');
		}

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
