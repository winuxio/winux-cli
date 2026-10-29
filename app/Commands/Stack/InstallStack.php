<?php

namespace App\Commands\Stack;

use App\Classes\Winux;
use App\Enums\ServiceType;
use App\Services\PhpFpm;
use App\Services\Postgres;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{error, info};

class InstallStack extends Command
{
	protected $signature = 'service:install
		{service? : nginx, php, postgres, redis, or mysql (default: the whole stack)}
		{version? : Version for php or postgres, e.g. 8.4 or 16}';

	protected $description = 'Install the stack (Nginx, PHP, Postgres, Redis) or a single service';

	public function handle(): int
	{
		$service = $this->argument('service');
		if ($service !== null && !ServiceType::tryFrom($service)) {
			error("Unknown service '$service'. Choose from: " . implode(', ', ServiceType::values()));
			return self::FAILURE;
		}

		// Installing the whole stack installs the default versions, not every installed one
		$services = $service
			? ServiceType::resolve($service, $this->argument('version') ?? $this->defaultVersionFor($service))
			: [...ServiceType::resolve('nginx'), PhpFpm::make(), Postgres::make(), ...ServiceType::resolve('redis')];

		$failed = false;
		foreach ($services as $instance) {
			if (!$instance->install()) {
				$failed = true;
			}
		}

		Postgres::flushClusterCache();

		if ($service === null || $service === 'nginx') {
			Winux::SetupEnvironment();
		}

		if ($failed) {
			error('Some services failed to install.');
			return self::FAILURE;
		}

		info('Installed: ' . collect($services)->map->label()->implode(', '));
		return self::SUCCESS;
	}

	protected function defaultVersionFor(string $service): ?string
	{
		return match ($service) {
			'php' => PhpFpm::defaultVersion(),
			'postgres' => Postgres::defaultVersion(),
			default => null,
		};
	}
}
