<?php

namespace App\Commands\Nginx;

use App\Classes\Certificate;
use App\Classes\Hosts;
use App\Commands\Concerns\ResolvesSite;
use App\Services\Nginx;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{confirm, error, info};

class DeleteNginxSite extends Command
{
	use ResolvesSite;

	protected $signature = 'nginx:delete
		{domain? : Site name or domain (defaults to the project in the current directory)}
		{--force : Skip the confirmation}';

	protected $description = "Delete an nginx site and its certificate (the project's files and database are kept)";

	public function handle(): int
	{
		$site = $this->resolveSite($this->argument('domain'));
		if (!$site) {
			return self::FAILURE;
		}

		if (!$this->option('force') && !confirm("Delete the nginx site {$site->domain}?", false)) {
			return self::FAILURE;
		}

		$extraDomains = $site->extraDomains();

		if (!$site->disable() || !$site->delete()) {
			error('Failed to delete the site.');
			return self::FAILURE;
		}

		Certificate::destroy($site->domain);
		Hosts::remove(array_filter($extraDomains, fn ($domain) => !Hosts::coveredByWildcard($domain) && !str_starts_with($domain, '*.')));
		Nginx::make()->reload();

		info('Site deleted successfully!');
		return self::SUCCESS;
	}
}
