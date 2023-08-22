<?php

namespace App\Commands\Nginx;

use App\Classes\Site;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

class DeleteNginxSite extends Command
{
	/**
	 * The signature of the command.
	 *
	 * @var string
	 */
	protected $signature = 'nginx:delete {domain}';

	/**
	 * The description of the command.
	 *
	 * @var string
	 */
	protected $description = 'Delete an Nginx site by removing the configuration file in sites-available and the symbolic link in sites-enabled';

	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function handle()
	{
		$domain = $this->argument('domain');
		$site = Site::make($domain);

		$this->call('nginx:disable', ['domain' => $domain]);

		// Check if the site exists
		if (!$site->delete()) {
			error('Failed to delete the site.');
			return 1;
		}

		info('Site deleted successfully!');
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
