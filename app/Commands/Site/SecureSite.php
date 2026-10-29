<?php

namespace App\Commands\Site;

use App\Classes\Certificate;
use App\Classes\Site;
use App\Commands\Concerns\ResolvesSite;
use App\Services\Nginx;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;

class SecureSite extends Command
{
	use ResolvesSite;

	protected $signature = 'site:secure
		{site? : Site name or domain (defaults to the project in the current directory)}
		{--all : Reissue certificates for every site}';

	protected $description = "Reissue a site's certificate (covering all of its domains) with mkcert";

	public function handle(): int
	{
		$sites = $this->option('all')
			? Site::all()
			: array_filter([$this->resolveSite($this->argument('site'))]);

		if (empty($sites)) {
			return self::FAILURE;
		}

		$failed = false;
		foreach ($sites as $site) {
			if (!Certificate::create($site->domain, $site->extraDomains())) {
				$failed = true;
			}
		}

		Nginx::make()->reload();
		info('Done.');

		return $failed ? self::FAILURE : self::SUCCESS;
	}
}
