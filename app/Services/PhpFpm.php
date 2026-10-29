<?php

namespace App\Services;

use App\Classes\Apt;
use App\Classes\AptRepository;
use App\Classes\ServiceUser;
use App\Classes\Settings;
use App\Classes\Sudoers;
use App\Classes\SystemFile;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\error;
use function Laravel\Prompts\warning;

class PhpFpm extends Service
{
	public string $version;

	public function __construct(?string $version = null)
	{
		$this->version = $version ?? static::defaultVersion();

		if (!static::isValidVersion($this->version)) {
			throw new \InvalidArgumentException("Invalid PHP version '{$this->version}'. Expected something like 8.4.");
		}
	}

	public static function make(?string $version = null): static
	{
		return new static($version);
	}

	public static function instances(): array
	{
		$versions = static::installedVersions() ?: [static::defaultVersion()];
		return array_map(fn ($version) => static::make($version), $versions);
	}

	protected function serviceName(): string
	{
		return "php{$this->version}-fpm";
	}

	public function label(): string
	{
		return "PHP {$this->version} FPM";
	}

	public function socket(): string
	{
		return "/run/php/php{$this->version}-fpm.sock";
	}

	protected function packages(): array
	{
		return [
			"php{$this->version}-fpm",
			...array_map(fn ($ext) => "php{$this->version}-{$ext}", config('winux.php.extensions', [])),
		];
	}

	public function isInstalled(): bool
	{
		return in_array($this->version, static::installedVersions(), true);
	}

	protected function preInstall(): bool
	{
		return AptRepository::ensurePhp();
	}

	#region Run as the winux user
	protected function postInstall(): bool
	{
		$user = ServiceUser::resolve();
		if ($user === null || !$this->runAs($user['name'], $user['group'])) {
			return false;
		}

		// The passwordless rules list each PHP version's commands, so they gain this one
		if (Sudoers::isInstalled() && !Sudoers::install()) {
			warning("PHP {$this->version} FPM reloads will ask for your password until you run `winux sudoers`.");
		}

		return true;
	}

	public function poolFile(): string
	{
		return config('winux.php.root') . "/{$this->version}/fpm/pool.d/www.conf";
	}

	/**
	 * Runs the www pool as the given user, with its socket owned by them so nginx (running as
	 * the same user) can connect. Only those four directives change. Restarts FPM if it's running.
	 */
	public function runAs(string $user, string $group): bool
	{
		$path = $this->poolFile();
		$current = @file_get_contents($path);
		if ($current === false) {
			error("PHP-FPM pool config not found at $path. Is PHP {$this->version} FPM installed?");
			return false;
		}

		$updated = static::withPoolUser($current, $user, $group);
		if ($updated === $current) {
			return true;
		}

		if (!SystemFile::replace($path, $updated, fn () => $this->test())) {
			return false;
		}

		return !$this->isActive() || $this->restart();
	}

	/**
	 * A pool config with user, group, listen.owner and listen.group set. Commented-out directives are
	 * uncommented in place; missing ones are added at the end of the pool.
	 */
	public static function withPoolUser(string $contents, string $user, string $group): string
	{
		$values = ['user' => $user, 'group' => $group, 'listen.owner' => $user, 'listen.group' => $group];

		foreach ($values as $key => $value) {
			$pattern = '/^[ \t]*;?[ \t]*' . preg_quote($key, '/') . '[ \t]*=.*$/m';
			$line = "$key = $value";

			// An active directive wins over a commented-out one
			$active = '/^[ \t]*' . preg_quote($key, '/') . '[ \t]*=.*$/m';
			if (preg_match($active, $contents)) {
				$contents = preg_replace($active, $line, $contents, 1);
			} elseif (preg_match($pattern, $contents)) {
				$contents = preg_replace($pattern, $line, $contents, 1);
			} else {
				$contents = rtrim($contents, "\n") . "\n" . $line . "\n";
			}
		}

		return $contents;
	}

	/**
	 * The pool's user and socket owner as configured (null when unset).
	 *
	 * @return array{user: ?string, listen.owner: ?string}
	 */
	public function poolUser(): array
	{
		$contents = @file_get_contents($this->poolFile()) ?: '';
		$value = fn (string $key) => preg_match('/^[ \t]*' . preg_quote($key, '/') . '[ \t]*=[ \t]*(\S+)/m', $contents, $m) ? $m[1] : null;

		return ['user' => $value('user'), 'listen.owner' => $value('listen.owner')];
	}

	/**
	 * Validates the FPM configuration. Returns the error output on failure.
	 */
	public function test(): true|string
	{
		$process = Process::run(['sudo', "php-fpm{$this->version}", '-t']);
		return $process->successful() ? true : trim($process->errorOutput() . "\n" . $process->output());
	}
	#endregion

	public static function isValidVersion(string $version): bool
	{
		return (bool) preg_match('/^\d+\.\d+$/', $version);
	}

	/**
	 * The user's chosen default, falling back to the newest installed version.
	 */
	public static function defaultVersion(): string
	{
		$installed = static::installedVersions();
		$preferred = Settings::phpVersion();

		if (empty($installed) || in_array($preferred, $installed, true)) {
			return $preferred;
		}

		return end($installed);
	}

	/**
	 * PHP versions with FPM installed, sorted ascending.
	 *
	 * @return string[]
	 */
	public static function installedVersions(): array
	{
		$versions = collect(glob(config('winux.php.root') . '/*/fpm/php-fpm.conf') ?: [])
			->map(fn ($path) => basename(dirname($path, 2)))
			->filter(fn ($version) => static::isValidVersion($version))
			->all();

		return static::sortVersions($versions);
	}

	/**
	 * PHP versions offered by the configured apt repositories, sorted ascending.
	 *
	 * @return string[]
	 */
	public static function availableVersions(): array
	{
		$versions = collect(Apt::search('^php[0-9]+\.[0-9]+-fpm$'))
			->map(fn ($package) => preg_replace('/^php([0-9]+\.[0-9]+)-fpm$/', '$1', $package))
			->filter(fn ($version) => version_compare($version, config('winux.php.minimum'), '>='))
			->all();

		return static::sortVersions($versions);
	}

	public static function sortVersions(array $versions): array
	{
		$versions = array_values(array_unique($versions));
		usort($versions, 'version_compare');
		return $versions;
	}
}
