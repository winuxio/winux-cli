<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

class Nginx extends Service
{
	protected static ?string $serviceName = 'nginx';

	public function label(): string
	{
		return 'Nginx';
	}

	public static function availablePath(): string
	{
		return config('winux.paths.base') . DIRECTORY_SEPARATOR . config('winux.paths.sites.available');
	}

	public static function enabledPath(): string
	{
		return config('winux.paths.base') . DIRECTORY_SEPARATOR . config('winux.paths.sites.enabled');
	}

	public static function SslPath(): string
	{
		return config('winux.paths.base') . DIRECTORY_SEPARATOR . 'ssl';
	}

	public static function RootCAPath(): string
	{
		return static::SslPath() . DIRECTORY_SEPARATOR . 'root';
	}

	public static function CertPath(): string
	{
		return static::SslPath() . DIRECTORY_SEPARATOR . 'certs';
	}

	/**
	 * Ensures nginx.conf includes winux's sites-enabled directory.
	 */
	public static function patchConfig(): bool
	{
		$nginxConfig = config('winux.nginx.config');
		if (!is_file($nginxConfig)) {
			error("Nginx config not found at $nginxConfig. Is nginx installed? (winux service:install nginx)");
			return false;
		}

		$contents = file_get_contents($nginxConfig);
		$include = 'include ' . self::enabledPath() . '/*;';

		if (str_contains($contents, self::enabledPath())) {
			return true;
		}

		$anchor = 'include /etc/nginx/conf.d/*.conf;';
		if (!str_contains($contents, $anchor)) {
			error("Couldn't find '$anchor' in $nginxConfig. Add '$include' to its http block manually.");
			return false;
		}

		$contents = str_replace($anchor, "$anchor\n\t$include", $contents);

		$process = Process::input($contents)->run(['sudo', 'tee', $nginxConfig]);
		if ($process->failed()) {
			error('Failed to write to the nginx config: ' . $process->errorOutput());
			return false;
		}

		info('Added the sites-enabled directory to the nginx config.');
		return true;
	}

	/**
	 * Validates the full nginx configuration. Returns the error output on failure.
	 */
	public function test(): true|string
	{
		$process = Process::run(['sudo', 'nginx', '-t']);
		return $process->successful() ? true : $process->errorOutput();
	}

	public static function installDefaultPage(): bool
	{
		$src = storage_path('default-site') . DIRECTORY_SEPARATOR . 'www';
		$dest = '/var/www/html';

		foreach (File::files($src) as $file) {
			$destFile = $dest . DIRECTORY_SEPARATOR . $file->getFilename();
			$process = Process::input(File::get($file->getPathname()))->run(['sudo', 'tee', $destFile]);
			if ($process->failed()) {
				error('Failed to copy ' . $file->getFilename() . ' to ' . $destFile);
				return false;
			}
		}

		return true;
	}
}
