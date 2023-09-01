<?php

namespace App\Commands\Stack;

use App\Enums\ServiceType;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{info, error};

class InstallStack extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'service:install {service?}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Install the stack (Nginx, PHP, Postgres/MySQL, Redis, etc.)';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $serviceName = $this->argument('service');
		$services = ServiceType::services();

		if ($serviceName !== null) {
			/** @var Service $service */
			$service = $services[$serviceName];
			if (!$service::make()->install())
				return Command::FAILURE;
			
			return Command::SUCCESS;
		} 

		foreach ($services as $serviceName => $service) {
			$service::make()->install();
		}
		info('Stack restarted successfully!');
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
