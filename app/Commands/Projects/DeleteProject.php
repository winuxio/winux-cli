<?php

namespace App\Commands\Projects;

use App\Classes\Certificate;
use App\Classes\Project;
use App\Classes\Site;
use App\Services\Nginx;
use App\Services\Postgres;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{alert, error, info, confirm, text, warning};

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

		$project = Project::create($projectName);
		info("Preparing to delete {$project->name} at {$project->path}");

		if (!is_dir($project->path)) {
			error("Project '{$project->name}' does not exist.");
			return Command::FAILURE;
		}

		if (!confirm("Are you sure you want to delete project '{$project->name}' at '{$project->path}'?")) {
			warning('Project not deleted.');
			return Command::FAILURE;
		}

		$confirmationEntry = text("If you're absolutely sure you want to delete this project, type '{$project->name}' below.", placeholder: $projectName);
		if ($project->name !== $confirmationEntry) {
			warning('Names did not match. Project NOT deleted.');
			return Command::FAILURE;
		}

		warning("Deleting project '{$project->name}' at '{$project->path}'");
		// WARNING: This is a VERY destructive operation!
		$project->destroy();

		$this->title("$projectName deleted successfully!");
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
}
