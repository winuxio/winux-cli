<?php

namespace App\Commands\Stack;

use App\Enums\ServiceType;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\table;

class StatusStack extends Command
{
	protected $signature = 'service:status {service? : nginx, php, postgres, redis, or mysql (default: the whole stack)}';

	protected $description = 'Show whether each service is installed and running';

	public function handle(): int
	{
		$rows = collect(ServiceType::resolve($this->argument('service')))
			->map(function ($service) {
				$installed = $service->isInstalled();
				return [
					$service->label(),
					$installed ? 'yes' : 'no',
					$installed ? ($service->isActive() ? 'running' : 'stopped') : '-',
				];
			})
			->all();

		table(['Service', 'Installed', 'Status'], $rows);

		return self::SUCCESS;
	}
}
