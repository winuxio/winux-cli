<?php

namespace App\Commands\Nginx;

use App\Commands\Concerns\ResolvesSite;
use App\Services\Nginx;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;

class DisableNginxSite extends Command
{
	use ResolvesSite;

	protected $signature = 'nginx:disable {domain? : Site name or domain (defaults to the project in the current directory)}';

	protected $description = 'Disable an nginx site';

	public function handle(): int
	{
		$site = $this->resolveSite($this->argument('domain'));
		if (!$site || !$site->disable()) {
			return self::FAILURE;
		}

		if (!Nginx::make()->reload()) {
			return self::FAILURE;
		}

		info("Disabled {$site->domain}.");
		return self::SUCCESS;
	}
}
