<?php

namespace App\Commands\Nginx;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;

class EnableNginxSite extends Command
{
    protected $signature = 'nginx:enable {domain}';
    protected $description = 'Enable an Nginx site by creating a symbolic link in sites-enabled';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
	public function handle()
    {
        $domain = $this->argument('domain');

        if ($this->activateSite($domain)) {
            $this->info('Site activated successfully!');
            return 0;
        } else {
            $this->error('Failed to activate the site.');
            return 1;
        }
    }

	protected function activateSite($domain)
	{
		$link = "/etc/nginx/sites-enabled/$domain";
		$destFile = "/etc/nginx/sites-available/$domain";
	
		// Check if the symbolic link exists
		if (file_exists($link)) {
			// Check if the symbolic link points to the correct file
			if (readlink($link) === $destFile) {
				$this->info('Symbolic link already exists and is configured correctly.');
				return true;
			} else {
				// Remove the existing symbolic link if it doesn't point to the correct file
				unlink($link);
			}
		}
	
		// Create the symbolic link
		$process = new Process(['sudo', 'ln', '-s', $destFile, $link]);
		$process->run();
	
		if (!$process->isSuccessful()) {
			$this->error('Failed to activate the site.');
			return false;
		}
	
		$this->info('Site activated successfully!');
		return true;
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
