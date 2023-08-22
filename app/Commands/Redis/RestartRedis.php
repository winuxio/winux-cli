<?php

namespace App\Commands\Redis;

use App\Services\Redis;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

class RestartRedis extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'redis:restart';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Restart Redis';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $redis = new Redis();
		if (!$redis->restart()) {
			error('Failed to restart Redis.');
			return Command::FAILURE;
		}

		info('Redis restarted successfully!');
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
