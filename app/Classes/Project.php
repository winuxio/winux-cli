<?php

namespace App\Classes;

use App\Enums\Editors;
use App\Enums\Frameworks;
use App\Enums\GitHubVisibility;
use App\Enums\Packages;
use App\Services\Nginx;
use App\Services\PhpFpm;
use App\Services\Postgres;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\alert;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

class Project
{
	public string $name;
	public string $path;
	public Frameworks $framework = Frameworks::Laravel;
	public Site $site;

	public string $phpVersion;
	public string $postgresVersion;
	/** @var string[] */
	public array $extraDomains = [];

	protected string $dbName;
	protected string $dbUser;
	protected string $dbPassword;

	/** Import found working credentials in .env, so its DB_* values are left alone */
	protected bool $keepEnvDatabase = false;

	protected ?bool $envDatabaseWorks = null;

	protected bool $hasRepo = false;

	public function getDomain(): string
	{
		return $this->name . '.' . config('winux.nginx.domain');
	}

	/**
	 * Create a new project instance in the current directory
	 */
	public static function create(string $name, ?string $phpVersion = null, ?string $postgresVersion = null): Project
	{
		$project = new Project();
		$project->name = preg_replace('/[^a-zA-Z0-9-_\.]/', '', $name);
		$project->path = getcwd() . DIRECTORY_SEPARATOR . $name;
		$project->phpVersion = $phpVersion ?? PhpFpm::defaultVersion();
		$project->postgresVersion = $postgresVersion ?? $project->detectPostgresVersion() ?? Postgres::defaultVersion();

		$project->site = Site::create($project->getDomain(), $project->path);

		// An existing site keeps its PHP version unless one was asked for
		if ($phpVersion === null && $project->site->exists() && ($sitePhp = $project->site->phpVersion())) {
			$project->phpVersion = $sitePhp;
		}
		$project->dbName = static::databaseName($project->name);
		$project->dbUser = $project->dbName;

		return $project;
	}

	/**
	 * Hyphens become underscores; anything but lowercase letters, digits, and underscores is dropped.
	 */
	public static function databaseName(string $name): string
	{
		return preg_replace('/[^a-z0-9_]/', '', str_replace('-', '_', strtolower($name)));
	}

	/**
	 * For an existing project, the Postgres version whose port matches DB_PORT in its .env.
	 */
	protected function detectPostgresVersion(): ?string
	{
		$port = $this->envValue('DB_PORT');
		if ($port === null) {
			return null;
		}

		foreach (Postgres::clusters() as $version => $cluster) {
			if ($cluster['port'] === (int) $port) {
				return (string) $version;
			}
		}

		return null;
	}

	public function postgres(): Postgres
	{
		return Postgres::make($this->postgresVersion);
	}

	#region Meta-Methods
	public function setupNginx(): bool
	{
		if ($this->site->exists()) {
			return $this->updateExistingSite();
		}

		return Certificate::create($this->getDomain(), $this->extraDomains)
			&& $this->site->generateAndSave($this->phpVersion, $this->extraDomains)
			&& $this->site->enable();
	}

	/**
	 * Re-importing a site that already has an nginx config: keep it, and only apply what was asked for.
	 */
	protected function updateExistingSite(): bool
	{
		info("{$this->getDomain()} already has an nginx config; keeping it.");

		$current = $this->site->phpVersion();
		if ($current && $current !== $this->phpVersion) {
			info("Switching it from PHP $current to {$this->phpVersion}.");
			if (!$this->site->setPhpVersion($this->phpVersion)) {
				return false;
			}
		}

		$newDomains = array_diff($this->extraDomains, $this->site->extraDomains());
		if ($newDomains) {
			if (!$this->site->setDomains([...$this->site->domains(), ...$newDomains])) {
				return false;
			}
		} elseif (!is_file(Certificate::certFile($this->getDomain())) && !Certificate::create($this->getDomain(), $this->site->extraDomains())) {
			return false;
		}

		return $this->site->enable();
	}

	public function setupDatabase(): bool
	{
		$this->dbPassword = Postgres::generatePassword();
		return $this->postgres()->createAll($this->dbName, $this->dbUser, $this->dbPassword);
	}

	/**
	 * The Postgres connection an existing project's .env points at, if any.
	 *
	 * @return array{host: string, port: int, database: string, username: string, password: string}|null
	 */
	public function envDatabase(): ?array
	{
		if ($this->envValue('DB_CONNECTION') !== 'pgsql' || !($database = $this->envValue('DB_DATABASE'))) {
			return null;
		}

		return [
			'host' => $this->envValue('DB_HOST') ?: '127.0.0.1',
			'port' => (int) ($this->envValue('DB_PORT') ?: 5432),
			'database' => $database,
			'username' => $this->envValue('DB_USERNAME') ?: $database,
			'password' => $this->envValue('DB_PASSWORD') ?? '',
		];
	}

