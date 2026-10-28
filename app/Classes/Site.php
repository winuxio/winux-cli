<?php

namespace App\Classes;

use App\Services\Nginx;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\{alert, error, info, warning};

/**
 * A site is an nginx config in ~/.winux/sites/available named after its primary domain.
 * The config file itself is the source of truth for its domains, root, and PHP version.
 */
class Site
{
	public string $configFile = '';
	public string $linkFile = '';

	public function __construct(public string $domain, public ?string $path = null)
	{
		$this->configFile = Nginx::availablePath() . DIRECTORY_SEPARATOR . $domain;
		$this->linkFile = Nginx::enabledPath() . DIRECTORY_SEPARATOR . $domain;
	}

	public static function create(string $domain, string $path): static
	{
		return new static($domain, $path);
	}

	public static function make(string $domain): static
	{
		return new static($domain);
	}

	#region Lookup
	/**
	 * Finds a site by its full domain or its short name (e.g. 'my-app' for my-app.local.winux.io).
	 */
	public static function find(string $name): ?static
	{
		foreach ([$name, $name . '.' . config('winux.nginx.domain')] as $domain) {
			$site = new static($domain);
			if ($site->exists()) {
				return $site;
			}
		}

		return null;
	}

	/**
	 * Finds the site whose project directory contains the given path.
	 */
	public static function findByPath(string $path): ?static
	{
		$path = rtrim(realpath($path) ?: $path, '/');

		return collect(static::all())
			->filter(fn (Site $site) => ($root = $site->projectPath()) && ($path === $root || str_starts_with($path . '/', $root . '/')))
			->sortByDesc(fn (Site $site) => strlen($site->projectPath()))
			->first();
	}

	/** @return static[] */
	public static function all(): array
	{
		return collect(File::isDirectory(Nginx::availablePath()) ? File::files(Nginx::availablePath()) : [])
			->map(fn ($file) => $file->getFilename())
			// Skip backups and other stray files
			->reject(fn ($name) => str_ends_with($name, '.bak') || str_starts_with($name, '.'))
			->sort()
			->map(fn ($name) => new static($name))
			->values()
			->all();
	}
	#endregion

	#region State
	public function exists(): bool
	{
		return is_file($this->configFile);
	}

	public function isEnabled(): bool
	{
		return is_link($this->linkFile) || is_file($this->linkFile);
	}

	public function config(): NginxConfig
	{
		return new NginxConfig(File::get($this->configFile));
	}

	/** Every domain the site answers to, primary first. */
	public function domains(): array
	{
		$domains = $this->config()->domains();
		return array_values(array_unique([$this->domain, ...$domains]));
	}

	public function extraDomains(): array
	{
		return array_values(array_diff($this->domains(), [$this->domain]));
	}

	public function phpVersion(): ?string
	{
		return $this->config()->phpVersion();
	}

	/** The project directory (the web root without its trailing /public). */
	public function projectPath(): ?string
	{
		if ($this->path) {
			return $this->path;
		}

		if (!$this->exists() || !($root = $this->config()->root())) {
			return null;
		}

		return preg_replace('#/public/?$#', '', rtrim($root, '/'));
	}
	#endregion

	#region Changes
	/**
	 * Points the site at a different php-fpm version.
	 */
	public function setPhpVersion(string $version): bool
	{
		return $this->apply($this->config()->withPhpVersion($version));
	}

	/**
	 * Replaces the site's domains (the primary domain is always kept) and reissues its certificate.
	 */
	public function setDomains(array $domains): bool
	{
		$domains = array_values(array_unique([$this->domain, ...$domains]));

		if (!Certificate::create($this->domain, array_slice($domains, 1))) {
			return false;
		}

		return $this->apply($this->config()->withDomains($domains));
	}

	/**
	 * Writes a new config, validates it with `nginx -t`, and reloads nginx.
	 * The previous config is restored if validation fails.
	 */
	public function apply(NginxConfig $config): bool
	{
		$previous = File::get($this->configFile);
		if ($previous === $config->contents) {
			return true;
		}

		$backup = $this->backup($previous);
		File::put($this->configFile, $config->contents);

		$nginx = Nginx::make();
		if ($this->isEnabled() && ($result = $nginx->test()) !== true) {
			File::put($this->configFile, $previous);
			alert('The updated nginx config failed validation; the previous config was restored.');
			error($result);
			return false;
		}

		info("Updated {$this->configFile} (backup: $backup)");
		return !$this->isEnabled() || $nginx->reload();
	}

	protected function backup(string $contents): string
	{
		$dir = config('winux.paths.base') . DIRECTORY_SEPARATOR . 'backups';
		File::ensureDirectoryExists($dir);

		$path = $dir . DIRECTORY_SEPARATOR . $this->domain . '.' . date('Ymd-His') . '.conf';
		File::put($path, $contents);
		return $path;
	}
	#endregion

	#region Lifecycle
	public function enable(): bool
	{
		File::ensureDirectoryExists(dirname($this->linkFile), 0755);

		if (is_link($this->linkFile) || file_exists($this->linkFile)) {
			if (is_link($this->linkFile) && readlink($this->linkFile) === $this->configFile) {
				info('Site is already enabled.');
				return true;
			}
			unlink($this->linkFile);
		}

		if (!symlink($this->configFile, $this->linkFile)) {
			error('Failed to enable the site.');
			return false;
		}

		info('Site enabled successfully!');
		return true;
	}

	public function disable(): bool
	{
		if (!is_link($this->linkFile) && !file_exists($this->linkFile)) {
			info("{$this->domain} is already disabled.");
			return true;
		}

		if (!unlink($this->linkFile)) {
			error("Failed to disable the Nginx config for {$this->domain}.");
			return false;
		}

		info("Nginx config for {$this->domain} disabled successfully!");
		return true;
	}

	public function delete(): bool
	{
		if (!$this->exists()) {
			info("Config file {$this->configFile} does not exist. Site is already deleted.");
			return true;
		}

		if (!unlink($this->configFile)) {
			alert('Failed to delete Nginx config for ' . $this->domain);
			return false;
		}

		return true;
	}

	public function generateAndSave(?string $phpVersion = null, array $extraDomains = []): bool
	{
		if ($this->exists()) {
			warning("{$this->configFile} already exists; leaving it untouched.");
			return true;
		}

		return $this->save($this->generate($phpVersion, $extraDomains));
	}

	public function generate(?string $phpVersion = null, array $extraDomains = []): string
	{
		$serverNames = implode(' ', array_unique([$this->domain, ...$extraDomains]));

		return str_replace(
			['{{SERVER_NAMES}}', '{{PROJECT_DOMAIN}}', '{{PROJECT_PATH}}', '{{SSL_CERT_PATH}}', '{{PHP_VERSION}}'],
			[$serverNames, $this->domain, $this->path, Nginx::CertPath(), $phpVersion ?? Settings::phpVersion()],
			File::get(storage_path('templates/site-template.conf'))
		);
	}

	public function save(string $config): bool
	{
		File::ensureDirectoryExists(dirname($this->configFile), 0755);

		if (File::put($this->configFile, $config) === false) {
			error('Failed to save the Nginx config.');
			return false;
		}

		info('Saved configuration to ' . $this->configFile);
		return true;
	}
	#endregion
}
