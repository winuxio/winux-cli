<?php

namespace App\Services;

use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\error;
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

	public static function RootCAPath(): string
	{
		return join(DIRECTORY_SEPARATOR, [
			config('winux.paths.base'),
			'ssl',
			'root'
		]);
	}

	public static function KeyPath(): string
	{
		return join(DIRECTORY_SEPARATOR, [
			config('winux.paths.base'),
			'ssl',
			'keys'
		]);
	}

	public static function CertPath(): string
	{
		return join(DIRECTORY_SEPARATOR, [
			config('winux.paths.base'),
			'ssl',
			'certs'
		]);
	}

	// public static function patchConfig(): bool
	// {
	// 	$nginxConfig = config('winux.nginx.config');
	// 	$nginxConfigContents = file_get_contents($nginxConfig);

	// 	// Check if the sites-enabled directory is already included in the nginx config
	// 	if (strpos($nginxConfigContents, self::enabledPath()) === false) {
	// 		// Add the sites-enabled directory to the nginx config
	// 		$nginxConfigContents = str_replace(
	// 			'include /etc/nginx/conf.d/*.conf;',
	// 			"include /etc/nginx/conf.d/*.conf;\n\tinclude " . self::enabledPath() . "/*;",
	// 			$nginxConfigContents
	// 		);

	// 		// Write the new nginx config
	// 		if (!file_put_contents($nginxConfig, $nginxConfigContents)) {
	// 			throw new \Exception('Failed to write to the nginx config.');
	// 		}
	// 		info("Added the sites-enabled directory to the nginx config.");
	// 	}

	// 	return true;
	// }

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

			// Prepare the command to write the new nginx config with sudo
			$cmd = sprintf('echo %s | sudo tee %s', escapeshellarg($nginxConfigContents), escapeshellarg($nginxConfig));

			// Run the command
			$process = Process::run($cmd);
			if ($process->failed()) {
				error('Failed to write to the nginx config: ' . $process->errorOutput());
				return false;
			}

			info("Added the sites-enabled directory to the nginx config.");
			return true;
		}

		return true;
	}

	public function reload(): bool
	{
		return $this->init('reload');
	}

	public static function installDefaultPage(): bool
	{
		$src = storage_path('default-site') . DIRECTORY_SEPARATOR . 'www';
		// Copy to tmp do we can do a sudo copy to /var/www/html
		$tmpDest = '/tmp/winux-default-www';
		$dest = '/var/www/html';

		// If the 'www' directory already exists, don't copy
		// Copy the default-site directory to the $dest
		if (!File::copyDirectory($src, $tmpDest)) {
			error('Failed to copy the default site to ' . $tmpDest);
			return false;
		}

		// Copy the default site to /var/www/html
		$src = $tmpDest . DIRECTORY_SEPARATOR . '*';
		// Loop through the files in the tmp directory and copy them to /var/www/html
		foreach (glob($src) as $file) {
			$destFile = $dest . DIRECTORY_SEPARATOR . basename($file);
			if (Process::run(['sudo', 'cp', $file, $destFile])->failed()) {
				error('Failed to copy ' . $file . ' to ' . $destFile);
				return false;
			}
		}

		File::deleteDirectory($tmpDest);
		return true;

		// Old Dead code - Need to come up with a better solution for the collision with the default site 
		// included with nginx
		// // Copy the default site config to the sites-available directory
		// $defaultSite = '00-default-winux';
		// $src = storage_path('default-site') . DIRECTORY_SEPARATOR . $defaultSite;
		// // Read in the default site config template and replace {{ WINUX_WWW_PATH }} with the $dest
		// $defaultSiteContents = str_replace('{{ WINUX_WWW_PATH }}', $dest, File::get($src));

		// $dest = self::availablePath() . DIRECTORY_SEPARATOR . $defaultSite;
		// // Write the default site config to the sites-available directory
		// if (!file_put_contents($dest, $defaultSiteContents)) {
		// 	error('Failed to write the default site config to ' . $dest);
		// 	return false;
		// }

		// // Symlink the default site config to the sites-enabled directory
		// $src = self::availablePath() . DIRECTORY_SEPARATOR . $defaultSite;
		// $dest = self::enabledPath() . DIRECTORY_SEPARATOR . $defaultSite;
		// if (!is_link($dest)) {
		// 	if (!symlink($src, $dest)) {
		// 		error('Failed to symlink the default site config to ' . $dest);
		// 		return false;
		// 	}
		// }

		// // Restart nginx since we copied this in
		// // Nginx::make()->restart();
		// Nginx::make()->reload();

		// return true;
	}
}
