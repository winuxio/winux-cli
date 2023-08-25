<?php

namespace App\Classes;

use App\Enums\Editors;
use App\Enums\Frameworks;
use App\Services\Nginx;
use App\Services\Postgres;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\alert;
use function Laravel\Prompts\confirm;
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

		// Site
		$project->site = Site::create($project->getDomain(), $project->path);

		// Database
		$dbName = $project->name;
		// Convert hyphens to underscores
		$dbName = str_replace('-', '_', $dbName);
		// Convert the db name to a valid database name, (only lowercase letters and underscores)
		$project->dbName = preg_replace('/[^a-z0-9_]/', '', $dbName);

		return $project;
	}

	#region Meta-Methods
	public function setupNginx(): bool
	{
		return $this->site->generateAndSave()
			&& $this->site->enable()
			&& OpenSSL::init()
			&& Certificate::create($this->getDomain())
			&& Nginx::make()->restart();
	}

	public function setupDatabase(): bool
	{
		$this->dbPassword = Postgres::generatePassword();
		return Postgres::make()->createAll($this->dbName, password: $this->dbPassword);
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

	public function destroy(): bool
	{
		// Delete the NGINX site for this project
		// $this->call('nginx:delete', ['domain' => $projectName]);
		$this->site->disable();
		$this->site->delete();
		Nginx::make()->restart();

		Certificate::destroy($this->getDomain());

		// and then tear down the database and user
		// $this->call('postgres:delete', ['database' => $projectName]);
		$postgres = Postgres::make();
		$dbName = $this->name;
		// Convert hyphens to underscores
		$dbName = str_replace('-', '_', $dbName);
		// Convert the db name to a valid database name, (only lowercase letters and underscores)
		$dbName = preg_replace('/[^a-z0-9_]/', '', $dbName);
		if (!$postgres->dropAll($dbName)) {
			alert('Failed to drop some (or all) database resources.');
			return false;
		}

		info("Dropped all database resources.\n");

		// Delete the project directory
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

	#region Helpers

	#endregion

}
