<?php

namespace App\Services;

use function Laravel\Prompts\info;

class Nginx extends Service
{
	protected static ?string $serviceName = 'nginx';

	public static function availablePath(): string
	{
		// return '/etc/nginx/sites-available';
		return join(DIRECTORY_SEPARATOR, [
			config('winux.paths.home'),
			config('winux.paths.winux'),
			config('winux.paths.sites.available')
		]);
	}

	public static function enabledPath(): string
	{
		// return '/etc/nginx/sites-enabled';
		return join(DIRECTORY_SEPARATOR, [
			config('winux.paths.home'),
			config('winux.paths.winux'),
			config('winux.paths.sites.enabled')
		]);
	}

	public static function patchConfig(): bool
	{
		$nginxConfig = config('winux.nginx.config');
		$nginxConfigContents = file_get_contents($nginxConfig);

		// Check if the sites-enabled directory is already included in the nginx config
		if (strpos($nginxConfigContents, self::enabledPath()) === false) {
			// Add the sites-enabled directory to the nginx config
			$nginxConfigContents = str_replace(
				'include /etc/nginx/conf.d/*.conf;',
				"include /etc/nginx/conf.d/*.conf;\n\tinclude " . self::enabledPath() . "/*;",
				$nginxConfigContents
			);

			// Write the new nginx config
			if (!file_put_contents($nginxConfig, $nginxConfigContents)) {
				throw new \Exception('Failed to write to the nginx config.');
			}
			info("Added the sites-enabled directory to the nginx config.");
		}

		return true;
	}
}
