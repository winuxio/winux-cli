<?php

namespace App\Commands\Nginx;

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
		$link = "/etc/nginx/sites-enabled/$domain";

		// Check if the symbolic link exists
		if (!file_exists($link)) {
			$this->info("Symbolic link $link does not exist. Site is already disabled.");
			return 0;
		}

		// Remove the symbolic link
		$process = new Process(['sudo', 'rm', $link]);
		$process->run();

		if (!$process->isSuccessful()) {
			$this->error('Failed to disable the site.');
			return 1;
		}

		$this->info('Site disabled successfully!');
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
