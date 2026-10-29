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
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

class Project
{
	/** The project directory's name, exactly as given (e.g. My_App) */
	public string $name;
	/** The lowercase, DNS-safe form used for the domain and database (e.g. my-app) */
	public string $slug;
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

	/** A new project adopting the database and user an earlier, deleted project left behind */
	protected bool $reuseDatabase = false;

	protected ?bool $envDatabaseWorks = null;

	protected bool $hasRepo = false;

	protected ?string $domain = null;

	public function getDomain(): string
	{
		return $this->domain ?? $this->slug . '.' . config('winux.nginx.domain');
	}

	/**
	 * Why a project name (a directory in the current directory) can't be used, or null if it can.
	 * Letters, digits, hyphens and underscores, starting with a letter or digit. The lowercase form,
	 * with underscores as hyphens, must be a valid DNS label since it becomes the site's domain.
	 */
	public static function nameError(string $name): ?string
	{
		$rule = 'Use letters, digits, hyphens and underscores, starting and ending with a letter or digit (e.g. my-app).';

		if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $name)) {
			return "'$name' isn't a valid project name. $rule";
		}

		if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', static::slug($name))) {
			return "'$name' isn't a valid project name: '" . static::slug($name) . "' must be a valid domain label (at most 63 characters). $rule";
		}

		return null;
	}

	/**
	 * Why a project can't be set up in $directory (default: the current one): a bad name or a path nginx can't serve, or null.
	 */
	public static function validationError(string $name, ?string $directory = null): ?string
	{
		return static::nameError($name) ?? Site::unsafePathReason(($directory ?? getcwd()) . DIRECTORY_SEPARATOR . $name);
	}

	/**
	 * My_App -> my-app: the site name (and, with hyphens as underscores, the database name).
	 */
	public static function slug(string $name): string
	{
		return str_replace('_', '-', strtolower($name));
	}

	/**
	 * Create a new project instance for the directory $name in $directory (default: the current one)
	 */
	public static function create(string $name, ?string $phpVersion = null, ?string $postgresVersion = null, ?string $directory = null): Project
	{
		if ($reason = static::nameError($name)) {
			throw new \InvalidArgumentException($reason);
		}

		$project = new Project();
		$project->name = $name;
		$project->slug = static::slug($name);
		$project->path = ($directory ?? getcwd()) . DIRECTORY_SEPARATOR . $name;
		$project->phpVersion = $phpVersion ?? PhpFpm::defaultVersion();
		$project->postgresVersion = $postgresVersion ?? $project->detectPostgresVersion() ?? Postgres::defaultVersion();

		// A site that already serves this directory (e.g. My_App.local.winux.io from an older winux) is kept
		if (!Site::make($project->getDomain())->exists() && ($existing = static::siteServing($project->path))) {
			$project->domain = $existing->domain;
		}

		$project->site = Site::create($project->getDomain(), $project->path);

		// An existing site keeps its PHP version unless one was asked for
		if ($phpVersion === null && $project->site->exists() && ($sitePhp = $project->site->phpVersion())) {
			$project->phpVersion = $sitePhp;
		}
		$project->dbName = static::databaseName($project->slug);
		$project->dbUser = $project->dbName;

		return $project;
	}

	/**
	 * The site whose web root is exactly this project's public directory.
	 */
	protected static function siteServing(string $path): ?Site
	{
		return collect(Site::all())->first(fn (Site $site) => $site->projectPath() === $path);
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

		if ($reason = Site::unsafePathReason($this->path)) {
			error($reason);
			return false;
		}

		if (!Certificate::create($this->getDomain(), $this->extraDomains)) {
			return false;
		}

		// Written, enabled, and checked with `nginx -t`; removed again (with its certificate) if nginx rejects it
		if (!$this->site->createAndEnable($this->phpVersion, $this->extraDomains)) {
			Certificate::destroy($this->getDomain());
			return false;
		}

		return true;
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

		return $this->site->enableVerified();
	}

	/**
	 * Creates the project's database and user. Only import passes $reuse to adopt existing ones.
	 */
	public function setupDatabase(bool $reuse = false): bool
	{
		$this->dbPassword = Postgres::generatePassword();
		return $this->postgres()->createAll($this->dbName, $this->dbUser, $this->dbPassword, $reuse || $this->reuseDatabase);
	}

	/**
	 * For a new project whose database or user already exists: refuses if another winux project's
	 * .env uses them (e.g. my_app's, for my-app). Otherwise they were left behind by a deleted
	 * project with this name, so offers to pick them back up, data and all.
	 */
	public function claimExistingDatabase(): bool
	{
		$postgres = $this->postgres();
		if (!($existing = $postgres->existing($this->dbName, $this->dbUser))) {
			return true;
		}

		$described = 'the Postgres ' . implode(' and the ', $existing) . " on {$postgres->label()}";
		if ($user = $this->otherProjectUsing($this->dbName, $this->dbUser)) {
			error(ucfirst($described) . " already exist and are used by $user.");
			note('Pick a different project name.');
			return false;
		}

		warning(ucfirst($described) . ' already exist, but no other winux project uses them (probably left from an earlier project named this).');
		if (!confirm('Reuse them? Their data is kept, and the user gets a new password.', true)) {
			note("Pick a different project name, or drop them first with `winux postgres:delete {$this->dbName} --pg={$postgres->version}`.");
			return false;
		}

		return $this->reuseDatabase = true;
	}

	/**
	 * The site of another winux project whose .env points at this database or user on the same cluster.
	 * Only projects with a winux site are visible; anything else using the database isn't.
	 */
	public function otherProjectUsing(string $database, string $username): ?string
	{
		$port = $this->postgres()->getPort();

		foreach (Site::all() as $site) {
			$path = $site->projectPath();
			if (!$path || rtrim($path, '/') === rtrim($this->path, '/')) {
				continue;
			}

			$env = static::readEnv($path . DIRECTORY_SEPARATOR . '.env');
			$local = in_array($env['DB_HOST'] ?? '127.0.0.1', ['127.0.0.1', 'localhost', '::1'], true);

			if (($env['DB_CONNECTION'] ?? null) === 'pgsql' && $local && (int) ($env['DB_PORT'] ?? 5432) === $port
				&& (($env['DB_DATABASE'] ?? null) === $database || ($env['DB_USERNAME'] ?? null) === $username)) {
				return "{$site->domain} ($path)";
			}
		}

		return null;
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

		// Never take over the cluster's own database or a superuser (e.g. DB_USERNAME=postgres)
		if (Postgres::isProtectedDatabase($this->dbName) || $postgres->isProtectedRole($this->dbUser)) {
			error("The .env uses the built-in database '{$this->dbName}' or the superuser '{$this->dbUser}', which winux won't modify.");
			note('Fix DB_PASSWORD in .env yourself, or give the app its own database and user: set DB_DATABASE and DB_USERNAME to an app-specific name (or remove them so winux creates ones named after the project), then run the import again.');
			return false;
		}

		// The project-named database (e.g. my_app) may belong to another project (e.g. my-app)
		if ($postgres->existing($this->dbName, $this->dbUser) && ($user = $this->otherProjectUsing($this->dbName, $this->dbUser))) {
			error("The database '{$this->dbName}' or user '{$this->dbUser}' on {$postgres->label()} is used by $user; winux won't change it for this project.");
			note('Point DB_DATABASE and DB_USERNAME in .env at a database of its own (or fix DB_PASSWORD if it really is shared), then run the import again.');
			return false;
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

		return $this->setupDatabase(reuse: true);
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
		return static::readEnv($this->path . DIRECTORY_SEPARATOR . '.env')[$key] ?? null;
	}

	/** @return array<string, string> KEY => unquoted value */
	public static function readEnv(string $envFile): array
	{
		if (!is_file($envFile) || !preg_match_all('/^([A-Z0-9_]+)=(.*)$/m', (string) @file_get_contents($envFile), $matches, PREG_SET_ORDER)) {
			return [];
		}

		return collect($matches)->mapWithKeys(fn ($match) => [$match[1] => trim($match[2], " \t\"'")])->all();
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

	/**
	 * Runs `git init` and makes the initial commit. Without a git identity (user.name and user.email)
	 * the repository is still created, but nothing is committed.
	 */
	public function initializeGitRepository(): bool
	{
		if (Process::path($this->path)->run(['git', 'init', '-b', 'main'])->failed()) {
			error('Failed to initialize the git repository.');
			return false;
		}

		$this->hasRepo = true;

		return $this->gitCommit('Initial commit');
	}

	/**
	 * Whether git knows who to commit as (user.name and user.email, from any config level).
	 */
	public function hasGitIdentity(): bool
	{
		foreach (['user.name', 'user.email'] as $key) {
			$process = Process::path($this->path)->run(['git', 'config', '--get', $key]);
			if ($process->failed() || trim($process->output()) === '') {
				return false;
			}
		}

		return true;
	}

	public function gitCommit(string $message): bool
	{
		if (!$this->hasGitIdentity()) {
			warning("Skipped the git commit ('$message'): git doesn't know who you are yet.");
			note('Set your identity with `git config --global user.name "Your Name"` and `git config --global user.email you@example.com`, then commit yourself.');
			return false;
		}

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

	/**
	 * Installs the given extra packages (committing each one when the project has a repository).
	 *
	 * @param Packages[] $packages
	 */
	public function installAdditionalPackages(array $packages, bool $commit = true): bool
	{
		$success = true;

		foreach ($packages as $package) {
			info("Installing {$package->label()}...");
			if (!$package->install($this->path, $this->phpBinary())) {
				$success = false;
				continue;
			}

			if ($commit && $this->hasRepo) {
				$this->gitCommit("Installed {$package->label()}");
			}
		}

		return $success;
	}

	/**
	 * `composer install` with the site's PHP version, for a checkout without vendor/.
	 */
	public function installDependencies(): bool
	{
		if (is_file($this->path . '/vendor/autoload.php') || !is_file($this->path . '/composer.json')) {
			return true;
		}

		if (!($composer = static::composerPath())) {
			error('vendor/ is missing and Composer was not found on your PATH. See https://getcomposer.org/download/');
			return false;
		}

		info("Installing Composer dependencies with PHP {$this->phpVersion}...");
		$process = Process::path($this->path)->forever()->run(
			[$this->phpBinary(), $composer, 'install', '--no-interaction'],
			function ($type, $buffer) {
				echo $buffer;
			}
		);

		if ($process->failed()) {
			error("`composer install` failed. Fix the problem above, then run `{$this->phpBinary()} $composer install` in {$this->path}.");
			return false;
		}

		return true;
	}

	/**
	 * Generates APP_KEY when .env leaves it empty (e.g. right after copying .env.example).
	 */
	public function ensureAppKey(): bool
	{
		if (($this->envValue('APP_KEY') ?? '') !== '' || !is_file($this->path . '/artisan')) {
			return true;
		}

		$process = Process::path($this->path)->run([$this->phpBinary(), 'artisan', 'key:generate', '--force', '--no-interaction']);
		if ($process->failed()) {
			error('Failed to generate APP_KEY: ' . trim($process->errorOutput() ?: $process->output()));
			return false;
		}

		info('Generated APP_KEY.');
		return true;
	}

	public static function composerPath(): ?string
	{
		return trim(Process::run(['sh', '-c', 'command -v composer'])->output()) ?: null;
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

	/**
	 * What project:delete would drop from Postgres: the database and user in .env when they're on a
	 * winux cluster, else the ones named after the project. Only ones that exist are returned, and never
	 * the built-in databases or a superuser; anything left alone is explained in 'notes'.
	 *
	 * @return array{version: ?string, database: ?string, username: ?string, notes: string[]}
	 */
	public function databaseToDrop(): array
	{
		$plan = ['version' => null, 'database' => null, 'username' => null, 'notes' => []];
		$database = $this->dbName;
		$username = $this->dbUser;
		$version = $this->postgresVersion;

		if ($env = $this->envDatabase()) {
			$version = collect(Postgres::clusters())->search(fn ($cluster) => $cluster['port'] === $env['port']);
			if (!in_array($env['host'], ['127.0.0.1', 'localhost', '::1'], true) || $version === false) {
				$plan['notes'][] = "The database in .env ('{$env['database']}' at {$env['host']}:{$env['port']}) isn't on a winux Postgres cluster; leaving it alone.";
				return $plan;
			}
			$database = $env['database'];
			$username = $env['username'];
		}

		$postgres = Postgres::make((string) $version);
		if (!$postgres->isInstalled()) {
			$plan['notes'][] = "{$postgres->label()} isn't installed; no database to drop.";
			return $plan;
		}
		$plan['version'] = $postgres->version;

		if (Postgres::isProtectedDatabase($database)) {
			$plan['notes'][] = "Keeping the built-in database '$database'.";
		} elseif ($postgres->databaseExists($database)) {
			$plan['database'] = $database;
		}

		if ($postgres->isProtectedRole($username)) {
			$plan['notes'][] = "Keeping the superuser '$username'.";
		} elseif ($postgres->userExists($username)) {
			$plan['username'] = $username;
		}

		return $plan;
	}

	/**
	 * Removes the site (and its hosts entries), the certificate, the given database plan (see databaseToDrop()),
	 * and optionally the project directory. Keeps going after a failure, but reports it.
	 *
	 * @param array{version: ?string, database: ?string, username: ?string} $database
	 */
	public function destroy(array $database, bool $deleteDirectory = false): bool
	{
		$success = true;

		if ($this->site->exists() || $this->site->isEnabled()) {
			$extraDomains = $this->site->exists() ? $this->site->extraDomains() : [];

			if ($this->site->disable() && $this->site->delete()) {
				Hosts::remove(array_filter($extraDomains, fn ($domain) => !Hosts::coveredByWildcard($domain) && !str_starts_with($domain, '*.')));
			} else {
				$success = false;
			}

			if (!Nginx::make()->reload()) {
				warning('nginx failed to reload; run `winux nginx:restart` once the problem is fixed.');
				$success = false;
			}
		}

		$success = Certificate::destroy($this->getDomain()) && $success;

		if ($database['version'] && ($database['database'] || $database['username'])) {
			$postgres = Postgres::make($database['version']);
			$dropped = (!$database['database'] || $postgres->dropDatabase($database['database']))
				&& (!$database['username'] || $postgres->dropUser($database['username']));

			if ($dropped) {
				info('Dropped ' . implode(' and ', array_filter([
					$database['database'] ? "the database '{$database['database']}'" : null,
					$database['username'] ? "the user '{$database['username']}'" : null,
				])) . " from {$postgres->label()}.");
			} else {
				alert('Failed to drop some (or all) database resources.');
				$success = false;
			}
		}

		if ($deleteDirectory && is_dir($this->path)) {
			if (!File::deleteDirectory($this->path)) {
				error('Failed to delete the project directory.');
				return false;
			}

			info('Deleted the project directory.');
		}

		return $success;
	}
	#endregion

	#region Openers
	public function openEditor(Editors $editor = Editors::VSCODE): bool
	{
		$path = $this->path;
		if ($editor->needsWindowsPath() && Platform::isWsl()) {
			$path = trim(Process::run(['wslpath', '-w', $this->path])->output()) ?: $this->path;
		}

		$process = Process::run([$editor->getCommand(), $path]);
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
		$versioned = config('winux.php.bin', '/usr/bin') . "/php{$this->phpVersion}";
		return is_executable($versioned) ? $versioned : 'php';
	}

	private function installLaravel(): bool
	{
		$composer = static::composerPath();
		if ($composer === null) {
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
