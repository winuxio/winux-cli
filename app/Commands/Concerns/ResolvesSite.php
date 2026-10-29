<?php

namespace App\Commands\Concerns;

use App\Classes\Site;
use App\Services\Nginx;

use function Laravel\Prompts\{error, search};

trait ResolvesSite
{
	/**
	 * Resolves a site from an explicit name/domain, the current directory, or an interactive pick.
	 */
	protected function resolveSite(?string $name): ?Site
	{
		if ($name) {
			$site = Site::find($name);
			if (!$site) {
				error("No site named '$name' was found in " . Nginx::availablePath() . '.');
			}
			return $site;
		}

		if ($site = Site::findByPath(getcwd())) {
			return $site;
		}

		$domains = collect(Site::all())->map(fn (Site $site) => $site->domain);
		if ($domains->isEmpty()) {
			error('No sites exist yet.');
			return null;
		}

		if (!$this->input->isInteractive()) {
			error('Specify a site, or run this from inside a project directory.');
			return null;
		}

		$domain = search(
			label: 'Which site?',
			options: fn (string $value) => $domains
				->filter(fn ($domain) => str_contains($domain, $value))
				->values()
				->all(),
		);

		return Site::find($domain);
	}
}
