<?php

namespace App\Commands\Site;

use App\Classes\Hosts;
use App\Classes\Site;
use App\Commands\Concerns\ManagesHostsEntries;
use App\Commands\Concerns\ResolvesSite;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{error, info};

class AddSiteDomain extends Command
{
	use ManagesHostsEntries, ResolvesSite;

	protected $signature = 'site:domain:add
		{domains* : One or more extra domains, e.g. api.my-app.local.winux.io or my-app.test}
		{--site= : Site name or domain (defaults to the project in the current directory)}
		{--no-hosts : Never add hosts file entries}';

	protected $description = 'Make a site also answer to additional domain names';

	public function handle(): int
	{
		$site = $this->resolveSite($this->option('site'));
		if (!$site) {
			return self::FAILURE;
		}

		$requested = array_map('strtolower', $this->argument('domains'));
		foreach ($requested as $domain) {
			if (!Hosts::isValidDomain($domain)) {
				error("'$domain' isn't a valid domain name.");
				return self::FAILURE;
			}

			$owner = collect(Site::all())->first(fn (Site $other) => $other->domain !== $site->domain && in_array($domain, $other->domains(), true));
			if ($owner) {
				error("'$domain' already belongs to {$owner->domain}.");
				return self::FAILURE;
			}
		}

		$new = array_values(array_diff($requested, $site->domains()));
		if (empty($new)) {
			info("{$site->domain} already answers to " . implode(', ', $requested) . '.');
			return self::SUCCESS;
		}

		if (!$site->setDomains([...$site->domains(), ...$new])) {
			return self::FAILURE;
		}

		info("{$site->domain} now also answers to: " . implode(', ', $new));

		$this->offerHostsEntries($new, (bool) $this->option('no-hosts'));

		return self::SUCCESS;
	}
}
