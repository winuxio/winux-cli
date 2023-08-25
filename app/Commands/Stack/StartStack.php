<?php

namespace App\Commands\Stack;

use App\Enums\ServiceType;
use App\Services\Service;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

class StartStack extends Command
{
	/**
	 * The signature of the command.
	 *
	 * @var string
	 */
	protected $signature = 'service:start {service?}';

	/**
	 * The description of the command.
	 *
	 * @var string
	 */
	protected $description = 'Start the stack (Nginx, PHP, Postgres, Redis, etc.)';

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
			$service::make()->start()
				? info("$serviceName started successfully!")
				: error("Failed to start $serviceName.");

			return Command::SUCCESS;
		}

		foreach ($services as $service) {
			$service::make()->start();
		}
		info('Stack started successfully!');
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
