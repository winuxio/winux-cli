<?php

namespace App\Commands\Projects;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
// use Symfony\Component\Process\Exception\ProcessFailedException;
// use Symfony\Component\Process\Process;

use function Laravel\Prompts\alert;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class DeleteProject extends Command
{
	/**
	 * The signature of the command.
	 *
	 * @var string
	 */
	protected $signature = 'project:delete {projectName : The name of your new project that will be deleted from the local directory}'; // {--type=laravel}';

	/**
	 * The description of the command.
	 *
	 * @var string
	 */
	protected $description = 'Delete a project';

	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function handle()
	{
		$projectName = $this->argument('projectName');
		// $projectType = $this->option('type');

		// Calculate the path of the new project
		$projectDirectory = getcwd() . '/' . $projectName;

		info("Preparing to delete $projectName at $projectDirectory");

		if (!is_dir($projectDirectory)) {
			error("Project '$projectName' does not exist.");
			return 1;
		}

		if (!confirm("Are you sure you want to delete project '$projectName' at '$projectDirectory'?")) {
			warning('Project not deleted.');
			return 1;
		}

		if ($projectName !== text("If you're absolutely sure you want to delete this project, type '$projectName' below.", placeholder: $projectName)) {
			warning('Project not deleted.');
			return 1;
		}

		alert("Deleting project '$projectName' at '$projectDirectory'");


		// Delete the NGINX site for this project
		$this->call('nginx:delete', ['domain' => $projectName]);

		// and then tear down the database and user
		$this->call('postgres:delete', ['database' => $projectName]);
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
