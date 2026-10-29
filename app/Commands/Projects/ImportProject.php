<?php

namespace App\Commands\Projects;

use App\Classes\Winux;
use App\Commands\Concerns\PreparesProject;
use App\Enums\Editors;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{alert, confirm, error, info, select};

class ImportProject extends Command
{
	use PreparesProject;

	protected $signature = 'project:import
		{projectName : The project directory (in the current directory) to configure}
		{--php= : PHP version (defaults to `winux php:default`)}
		{--pg= : Postgres version (defaults to the one matching DB_PORT in .env, then `winux postgres:default`)}
		{--domain=* : Extra domains the site should also answer to}';

	protected $description = 'Configure nginx, SSL, a database, and .env for an existing project (reusing its database when .env already works)';

	public function handle(): int
	{
		$projectName = $this->argument('projectName');

		Winux::SetupEnvironment();

		$project = $this->prepareProject($projectName);
		if (!$project) {
			return self::FAILURE;
		}

		if (!is_dir($project->path)) {
			error("No project directory at {$project->path}.");
			return self::FAILURE;
		}

		info("Importing project '{$project->name}' at '{$project->path}' (PHP {$project->phpVersion}, Postgres {$project->postgresVersion})");

		if (!$project->setupNginx()) {
			alert('Failed to create the nginx site.');
			return self::FAILURE;
		}

		if (!$this->reloadNginx()) {
			return self::FAILURE;
		}

		if (!$project->setupImportedDatabase()) {
			alert('Failed to create all required postgres resources.');
			return self::FAILURE;
		}

		if (!$project->configureEnv()) {
			alert('Failed to update the .env file.');
			return self::FAILURE;
		}

		info("{$project->name}'s .env file updated successfully!");

		$this->offerMigrations($project);

		$this->title('Project imported successfully!');

		if ($this->input->isInteractive() && confirm('Do you want to open the project in your IDE?', true)) {
			$ide = select(
				'Which IDE do you want to use?',
				collect(Editors::cases())->mapWithKeys(fn ($editor) => [$editor->value => $editor->getLabel()])->all()
			);
			$project->openEditor(Editors::from($ide));
		}

		if ($this->input->isInteractive() && confirm('Do you want to open the project in your browser?', true)) {
			$project->openBrowser();
		}

		info("{$project->name} is live at https://{$project->getDomain()}");
		$this->reportExtraDomains($project);

		return self::SUCCESS;
	}
}
