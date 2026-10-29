<?php

namespace App\Commands\Projects;

use App\Classes\Certificate;
use App\Classes\Project;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{alert, error, info, confirm, note, text, warning};

class DeleteProject extends Command
{
	/**
	 * The signature of the command.
	 *
	 * @var string
	 */
	protected $signature = 'project:delete
		{projectName : The project directory (in the current directory) whose site, certificate, and database are deleted}
		{--force : Skip the confirmations}
		{--delete-files : Also delete the project directory (otherwise you are asked, and it is kept by default)}';

	/**
	 * The description of the command.
	 *
	 * @var string
	 */
	protected $description = "Delete a project's site, certificate, and database (and optionally its files)";

	/**
	 * Execute the console command.
	 */
	public function handle(): int
	{
		$projectName = $this->argument('projectName');

		if ($reason = Project::nameError($projectName)) {
			error($reason);
			return self::FAILURE;
		}

		$project = Project::create($projectName);
		$hasDirectory = is_dir($project->path);
		$hasSite = $project->site->exists() || $project->site->isEnabled();
		$hasCertificate = is_dir(Certificate::path($project->getDomain()));
		$database = $project->databaseToDrop();

		// Spell out exactly what will go, so a reused or differently-named database is never a surprise
		$plan = array_filter([
			$hasSite ? "the nginx site {$project->getDomain()}" : null,
			$hasCertificate ? "the certificate for {$project->getDomain()}" : null,
			$database['database'] ? "the database '{$database['database']}' on Postgres {$database['version']}" : null,
			$database['username'] ? "the Postgres user '{$database['username']}'" : null,
		]);

		foreach ($database['notes'] as $line) {
			note($line);
		}

		if (!$hasDirectory) {
			warning("The project directory {$project->path} doesn't exist.");
		}

		if (empty($plan) && !$hasDirectory) {
			error("Nothing to delete for '{$project->name}'.");
			return self::FAILURE;
		}

		info("Preparing to delete '{$project->name}'" . ($hasDirectory ? " at {$project->path}" : '') . '. This removes:');
		foreach ($plan ?: ['nothing but (optionally) the project directory'] as $item) {
			$this->line("  - $item");
		}

		if (!$this->option('force')) {
			if (!confirm('Are you sure?', false)) {
				warning('Project not deleted.');
				return self::FAILURE;
			}

			$confirmationEntry = text("If you're absolutely sure, type '{$project->name}' below.", placeholder: $projectName);
			if ($project->name !== $confirmationEntry) {
				warning('Names did not match. Project NOT deleted.');
				return self::FAILURE;
			}
		}

		$deleteFiles = false;
		if ($hasDirectory) {
			$deleteFiles = $this->option('delete-files');
			if (!$deleteFiles && $this->input->isInteractive()) {
				alert("Answering 'yes' will delete ALL of this project's files. THIS CAN NOT BE UNDONE!");
				$deleteFiles = confirm("Do you want to delete the project directory at '{$project->path}'?", false);
			}
		}

		warning("Deleting project '{$project->name}'");
		// WARNING: This is a VERY destructive operation!
		if (!$project->destroy($database, $deleteFiles)) {
			alert("Some of '{$project->name}' could not be deleted; see the errors above.");
			return self::FAILURE;
		}

		$this->title("$projectName deleted successfully!");
		return self::SUCCESS;
	}
}
