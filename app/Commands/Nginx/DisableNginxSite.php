<?php

namespace App\Commands\Nginx;

use App\Classes\Site;
use App\Services\Nginx;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;

class DisableNginxSite extends Command
{
	protected $signature = 'nginx:disable {domain}';
	protected $description = 'Disable an Nginx site by removing the symbolic link in sites-enabled';

	public function handle(): mixed
	{
		$domain = $this->argument('domain');

		if (Site::make($domain)->disable()) {
			$this->info('Site disabled successfully!');
			return 0;
		} else {
			$this->error('Failed to disable the site.');
			return 1;
		}
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
