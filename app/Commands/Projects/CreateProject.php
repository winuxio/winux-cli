<?php

namespace App\Commands\Projects;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
// use Symfony\Component\Process\Exception\ProcessFailedException;
// use Symfony\Component\Process\Process;

use function Laravel\Prompts\alert;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

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

		// Ensure the project name is linux directory name friendly
		$projectName = preg_replace('/[^a-zA-Z0-9-_\.]/', '', $projectName);
		// Calculate the path of the new project
		$projectDirectory = getcwd() . '/' . $projectName;
		info("Creating project '$projectName' at '$projectDirectory'");

		// Lets create the project now
		if (!$this->createLaravel($projectName)) {
			alert('Failed to create the laravel project.');
			return 1;
		}

		$this->title("$projectName created in '$projectDirectory'");

		info('Performing infrastructure configuration...');

		// and then setup the nginx site for it
		$this->call('nginx:create', ['domain' => $projectName, 'path' => $projectDirectory]);

		// and then setup the database for it
		$this->call('postgres:create', ['database' => $projectName]);		
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