	/**
	 * Whether the credentials already in .env work, in which case import leaves the database alone.
	 */
	public function envDatabaseWorks(): bool
	{
		if ($this->envDatabaseWorks !== null) {
			return $this->envDatabaseWorks;
		}

		$env = $this->envDatabase();

		return $this->envDatabaseWorks = $env !== null
			&& Postgres::canConnect($env['host'], $env['port'], $env['database'], $env['username'], $env['password']);
	}

	/**
	 * Import's database setup: reuse what .env points at when possible, and only create or change what's missing.
	 *  - .env credentials work                  -> use them as-is
	 *  - .env's database exists on this cluster -> keep its name/user; create the user or reset its password
	 *  - otherwise                              -> create a database and user named after the project
	 */
	public function setupImportedDatabase(): bool
	{
		$env = $this->envDatabase();

		if ($env && $this->envDatabaseWorks()) {
			info("Reusing the existing database '{$env['database']}' as '{$env['username']}' (the credentials in .env work).");
			$this->keepEnvDatabase = true;
			return true;
		}

		$postgres = $this->postgres();

		if ($env && $postgres->databaseExists($env['database'])) {
			$this->dbName = $env['database'];
			$this->dbUser = $env['username'];
			info("Using the existing database '{$this->dbName}' on {$postgres->label()}.");
		}

		$userExists = $postgres->userExists($this->dbUser);
		if ($userExists) {
			warning("The Postgres user '{$this->dbUser}' exists, but the credentials in .env don't work for it.");
			if (!confirm("Reset '{$this->dbUser}''s password and save it to .env?", true, hint: 'Anything else using this user will need the new password.')) {
				warning('Leaving the database and the DB_* values in .env untouched.');
				$this->keepEnvDatabase = true;
				return true;
			}
		}

		if (!$postgres->databaseExists($this->dbName)) {
			info("Creating the database '{$this->dbName}' on {$postgres->label()}.");
		}
		if (!$userExists) {
			info("Creating the user '{$this->dbUser}'.");
		}

		return $this->setupDatabase();
	}

	public function initEnv(): bool
	{
		return $this->updateEnv($this->envValues());
	}

