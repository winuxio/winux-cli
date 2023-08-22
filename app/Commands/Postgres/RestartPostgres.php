<?php

namespace App\Commands\Postgres;

use App\Enums\Versions\PostgresVersion;
use App\Services\Postgres;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;

class RestartPostgres extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'postgres:restart';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Restart Postgres';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
		// $version = $this->option('db_version') ?: '13';
		$version = PostgresVersion::v13;
		$this->info("Restarting Postgres {$version->value}...");
		$postgres = new Postgres(PostgresVersion::v13);	
		$postgres->restart();	
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
