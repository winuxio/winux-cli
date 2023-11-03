<?php

namespace App\Commands\Projects;

use App\Classes\Certificate;
use App\Classes\OpenSSL;
use App\Classes\Project;
use App\Classes\Site;
use App\Classes\Winux;
use App\Enums\Editors;
use App\Enums\GitHubVisibility;
use App\Enums\Packages;
use App\Services\Nginx;
use App\Services\Postgres;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{alert, confirm, error, info, select, multiselect};

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

		$project = Project::create($projectName);

		info("Creating project '{$project->name}' at '{$project->path}'");

		// Lets create the project now
		if (!$project->installFramework()) {
			alert('Failed to install the project\s framework.');
			return 1;
		}
		info("Framework files installed for $projectName");

		info('Performing infrastructure configuration...');

		if (!$project->setupNginx()) {
			alert('Failed to create the nginx site.');
			return Command::FAILURE;
		}

		Nginx::make()->reload();

		if (!$project->setupDatabase()) {
			alert('Failed to create all required postgres resources.');
			return Command::FAILURE;
		}

		if (!$project->initEnv()) {
			alert('Failed to create the .env file.');
			return Command::FAILURE;
		}

		info("{$project->name}'s .env file updated successfully!");

		$this->title('Project created successfully!');

		if (confirm('Initialize Git Repository and make initial commit?', true)) {
			$project->initializeGitRepository();

			if (confirm('Do you want to add the project to your GitHub account?', true)) {
				$visibility = GitHubVisibility::from(
					select('Public or Private Repository?', GitHubVisibility::selectValues())
				);

				$project->createGitHubRepo($visibility);
			}
		}

		$project->installAdditionalPackages();

		$ide = null;
		if (confirm('Do you want to open the project in your IDE?', true)) {
			$ide = select(
				'Which IDE do you want to use?',
				collect(Editors::cases())
					->mapWithKeys(fn ($editor) => [$editor->value => $editor->getLabel()])
					->toArray()
			);
			$ide = Editors::from($ide);
		}

		if (confirm('Do you want to open the project in your browser?', true)) {
			$project->openBrowser();
		}

		if ($ide) {
			$project->openEditor($ide);
		}

		info("{$project->name} is live at https://{$project->getDomain()}");
		info("Switch into the new project directory with `cd $projectName` if needed.");

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
}
