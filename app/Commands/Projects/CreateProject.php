<?php

namespace App\Commands\Projects;

use App\Classes\Site;
use App\Classes\Winux;
use App\Services\Nginx;
use App\Services\Postgres;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{alert, confirm, error, info};

class CreateProject extends Command
{
	/**
	 * The signature of the command.
	 *
	 * @var string
	 */
	protected $signature = 'project:create {projectName : The name of your new project that will be created in the local directory}'; // {--type=laravel}';

	/**
	 * The description of the command.
	 *
	 * @var string
	 */
	protected $description = 'Create a new project';

	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function handle()
	{
		$projectName = $this->argument('projectName');
		// $projectType = $this->option('type');

		Winux::SetupEnvironment();

		// Ensure the project name is linux directory name friendly
		$projectName = preg_replace('/[^a-zA-Z0-9-_\.]/', '', $projectName);

		$domain = $projectName . '.localhost';
		// Calculate the path of the new project
		$projectDirectory = getcwd() . '/' . $projectName;
		info("Creating project '$projectName' at '$projectDirectory'");

		$site = Site::create($domain, $projectDirectory);

		// Lets create the project now
		if (!$this->createLaravel($projectName)) {
			alert('Failed to create the laravel project.');
			return 1;
		}

		info("Project files created for $projectName");

		info('Performing infrastructure configuration...');

		// and then setup the nginx site for it
		// $this->call('nginx:create', ['domain' => $projectName, 'path' => $projectDirectory]);

		// Create the nginx config
		info('Generating Nginx configuration...');
		$config = $site->generate();
		$this->line(Site::configDiff($config));
		info('Saving Nginx configuration...');
		$site->save($config);
		// Enable it in sites-enabled
		info('Enabling Nginx site...');
		$site->enable();
		// Restart nginx
		info('Restarting Nginx...');
		Nginx::make()->restart();


		// and then setup the database for it
		$postgres = Postgres::make();
		// $this->call('postgres:create', ['database' => $projectName]);
		$dbName = $projectName;
		// Convert hyphens to underscores
		$dbName = str_replace('-', '_', $dbName);
		// Convert the db name to a valid database name, (only lowercase letters and underscores)
		$dbName = preg_replace('/[^a-z0-9_]/', '', $dbName);
		$password = Postgres::generatePassword();
		if (!$postgres->createAll($dbName, password: $password)) {
			alert('Failed to create all required postgres resources.');
		}

		$envFile = $projectDirectory . '/.env';
		$currentEnvContent = file_get_contents($envFile);

		// TODO - Update the project's .env file with the new database credentials
		// Go to the project directory and update the .env file
		// Update the APP_NAME and APP_URL values
		// Change CACHE_DRIVER, SESSION_DRIVER, and QUEUE_CONNECTION to redis
		// Update the DB_CONNECTION, DB_PORT, DB_DATABASE, DB_USERNAME, and DB_PASSWORD values
		// Define the new values
		$newValues = [
			'APP_NAME' => "\"$projectName\"",
			'APP_URL' => '"http://' . $domain . '"',
			'CACHE_DRIVER' => 'redis',
			'SESSION_DRIVER' => 'redis',
			'QUEUE_CONNECTION' => 'redis',
			'DB_CONNECTION' => 'pgsql',
			'DB_PORT' => $postgres->getPort(),
			'DB_DATABASE' => "\"$dbName\"",
			'DB_USERNAME' => "\"$dbName\"",
			'DB_PASSWORD' => "\"$password\"",
		];

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

		info('New Project\'s .env file updated successfully!');

		$this->title('Project created successfully!');

		info('Switch into the new project directory with `cd ' . $projectName . '`');
		info("$projectName should now be live at http://$domain");
		if (confirm('Do you want to open the project in VS Code?', true)) {
			$this->openProject($projectDirectory);
		}

		Process::run(['explorer.exe', 'http://' . $domain]);

		return 0;
	}

	/**
	 * Define the command's schedule.
	 *
	 * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
	 * @return void
	 */
	public function schedule(Schedule $schedule): void
	{
		// $schedule->command(static::class)->everyMinute();
	}

	public function openProject(string $projectDirectory): bool
	{
		// TODO - Support PHPStorm
		$process = Process::run(['code', $projectDirectory]);
		if ($process->failed()) {
			error('Failed to open the project in VS Code.');
			return false;
		}
		return true;
	}

	public function createLaravel(string $projectName): bool
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

		$process = Process::run(['laravel', 'new', $projectName], function ($type, $buffer) {
			if ($type === 'out') {
				// $this->line($buffer);
				$this->output->write($buffer);
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
}