	/**
	 * Import: point APP_URL and the database at winux, but don't clobber an existing app's other settings.
	 */
	public function configureEnv(): bool
	{
		$values = $this->envValues();
		$overwrite = array_intersect_key($values, array_flip(['APP_URL', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD']));
		$fillMissing = array_diff_key($values, $overwrite);

		return $this->updateEnv($overwrite) && $this->updateEnv($fillMissing, overwrite: false);
	}

	protected function envValues(): array
	{
		$values = [
			'APP_NAME' => "\"$this->name\"",
			'APP_URL' => '"https://' . $this->getDomain() . '"',
			'CACHE_STORE' => 'redis',
			'SESSION_DRIVER' => 'redis',
			'QUEUE_CONNECTION' => 'redis',
		];

		if ($this->keepEnvDatabase) {
			return $values;
		}

		return $values + [
			'DB_CONNECTION' => 'pgsql',
			'DB_HOST' => '127.0.0.1',
			'DB_PORT' => $this->postgres()->getPort(),
			'DB_DATABASE' => "\"{$this->dbName}\"",
			'DB_USERNAME' => "\"{$this->dbUser}\"",
			'DB_PASSWORD' => "\"{$this->dbPassword}\"",
		];
	}

	protected function envValue(string $key): ?string
	{
		$envFile = $this->path . DIRECTORY_SEPARATOR . '.env';
		if (!is_file($envFile)) {
			return null;
		}

		return preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', File::get($envFile), $matches)
			? trim($matches[1], " \t\"'")
			: null;
	}

	private function updateEnv(array $newValues, bool $overwrite = true): bool
	{
		$envFile = $this->path . DIRECTORY_SEPARATOR . '.env';

		if (!File::exists($envFile)) {
			if (!File::copy($this->path . DIRECTORY_SEPARATOR . '.env.example', $envFile)) {
				error('Failed to copy the .env.example file to .env');
				return false;
			}
		}

		$contents = static::applyEnvValues(File::get($envFile), $newValues, $overwrite);

		return File::put($envFile, $contents) !== false;
	}

	/**
	 * Replaces existing keys (uncommenting them if needed, e.g. Laravel 11+'s "# DB_HOST=")
	 * and appends any that are missing. With $overwrite off, keys that are already set are left alone.
	 */
	public static function applyEnvValues(string $contents, array $values, bool $overwrite = true): string
	{
		foreach ($values as $key => $value) {
			$line = $key . '=' . $value;
			$quoted = preg_quote($key, '/');

			if (preg_match('/^' . $quoted . '=/m', $contents)) {
				if (!$overwrite) {
					continue;
				}
				$contents = preg_replace('/^' . $quoted . '=.*$/m', addcslashes($line, '\\$'), $contents);
			} elseif (preg_match('/^#\s*' . $quoted . '=/m', $contents)) {
				$contents = preg_replace('/^#\s*' . $quoted . '=.*$/m', addcslashes($line, '\\$'), $contents, 1);
			} else {
				$contents = rtrim($contents, "\n") . "\n" . $line . "\n";
			}
		}

		return $contents;
	}

	public function initializeGitRepository(): bool
	{
		if (Process::path($this->path)->run(['git', 'init', '-b', 'main'])->failed()) {
			error('Failed to initialize the git repository.');
			return false;
		}

		$this->hasRepo = true;

		return $this->gitCommit('Initial commit');
	}

	public function gitCommit(string $message): bool
	{
		if (Process::path($this->path)->run(['git', 'add', '.'])->failed()) {
			error('Failed to add the files to the git repository.');
			return false;
		}

		$commit = Process::path($this->path)->run(['git', 'commit', '-m', $message]);
		if ($commit->failed()) {
			error('Failed to commit the files to the git repository.');
			warning($commit->output());
			error($commit->errorOutput());
			return false;
		}

		return true;
	}

	public function installAdditionalPackages(bool $commit = true): void
	{
		$packages = collect(Packages::cases())
			->mapWithKeys(fn ($package) => [$package->value => $package->label()]);

		$extraPackages = multiselect(
			'Install any additional packages? (More coming!)',
			$packages,
			hint: 'Press space to select, enter to continue',
		);

		collect($extraPackages)->each(function ($packageValue) use ($commit) {
			$package = Packages::from($packageValue);
			$package->install($this->path, $this->phpBinary());
			if ($commit && $this->hasRepo) {
				$this->gitCommit("Installed {$package->label()}");
			}
		});
	}

	public function createGitHubRepo(GitHubVisibility $visibility = GitHubVisibility::Private): bool
	{
		$createRepoCommand = [
			'gh', 'repo', 'create', $this->name,
			"--{$visibility->value}",
			'--source=.',
			'--remote=origin',
			'--push',
		];

		$process = Process::path($this->path)->timeout(300)->run($createRepoCommand);
		if ($process->failed()) {
			alert('Failed to create the GitHub repository.');
			error($process->errorOutput());
			return false;
		}

		return true;
	}

	public function destroy(): bool
	{
		$this->site->disable();
		$this->site->delete();
		Nginx::make()->reload();

		Certificate::destroy($this->getDomain());

		if (!$this->postgres()->dropAll($this->dbName)) {
			alert('Failed to drop some (or all) database resources.');
		} else {
			info("Dropped all database resources.\n");
		}

		alert("Answering 'yes' will delete ALL of this project's files. THIS CAN NOT BE UNDONE!");
		if (confirm("Do you want to delete the project directory at '{$this->path}'?", false)) {
			if (!File::deleteDirectory($this->path)) {
				error('Failed to delete the project directory.');
				return false;
			}

			info('Deleted the project directory.');
		}

		return true;
	}
	#endregion

	#region Openers
	public function openEditor(Editors $editor = Editors::VSCODE): bool
	{
		$process = Process::run([$editor->getCommand(), $this->path]);
		if ($process->failed()) {
			error("Failed to open the project in {$editor->getLabel()}.");
			return false;
		}
		return true;
	}

	public function openBrowser(): bool
	{
		note('Attempting to open the project in the browser...');
		$result = Utility::openBrowser('https://' . $this->getDomain());
		if ($result !== true) {
			error('Failed to open the project in the browser.');
			warning($result);
			return false;
		}

		return true;
	}
	#endregion

	#region Installers
	public function installFramework(): bool
	{
		return match ($this->framework) {
			Frameworks::Laravel => $this->installLaravel(),
		};
	}

	/**
	 * The PHP CLI matching the site's FPM version, so composer resolves dependencies for it.
	 */
	public function phpBinary(): string
	{
		$versioned = "/usr/bin/php{$this->phpVersion}";
		return is_executable($versioned) ? $versioned : 'php';
	}

	private function installLaravel(): bool
	{
		$composer = trim(Process::run(['sh', '-c', 'command -v composer'])->output());
		if ($composer === '') {
			error('Composer was not found on your PATH. See https://getcomposer.org/download/');
			return false;
		}

		$process = Process::forever()->run(
			[$this->phpBinary(), $composer, 'create-project', 'laravel/laravel', $this->name],
			function ($type, $buffer) {
				echo $buffer;
			}
		);

		if ($process->failed()) {
			error('Failed to create the laravel project.');
			return false;
		}

		return true;
	}
	#endregion
}
