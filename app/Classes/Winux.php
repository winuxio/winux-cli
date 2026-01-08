<?php

namespace App\Classes;

use App\Services\Nginx;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class Winux
{
	public static function SetupEnvironment(): bool
	{
		$winuxDir = config('winux.paths.winux');
		$winuxConfigDir = join(DIRECTORY_SEPARATOR, [config('winux.paths.home'), $winuxDir]);

		// Create the .winux directory in the user's home dir
		if (!is_dir($winuxConfigDir)) {
			if (!mkdir($winuxConfigDir, 0755, true)) {
				throw new \Exception('Failed to create the .winux directory.');
			}
			info("Created the $winuxDir directory.");
		}

		$sitesAvailable = join(DIRECTORY_SEPARATOR, [$winuxConfigDir, config('winux.paths.sites.available')]);
		$sitesEnabled = join(DIRECTORY_SEPARATOR, [$winuxConfigDir, config('winux.paths.sites.enabled')]);
		
		// Create the sites directory
		// dump($sitesAvailable, $sitesEnabled);
		if (!is_dir($sitesAvailable)) {
			if (!mkdir($sitesAvailable, 0777, true)) {
				throw new \Exception('Failed to create the sites-available directory.');
			}
			info("Created the sites-available directory.");
		}

		if (!is_dir($sitesEnabled)) {
			if (!mkdir($sitesEnabled, 0777, true)) {
				throw new \Exception('Failed to create the sites-enabled directory.');
			}
			info("Created the sites-enabled directory.");
		}

		// Ensure our sites-enabled directory is included in the nginx config
		Nginx::patchConfig();

		Nginx::installDefaultPage();

		return true;
	}
}
