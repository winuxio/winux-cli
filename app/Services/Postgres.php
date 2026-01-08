<?php

namespace App\Services;

use App\Enums\Versions\PostgresVersion;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\error;
use function Laravel\Prompts\warning;

class Postgres extends Service
{
	protected static ?string $serviceName = 'postgresql';

	// public int $port = 5432;

	protected array $installedVersions = [ ];
	public PostgresVersion $version;

	public function __construct()
	{
		$this->checkVersions();
		// Set the version to the last installed version
		$this->version = PostgresVersion::from(array_key_last($this->installedVersions));
		$this->commandSuffix = $this->version->value;
	}

	public function getPort(): int
	{
		return $this->installedVersions[$this->version->value];
	}

	public function createAll(string $dbName, ?string $username = null, ?string $password = null): bool
	{
		$username ??= $dbName;
		$password ??= self::generatePassword();

		// Create database if it doesn't exist
		if (!$this->databaseExists($dbName)) {
			if (!$this->createDatabase($dbName)) {
				return false;
			}
		}

		// Drop and recreate user to ensure we have the correct password
		if ($this->userExists($username)) {
			$this->dropUser($username);
		}

		if (!$this->createUser($username, $password)) {
			return false;
		}

		return $this->grant($dbName, $username);
	}

	public function dropAll(string $dbName, ?string $username = null): bool
	{
		$username ??= $dbName;
		$success = true;
		if (!$this->dropDatabase($dbName)) {
			// error("Failed to drop database: $dbName");
			$success = false;
		}
		if (!$this->dropUser($username)) {
			// error("Failed to drop user: $username");
			$success = false;
		}
		return $success;
	}

	public static function generatePassword(): string
	{
		return \Illuminate\Support\Str::password(16, true, true, false);
	}

	public function databaseExists(string $databaseName): bool
	{
		$process = Process::run([
			'sudo', '-u', 'postgres', 'psql', '-p', $this->getPort(),
			'-tAc', "SELECT 1 FROM pg_database WHERE datname = '$databaseName'"
		]);
		return trim($process->output()) === '1';
	}

	public function userExists(string $username): bool
	{
		$process = Process::run([
			'sudo', '-u', 'postgres', 'psql', '-p', $this->getPort(),
			'-tAc', "SELECT 1 FROM pg_roles WHERE rolname = '$username'"
		]);
		return trim($process->output()) === '1';
	}

	public function createDatabase(string $databaseName): bool
	{
		return $this->query("CREATE DATABASE $databaseName");
	}

	public function dropDatabase(string $databaseName): bool
	{
		return $this->query("DROP DATABASE $databaseName");
	}

	public function createUser(string $username, string $password): bool
	{
		$query= "CREATE USER $username WITH ENCRYPTED PASSWORD '$password'";
		return $this->query($query);
	}

	public function dropUser(string $username): bool
	{
		// Revoke privileges first (required for PostgreSQL 15+)
		$this->query("REVOKE ALL ON SCHEMA public FROM $username");

		$query = "DROP USER $username";
		return $this->query($query);
	}

	public function grant(string $databaseName, string $username): bool
	{
		// Set the user as the database owner
		if (!$this->query("ALTER DATABASE $databaseName OWNER TO $username"))
			return false;

		$query = "GRANT ALL PRIVILEGES ON DATABASE $databaseName TO $username";
		if (!$this->query($query))
			return false;

		// PostgreSQL 15+ requires explicit schema grants
		if ((int) $this->version->value >= 15) {
			$query = "GRANT ALL ON SCHEMA public TO $username";
			if (!$this->query($query))
				return false;
		}

		return true;
	}

	protected function query(string $query): bool
	{
		$process = Process::run(['sudo', '-u', 'postgres', 'psql', '-p', $this->getPort(), '-c', $query]);

		if ($process->failed()) {
			warning($process->errorOutput());
			return false;
		}

		return $process->successful();
	}

	public function checkVersions(): void
	{
		// grep -H '^port' /etc/postgresql/*/main/postgresql.conf
		// Run the above command via Symfony process
		$process = Process::run("grep -H '^port' /etc/postgresql/*/main/postgresql.conf");
		$output = $process->output();
		$lines = collect(explode("\n", trim($output)));

		$lines->each(function ($line) {
			// Use regular expressions to extract the version and port
			if (preg_match('/\/etc\/postgresql\/(\d+)\/main\/postgresql\.conf:port = (\d+)/', $line, $matches)) {
				$version = $matches[1];
				$port = $matches[2];
		
				// Store in the associative array
				$this->installedVersions[PostgresVersion::from($version)->value] = $port;
			}
		});
		
	}

}