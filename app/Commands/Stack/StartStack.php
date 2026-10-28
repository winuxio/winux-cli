<?php

namespace App\Commands\Stack;

use App\Enums\ServiceType;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{error, info};

class StartStack extends Command
{
	protected $signature = 'service:start
		{service? : nginx, php, postgres, redis, or mysql (default: the whole stack)}
		{version? : Only this php/postgres version, e.g. 8.4 or 16}';

	protected $description = 'Start the stack (Nginx, PHP, Postgres, Redis) or a single service';

	public function handle(): int
	{
		$service = $this->argument('service');
		if ($service !== null && !ServiceType::tryFrom($service)) {
			error("Unknown service '$service'. Choose from: " . implode(', ', ServiceType::values()));
			return self::FAILURE;
		}

		$failed = false;
		foreach (ServiceType::resolve($service, $this->argument('version')) as $instance) {
			if ($instance->start()) {
				info("{$instance->label()} started.");
			} else {
				$failed = true;
			}
		}

		return $failed ? self::FAILURE : self::SUCCESS;
	}
}
