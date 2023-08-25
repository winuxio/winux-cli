<?php

namespace App\Classes;

use App\Enums\Editors;
use App\Enums\Frameworks;
use App\Services\Nginx;
use App\Services\Postgres;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\alert;
use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

class Project
{
	public string $name;
	public string $path;
	public Frameworks $framework = Frameworks::Laravel;
	public Site $site;

	protected string $dbName;
	// protected string $dbUser;
	protected string $dbPassword;

	public function getDomain(): string
	{
		return $this->name . '.localhost';
	}

	/**
	 * Create a new project instance in the current directory
	 */
	public static function create(string $name): Project
	{
		$project = new Project();
		$project->name = preg_replace('/[^a-zA-Z0-9-_\.]/', '', $name);
		$project->path = getcwd() . '/' . $name;
		return $project;
	}

	#region Meta-Methods
	public function setupNginx(): bool
	{
		$this->site = Site::create($this->getDomain(), $this->path);

		return $this->site->generateAndSave()
			&& $this->site->enable()
			&& OpenSSL::init()
			&& Certificate::create($this->getDomain())
			&& Nginx::make()->restart();
	}

	public function setupDatabase(): bool
	{
		// and then setup the database for it
		$postgres = Postgres::make();
		// $this->call('postgres:create', ['database' => $projectName]);
		$this->dbName = $this->name;
		// Convert hyphens to underscores
		$this->dbName = str_replace('-', '_', $this->dbName);
		// Convert the db name to a valid database name, (only lowercase letters and underscores)
		$this->dbName = preg_replace('/[^a-z0-9_]/', '', $this->dbName);
		$this->dbPassword = Postgres::generatePassword();
		if (!$postgres->createAll($this->dbName, password: $this->dbPassword)) {
			return false;
		}
		return true;
	}

	public function setupEnv()
	{
		$newValues = [
			'APP_NAME' => "\"$this->name\"",
			'APP_URL' => '"https://' . $this->getDomain() . '"',
			'CACHE_DRIVER' => 'redis',
			'SESSION_DRIVER' => 'redis',
			'QUEUE_CONNECTION' => 'redis',
			'DB_CONNECTION' => 'pgsql',
			'DB_PORT' => Postgres::make()->getPort(),
			'DB_DATABASE' => "\"{$this->dbName}\"",
			'DB_USERNAME' => "\"{$this->dbName}\"",
			'DB_PASSWORD' => "\"{$this->dbPassword}\"",
		];

		$envFile = $this->path . DIRECTORY_SEPARATOR . '.env';
		$currentEnvContent = file_get_contents($envFile);

		// Update the .env file with the new values using regular expressions
		foreach ($newValues as $key => $value) {
			$currentEnvContent = preg_replace(
				'/^' . $key . '=(.*)$/m', // Match the key at the beginning of a line
				$key . '=' . $value,     // Replace with the new value
				$currentEnvContent
			);
		}

		// Write the updated content back to the .env file
		file_put_contents($envFile, $currentEnvContent);
	}
	#endregion

	#region Openers
	public function openEditor(Editors $editor = Editors::VSCODE): bool
	{
		$process = Process::run([$editor->getCommand(), $this->path]);
		if ($process->failed()) {
			error('Failed to open the project in VS Code.');
			return false;
		}
		return true;
	}

	public function openBrowser(): bool
	{
		$process = Process::run(['explorer.exe', 'https://' . $this->getDomain()]);
		if ($process->failed()) {
			error('Failed to open the project in the browser.');
			return false;
		}
		return true;
	}
	#endregion

	#region Installers
	public function installFramework(): bool
	{
		switch ($this->framework) {
			case Frameworks::Laravel:
				return $this->installLaravel();
			default:
				error('Unknown framework: ' . $this->framework->value);
				return false;
		}
	}

	private function installLaravel(): bool
	{
		// Ensure the laravel installer is installed.
		// Check if the laravel installer is installed.
		$process = Process::run(['laravel', '--version']);
		if ($process->failed()) {
			$process = Process::run(['composer', 'global', 'require', 'laravel/installer']);
			if ($process->failed()) {
				error('Failed to install the laravel installer.');
				return false;
			}
		}

		$process = Process::run(['laravel', 'new', $this->name], function ($type, $buffer) {
			if ($type === 'out') {
				// $this->line($buffer);
				note($buffer);
			} else if ($type === 'err') {
				error($buffer);
				return false;
			}
		});

		if ($process->failed()) {
			error('Failed to create the laravel project.');
			return false;
		}

		return true;
	}
	#endregion
}
