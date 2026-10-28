<?php

namespace App\Commands\Nginx;

use App\Services\Nginx;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{error, info};

class RestartNginx extends Command
{
	protected $signature = 'nginx:restart';

	protected $description = 'Validate the config and restart Nginx';

	public function handle(): int
	{
		$nginx = Nginx::make();

		if (($result = $nginx->test()) !== true) {
			error("Nginx config is invalid; not restarting.\n$result");
			return self::FAILURE;
		}

		if (!$nginx->restart()) {
			return self::FAILURE;
		}

		info('Nginx restarted successfully!');
		return self::SUCCESS;
	}
}
