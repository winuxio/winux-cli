<?php

namespace App\Commands\Site;

use App\Classes\Hosts;
use App\Commands\Concerns\ResolvesSite;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{error, info, warning};

class RemoveSiteDomain extends Command
{
	use ResolvesSite;

	protected $signature = 'site:domain:remove
		{domains* : The extra domains to remove}
		{--site= : Site name or domain (defaults to the project in the current directory)}';

	protected $description = 'Stop a site answering to extra domain names';

	public function handle(): int
	{
		$site = $this->resolveSite($this->option('site'));
		if (!$site) {
			return self::FAILURE;
		}

		$requested = array_map('strtolower', $this->argument('domains'));

		if (in_array($site->domain, $requested, true)) {
			error("{$site->domain} is the site's primary domain and can't be removed.");
			return self::FAILURE;
		}

		$unknown = array_diff($requested, $site->extraDomains());
		if ($unknown) {
			warning("{$site->domain} doesn't answer to: " . implode(', ', $unknown));
		}

		$removing = array_values(array_intersect($site->extraDomains(), $requested));
		if (empty($removing)) {
			return self::SUCCESS;
		}

		if (!$site->setDomains(array_diff($site->domains(), $removing))) {
			return self::FAILURE;
		}

		info("{$site->domain} no longer answers to: " . implode(', ', $removing));

		// Only entries winux added (tagged '# winux') are removed
		$hostEntries = array_values(array_filter($removing, fn ($domain) => !Hosts::coveredByWildcard($domain) && !str_starts_with($domain, '*.')));
		Hosts::remove($hostEntries);

		return self::SUCCESS;
	}
}
