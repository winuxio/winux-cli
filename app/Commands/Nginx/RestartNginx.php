<?php

namespace App\Commands\Nginx;

use App\Services\Nginx;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{error,info};

class RestartNginx extends Command
{
    protected $signature = 'nginx:restart';
    protected $description = 'Restart Nginx';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
		$nginx = new Nginx();
        if (!$nginx->restart()) {
            error('Failed to restart Nginx.');
            return Command::FAILURE;
        }

        info('Nginx restarted successfully!');
        return Command::SUCCESS;
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
