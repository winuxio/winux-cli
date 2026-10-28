<?php

namespace App\Services;

use App\Classes\Apt;
use App\Classes\AptRepository;
use App\Classes\Settings;

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
