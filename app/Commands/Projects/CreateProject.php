<?php

namespace App\Commands\Projects;

use App\Classes\Project;
use App\Classes\Winux;
use App\Commands\Concerns\PreparesProject;
use App\Enums\Editors;
use App\Enums\GitHubVisibility;
use App\Services\Nginx;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{alert, confirm, info, select};

class CreateProject extends Command
{
	use PreparesProject;

	/**
	 * The signature of the command.
	 *
	 * @var string
	 */
	protected $signature = 'project:create
		{projectName : The name of your new project that will be created in the local directory}
		{--php= : PHP version (defaults to `winux php:default`)}
		{--pg= : Postgres version (defaults to `winux postgres:default`)}
		{--domain=* : Extra domains the site should also answer to}';

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

		Winux::SetupEnvironment();

		$project = $this->prepareProject($projectName);
		if (!$project) {
			return Command::FAILURE;
		}

		info("Creating project '{$project->name}' at '{$project->path}' (PHP {$project->phpVersion}, Postgres {$project->postgresVersion})");

		// Lets create the project now
		if (!$project->installFramework()) {
			alert('Failed to install the project\'s framework.');
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

		$this->offerMigrations($project);

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
		$this->reportExtraDomains($project);
		info("Switch into the new project directory with `cd $projectName` if needed.");

		return 0;
	}

}
