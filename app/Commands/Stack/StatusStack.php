<?php

namespace App\Commands\Stack;

use App\Enums\ServiceType;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{error, table};

class StatusStack extends Command
{
	protected $signature = 'service:status {service? : nginx, php, postgres, redis, or mysql (default: the whole stack)}';

	protected $description = 'Show whether each service is installed and running';

	public function handle(): int
	{
		$service = $this->argument('service');
		if ($service !== null && !ServiceType::tryFrom($service)) {
			error("Unknown service '$service'. Choose from: " . implode(', ', ServiceType::values()));
			return self::FAILURE;
		}

		$rows = collect(ServiceType::resolve($service))
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
