<?php

namespace App\Services;

use App\Classes\ServiceUser;
use App\Classes\SystemFile;
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

	/**
	 * Copies winux's placeholder page to the default web root. Files that are already up to date
	 * are left alone, so this doesn't need sudo on every command.
	 */
	public static function installDefaultPage(): bool
	{
		$src = storage_path('default-site') . DIRECTORY_SEPARATOR . 'www';
		$dest = config('winux.nginx.default_root', '/var/www/html');

		foreach (File::files($src) as $file) {
			$destFile = $dest . DIRECTORY_SEPARATOR . $file->getFilename();
			$contents = File::get($file->getPathname());
			if (is_file($destFile) && @file_get_contents($destFile) === $contents) {
				continue;
			}

			$process = Process::input($contents)->run(['sudo', 'tee', $destFile]);
			if ($process->failed()) {
				error('Failed to copy ' . $file->getFilename() . ' to ' . $destFile);
				return false;
			}
		}

		return true;
	}

	#region Run as the winux user
	protected function postInstall(): bool
	{
		$user = ServiceUser::resolve();
		return $user !== null && $this->runAs($user['name'], $user['group']);
	}

	/**
	 * Sets nginx.conf's `user` directive, validates it with `nginx -t`, and reloads nginx if it's running.
	 */
	public function runAs(string $user, string $group): bool
	{
		$path = config('winux.nginx.config');
		$current = @file_get_contents($path);
		if ($current === false) {
			error("Nginx config not found at $path. Is nginx installed? (winux service:install nginx)");
			return false;
		}

		$updated = static::withUser($current, $user, $group);
		if ($updated === $current) {
			return true;
		}

		if (!SystemFile::replace($path, $updated, fn () => $this->test())) {
			return false;
		}

		return !$this->isActive() || $this->reload();
	}

	/**
	 * nginx.conf with its main-context `user` directive set (added at the top if missing).
	 */
	public static function withUser(string $contents, string $user, string $group): string
	{
		$directive = "user $user $group;";

		if (preg_match('/^[ \t]*user[ \t]+[^;]*;/m', $contents)) {
			return preg_replace('/^([ \t]*)user[ \t]+[^;]*;/m', '${1}' . $directive, $contents, 1);
		}

		return $directive . "\n" . $contents;
	}

	/**
	 * The user nginx's workers run as according to nginx.conf, or null if it doesn't say.
	 */
	public static function configuredUser(): ?string
	{
		$contents = @file_get_contents(config('winux.nginx.config')) ?: '';
		return preg_match('/^[ \t]*user[ \t]+([^\s;]+)/m', $contents, $matches) ? $matches[1] : null;
	}
	#endregion

	/**
	 * Reloads, then waits (briefly) for the new workers, so a request made right after this
	 * returns isn't answered with the old config.
	 */
	public function reload(): bool
	{
		$before = static::workerPids();

		if (!parent::reload()) {
			return false;
		}

		if ($before) {
			$deadline = microtime(true) + 3;
			while (microtime(true) < $deadline && !array_diff(static::workerPids(), $before)) {
				usleep(100_000);
			}
		}

		return true;
	}

	/**
	 * The PIDs of the nginx master's worker processes (empty if nginx isn't running).
	 *
	 * @return int[]
	 */
	protected static function workerPids(): array
	{
		$master = (int) trim((string) @file_get_contents(config('winux.nginx.pid', '/run/nginx.pid')));
		if ($master <= 0) {
			return [];
		}

		$pids = [];
		foreach (glob('/proc/[0-9]*/stat') ?: [] as $stat) {
			// pid (comm) state ppid ...
			if (preg_match('/\)\s+\S+\s+(\d+)/', (string) @file_get_contents($stat), $matches) && (int) $matches[1] === $master) {
				$pids[] = (int) basename(dirname($stat));
			}
		}

		return $pids;
	}
}
