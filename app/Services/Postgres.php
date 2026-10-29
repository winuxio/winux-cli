<?php

namespace App\Services;

use App\Classes\AptRepository;
use App\Classes\Platform;
use App\Classes\ServiceUser;
use App\Classes\Settings;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\{alert, error, info, warning};

class Postgres extends Service
{
	public string $version;

	/** @var array<string, array{port: int, status: string}>|null */
	protected static ?array $clusters = null;

	/** Whether you can connect as your own superuser role, by port */
	protected static array $peerAccess = [];

	/** Postgres' default socket directory on Ubuntu and Debian */
	public const SOCKET_DIRECTORY = '/var/run/postgresql';

	public function __construct(?string $version = null)
	{
		$this->version = $version ?? static::defaultVersion();

		if (!preg_match('/^\d+$/', $this->version)) {
			throw new \InvalidArgumentException("Invalid Postgres version '{$this->version}'. Expected something like 16.");
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
		return "postgresql@{$this->version}-main";
	}

	public function label(): string
	{
		return "Postgres {$this->version}";
	}

	protected function packages(): array
	{
		return ["postgresql-{$this->version}"];
	}

	public function isInstalled(): bool
	{
		return in_array($this->version, static::installedVersions(), true);
	}

	protected function preInstall(): bool
	{
		return AptRepository::ensurePostgres();
	}

	protected function postInstall(): bool
	{
		static::flushClusterCache();

		// Your own superuser role, so projects don't need `sudo -u postgres` (a failure isn't fatal: sudo still works)
		if (!$this->grantPeerAccess()) {
			warning('winux will use sudo for Postgres until `winux doctor --fix` creates your role.');
		}

		return true;
	}

	/**
	 * postgresql@16-main is a systemd template unit; without systemd there's no init script by that
	 * name, so the cluster is driven with pg_ctlcluster (postgresql-common) instead.
	 */
	public function controlCommand(string $command): array
	{
		return Platform::hasSystemd()
			? ['sudo', 'systemctl', $command, $this->serviceName()]
			: ['sudo', 'pg_ctlcluster', $this->version, 'main', $command];
	}

	protected function control(string $command): bool
	{
		$commandParts = $this->controlCommand($command);
		info('Running command: ' . implode(' ', $commandParts));
		$process = Process::timeout(120)->run($commandParts);
		static::$clusters = null;

		if ($process->failed()) {
			alert("Failed to $command {$this->label()}.");
			error($process->errorOutput());
			return false;
		}

		return true;
	}

	public function isActive(): bool
	{
		if (Platform::hasSystemd()) {
			return parent::isActive();
		}

		// `pg_ctlcluster status` needs root or the postgres user; pg_lsclusters doesn't
		static::$clusters = null;
		return (static::clusters()[$this->version]['status'] ?? null) === 'online';
	}

	/**
	 * Why a requested Postgres version (e.g. from --pg) can't be used, or null if it's installed.
	 */
	public static function versionError(?string $version): ?string
	{
		$installed = static::installedVersions();
		$available = $installed ? 'Installed: ' . implode(', ', $installed) . '.' : 'No Postgres versions are installed yet.';

		if ($version !== null && !preg_match('/^\d+$/', $version)) {
			return "'$version' isn't a Postgres version (expected something like 16). $available";
		}

		$version ??= static::defaultVersion();
		if (!in_array($version, $installed, true)) {
			return "Postgres $version isn't installed. $available Install it with `winux service:install postgres $version`.";
		}

		return null;
	}

	public function getPort(): int
	{
		$clusters = static::clusters();
		if (!isset($clusters[$this->version])) {
			throw new \RuntimeException("Postgres {$this->version} is not installed. Run `winux service:install postgres {$this->version}`.");
		}

		return $clusters[$this->version]['port'];
	}

	/**
	 * The version chosen via `winux postgres:default`, else the newest installed one,
	 * else the configured default (what a fresh install gets).
	 */
	public static function defaultVersion(): string
	{
		$installed = static::installedVersions();
		$chosen = Settings::get('postgres.default');

		if ($chosen !== null && (empty($installed) || in_array((string) $chosen, $installed, true))) {
			return (string) $chosen;
		}

		return $installed ? end($installed) : (string) config('winux.postgres.default');
	}

	/** @return string[] */
	public static function installedVersions(): array
	{
		$versions = array_map('strval', array_keys(static::clusters()));
		sort($versions, SORT_NUMERIC);
		return $versions;
	}

	/**
	 * The 'main' cluster of each installed Postgres version, keyed by version.
	 *
	 * @return array<string, array{port: int, status: string}>
	 */
	public static function clusters(): array
	{
		if (static::$clusters !== null) {
			return static::$clusters;
		}

		$process = Process::run(['pg_lsclusters', '--no-header']);
		if ($process->failed()) {
			return static::$clusters = [];
		}

		return static::$clusters = static::parseClusters($process->output());
	}

	public static function parseClusters(string $output): array
	{
		$clusters = [];
		foreach (explode("\n", trim($output)) as $line) {
			// Ver Cluster Port Status Owner Data-directory Log-file
			$columns = preg_split('/\s+/', trim($line));
			if (count($columns) < 4 || $columns[1] !== 'main') {
				continue;
			}

			$clusters[$columns[0]] = ['port' => (int) $columns[2], 'status' => $columns[3]];
		}

		return $clusters;
	}

	public static function flushClusterCache(): void
	{
		static::$clusters = null;
		static::$peerAccess = [];
	}

	/** Your Linux user name, which is also the name of your Postgres role */
	public static function peerRole(): ?string
	{
		return ServiceUser::current()['name'] ?? null;
	}

	/**
	 * Whether you can connect to this cluster as a superuser role named after you, over the local
	 * socket: Ubuntu's and Debian's Postgres let a Linux user in as the matching role without a password.
	 */
	public function hasPeerAccess(): bool
	{
		$port = $this->getPort();
		if (isset(static::$peerAccess[$port])) {
			return static::$peerAccess[$port];
		}

		$role = static::peerRole();
		if ($role === null || $role === 'root') {
			return static::$peerAccess[$port] = false;
		}

		$process = Process::path('/')->env(['PGCONNECT_TIMEOUT' => '5'])->timeout(15)->run([
			'psql', '-w', '-h', static::SOCKET_DIRECTORY, '-p', (string) $port, '-U', $role, '-d', 'postgres',
			'-tAc', 'SELECT rolsuper FROM pg_roles WHERE rolname = current_user',
		]);

		return static::$peerAccess[$port] = $process->successful() && trim($process->output()) === 't';
	}

	/**
	 * Create (once, through sudo) the superuser role named after you, so winux stops needing sudo
	 * for Postgres. A role with your name that isn't a superuser belongs to a project, and is left alone.
	 */
	public function grantPeerAccess(): bool
	{
		$role = static::peerRole();
		if ($role === null || $role === 'root') {
			error("Can't tell which Linux user to create a Postgres role for.");
			return false;
		}

		if ($this->hasPeerAccess()) {
			return true;
		}

		if ($this->userExists($role)) {
			error("Postgres {$this->version} already has a role named '$role' that isn't a superuser (probably a project's), so winux will keep using sudo for it.");
			return false;
		}

		$created = $this->query('CREATE ROLE ' . static::identifier($role) . ' WITH LOGIN SUPERUSER');
		unset(static::$peerAccess[$this->getPort()]);

		return $created && $this->hasPeerAccess();
	}

	/**
	 * Creates a database and a user that owns it. An existing database or user is only adopted
	 * (and the user's password reset) with $reuse, which only `project:import` asks for.
	 */
	public function createAll(string $dbName, ?string $username = null, ?string $password = null, bool $reuse = false): bool
	{
		$username ??= $dbName;
		$password ??= self::generatePassword();

		if (!$reuse && ($existing = $this->existing($dbName, $username))) {
			error('The ' . implode(' and the ', $existing) . " already exist on {$this->label()}; refusing to reuse them.");
			return false;
		}

		if (static::isProtectedDatabase($dbName) || $this->isProtectedRole($username)) {
			error("Refusing to use the built-in database '$dbName' or the superuser '$username'.");
			return false;
		}

		if (!$this->databaseExists($dbName) && !$this->createDatabase($dbName)) {
			return false;
		}

		// Reset the password of an existing user so the new .env matches
		$userReady = $this->userExists($username)
			? $this->setPassword($username, $password)
			: $this->createUser($username, $password);

		return $userReady && $this->grant($dbName, $username);
	}

	/**
	 * Which of the given database and user already exist, e.g. ["database 'my_app'", "user 'my_app'"].
	 *
	 * @return string[]
	 */
	public function existing(string $dbName, ?string $username = null): array
	{
		$username ??= $dbName;

		return array_values(array_filter([
			$this->databaseExists($dbName) ? "database '$dbName'" : null,
			$this->userExists($username) ? "user '$username'" : null,
		]));
	}

	/**
	 * Drops a database and its user. The built-in databases and superuser roles are never dropped.
	 */
	public function dropAll(string $dbName, ?string $username = null): bool
	{
		$username ??= $dbName;
		$success = true;

		if (static::isProtectedDatabase($dbName)) {
			warning("Skipping the built-in database '$dbName'.");
		} else {
			$success = $this->dropDatabase($dbName);
		}

		if ($this->isProtectedRole($username)) {
			warning("Skipping the superuser '$username'.");
			return $success;
		}

		return $this->dropUser($username) && $success;
	}

	/** postgres and the template databases belong to the cluster, never to a project. */
	public static function isProtectedDatabase(string $databaseName): bool
	{
		return $databaseName === 'postgres' || str_starts_with($databaseName, 'template');
	}

	/** The postgres role and any other superuser. */
	public function isProtectedRole(string $username): bool
	{
		return $username === 'postgres'
			|| $this->scalar('SELECT 1 FROM pg_roles WHERE rolsuper AND rolname = ' . static::literal($username)) === '1';
	}

	public function databaseOwner(string $databaseName): ?string
	{
		$owner = $this->scalar('SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = ' . static::literal($databaseName));
		return $owner === '' ? null : $owner;
	}

	public static function generatePassword(): string
	{
		return Str::password(16, true, true, false);
	}

	public function databaseExists(string $databaseName): bool
	{
		return $this->scalar('SELECT 1 FROM pg_database WHERE datname = ' . static::literal($databaseName)) === '1';
	}

	public function userExists(string $username): bool
	{
		return $this->scalar('SELECT 1 FROM pg_roles WHERE rolname = ' . static::literal($username)) === '1';
	}

	public function createDatabase(string $databaseName): bool
	{
		return $this->query('CREATE DATABASE ' . static::identifier($databaseName));
	}

	public function dropDatabase(string $databaseName): bool
	{
		return $this->query('DROP DATABASE IF EXISTS ' . static::identifier($databaseName) . ' WITH (FORCE)');
	}

	public function createUser(string $username, string $password): bool
	{
		return $this->query('CREATE ROLE ' . static::identifier($username) . ' WITH LOGIN PASSWORD ' . static::literal($password));
	}

	public function dropUser(string $username): bool
	{
		return $this->query('DROP ROLE IF EXISTS ' . static::identifier($username));
	}

	public function grant(string $databaseName, string $username): bool
	{
		$db = static::identifier($databaseName);
		$user = static::identifier($username);

		// A database another role already owns (e.g. an imported one) keeps its owner; the user just gets access.
		// One the superuser winux connects as owns (postgres, or your own role) was made by winux, and is handed over
		$owner = $this->databaseOwner($databaseName);
		if ($owner !== null && !in_array($owner, ['postgres', static::peerRole(), $username], true)) {
			warning("'$databaseName' is owned by '$owner'; granting '$username' access instead of taking ownership.");

			return $this->query("GRANT ALL PRIVILEGES ON DATABASE $db TO $user")
				&& $this->query("GRANT ALL ON SCHEMA public TO $user", $databaseName)
				&& $this->query("GRANT ALL ON ALL TABLES IN SCHEMA public TO $user", $databaseName)
				&& $this->query("GRANT ALL ON ALL SEQUENCES IN SCHEMA public TO $user", $databaseName);
		}

		// Owning the database (and its public schema) covers Postgres 15+'s tightened defaults
		// Existing tables (e.g. an imported database) may be owned by another role
		return $this->query("ALTER DATABASE $db OWNER TO $user")
			&& $this->query("GRANT ALL PRIVILEGES ON DATABASE $db TO $user")
			&& $this->query("ALTER SCHEMA public OWNER TO $user", $databaseName)
			&& $this->query("GRANT ALL ON ALL TABLES IN SCHEMA public TO $user", $databaseName)
			&& $this->query("GRANT ALL ON ALL SEQUENCES IN SCHEMA public TO $user", $databaseName);
	}

	public function setPassword(string $username, string $password): bool
	{
		return $this->query('ALTER ROLE ' . static::identifier($username) . ' WITH LOGIN PASSWORD ' . static::literal($password));
	}

	/**
	 * Whether the given credentials can log in, the way the app would (over TCP with a password).
	 */
	public static function canConnect(string $host, int $port, string $database, string $username, string $password): bool
	{
		return Process::env(['PGPASSWORD' => $password, 'PGCONNECT_TIMEOUT' => '5'])
			->timeout(15)
			->run(['psql', '-w', '-h', $host, '-p', (string) $port, '-U', $username, '-d', $database, '-tAc', 'SELECT 1'])
			->successful();
	}

	public static function identifier(string $name): string
	{
		return '"' . str_replace('"', '""', $name) . '"';
	}

	public static function literal(string $value): string
	{
		return "'" . str_replace("'", "''", $value) . "'";
	}

	/**
	 * psql as a superuser: your own role when you have one, otherwise postgres through sudo. SQL is
	 * always sent on stdin (`-f -`), never as an argument, so passwords in it don't show up in `ps`
	 * or in sudo's log.
	 */
	protected function psql(array $args, string $database = 'postgres'): array
	{
		$connect = $this->hasPeerAccess()
			? ['psql', '-w', '-h', static::SOCKET_DIRECTORY, '-U', (string) static::peerRole()]
			: ['sudo', '-u', 'postgres', 'psql'];

		return [
			...$connect,
			'-p', (string) $this->getPort(),
			'-d', $database,
			'-v', 'ON_ERROR_STOP=1',
			...$args,
			'-f', '-',
		];
	}

	protected function scalar(string $query): string
	{
		return trim(Process::path('/')->input($query)->run($this->psql(['-tA']))->output());
	}

	protected function query(string $query, string $database = 'postgres'): bool
	{
		$process = Process::path('/')->input($query)->run($this->psql([], $database));

		if ($process->failed()) {
			warning($process->errorOutput());
			return false;
		}

		return true;
	}
}
