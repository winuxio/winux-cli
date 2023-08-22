<?php

namespace App\Services;

use App\Enums\Versions\PostgresVersion;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\error;

class Postgres extends Service
{
	protected static ?string $serviceName = 'postgresql';

	protected int $port = 5432;

	protected array $installedVersions = [ ];
	public PostgresVersion $version;

	public function __construct()
	{
		$this->checkVersions();
		// Set the version to the last installed version
		$this->version = PostgresVersion::from(array_key_last($this->installedVersions));
		$this->commandSuffix = $this->version->value;
	}

	protected function getPort(): int
	{
		return $this->installedVersions[$this->version->value];
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
		$query = "DROP USER $username";
		return $this->query($query);
	}

	public function grant(string $databaseName, string $username): bool
	{
		$query = "GRANT ALL PRIVILEGES ON DATABASE $databaseName TO $username";
		if (!$this->query($query))
			return false;

		if ($this->version == PostgresVersion::v15) {
			$query = "GRANT ALL ON SCHEMA public TO $username";
			if (!$this->query($query))
				return false;
		}

		return true;
	}

	protected function query(string $query): bool
	{
		$process = new Process(['sudo', '-u', 'postgres', 'psql', '-p', $this->getPort(), '-c', $query]);
		$process->run();

		if (!$process->isSuccessful()) {
			error($process->getErrorOutput());
			return false;
		}

		return $process->isSuccessful();
	}

	public function checkVersions(): void
	{
		// grep -H '^port' /etc/postgresql/*/main/postgresql.conf
		// Run the above command via Symfony process
		$process = Process::fromShellCommandline("grep -H '^port' /etc/postgresql/*/main/postgresql.conf");
		$process->run();
		$output = $process->getOutput();
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