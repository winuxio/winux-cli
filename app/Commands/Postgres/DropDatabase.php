<?php

namespace App\Commands\Postgres;

use App\Services\Postgres;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{error,info};

class DropDatabase extends Command
{
	/**
	 * The signature of the command.
	 *
	 * @var string
	 */
	protected $signature = 'postgres:delete {database}';

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
		// Convert hyphens to underscores
		$db_name = str_replace('-', '_', $db_name);
		// Convert the db name to a valid database name, (only lowercase letters and underscores)
		$db_name = preg_replace('/[^a-z0-9_]/', '', $db_name);
		// dd($db_name);
		$db_user = $db_name;

		$postgres = Postgres::make();
		if (!$postgres->dropDatabase($db_name)) {
			error('Failed to drop the database.');
		} else {
			info('Database dropped successfully!');
		}

		if (!$postgres->dropUser($db_user)) {
			error('Failed to drop the user.');
		} else {
			info('User dropped successfully!');
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
