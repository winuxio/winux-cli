<?php

namespace App\Commands\Nginx;

use App\Commands\Concerns\ResolvesSite;
use App\Services\Nginx;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;

class EnableNginxSite extends Command
{
	use ResolvesSite;

	protected $signature = 'nginx:enable {domain? : Site name or domain (defaults to the project in the current directory)}';

	protected $description = 'Enable an nginx site';

	public function handle(): int
	{
		$site = $this->resolveSite($this->argument('domain'));
		if (!$site || !$site->enable()) {
			return self::FAILURE;
		}

		if (!Nginx::make()->reload()) {
			return self::FAILURE;
		}

		info("Enabled {$site->domain}.");
		return self::SUCCESS;
	}
}
