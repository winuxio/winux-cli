<?php

namespace App\Commands\SSL;

use App\Classes\OpenSSL;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class InstallRootCA extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'ssl:root:install';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Install the root CA certificate to the system trust store';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        OpenSSL::installRoot();
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
