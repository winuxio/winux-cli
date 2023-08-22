<?php

namespace App\Commands\Php;

use App\Services\PhpFpm;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class RestartPhpFpm extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'php:restart';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Restart PHP-FPM';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $php = new PhpFpm();
		if (!$php->restart()) {
			$this->error('Failed to restart PHP-FPM.');
			return Command::FAILURE;
		}

		$this->info('PHP-FPM restarted successfully!');
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
