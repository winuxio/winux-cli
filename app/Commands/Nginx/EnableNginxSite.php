<?php

namespace App\Commands\Nginx;

use App\Classes\Site;
use App\Services\Nginx;
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
		
        if (Site::make($domain)->enable()) {
            $this->info('Site activated successfully!');
            return 0;
        } else {
            $this->error('Failed to activate the site.');
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
