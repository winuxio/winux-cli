<?php

namespace App\Services;

use App\Classes\AptRepository;
use App\Classes\Settings;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\warning;

class Postgres extends Service
{
	public string $version;

	/** @var array<string, array{port: int, status: string}>|null */
	protected static ?array $clusters = null;

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
		static::$clusters = null;
		return true;
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
	}

	public function createAll(string $dbName, ?string $username = null, ?string $password = null): bool
	{
		$username ??= $dbName;
		$password ??= self::generatePassword();

		if (!$this->databaseExists($dbName) && !$this->createDatabase($dbName)) {
			return false;
		}

		// Reset the password of an existing user so the new .env matches
		$userReady = $this->userExists($username)
			? $this->setPassword($username, $password)
			: $this->createUser($username, $password);

		return $userReady && $this->grant($dbName, $username);
	}

	public function dropAll(string $dbName, ?string $username = null): bool
	{
		$username ??= $dbName;
		$success = $this->dropDatabase($dbName);
		return $this->dropUser($username) && $success;
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

	protected function psql(array $args, string $database = 'postgres'): array
	{
		return [
			'sudo', '-u', 'postgres', 'psql',
			'-p', (string) $this->getPort(),
			'-d', $database,
			'-v', 'ON_ERROR_STOP=1',
			...$args,
		];
	}

	protected function scalar(string $query): string
	{
		return trim(Process::path('/')->run($this->psql(['-tAc', $query]))->output());
	}

	protected function query(string $query, string $database = 'postgres'): bool
	{
		$process = Process::path('/')->run($this->psql(['-c', $query], $database));

		if ($process->failed()) {
			warning($process->errorOutput());
			return false;
		}

		return true;
	}
}
