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
		{projectName : The project directory (in the current directory) whose site and certificate are deleted}
		{--force : Skip the confirmations}
		{--delete-database : Also drop the project\'s database and Postgres user (otherwise you are asked, and they are kept by default)}
		{--delete-files : Also delete the project directory (otherwise you are asked, and it is kept by default)}';

	/**
	 * The description of the command.
	 *
	 * @var string
	 */
	protected $description = "Delete a project's site and certificate (and optionally its database and files)";

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
		]);
		$databaseItems = array_filter([
			$database['database'] ? "the database '{$database['database']}' on Postgres {$database['version']}" : null,
			$database['username'] ? "the Postgres user '{$database['username']}'" : null,
		]);

		foreach ($database['notes'] as $line) {
			note($line);
		}

		if (!$hasDirectory) {
			warning("The project directory {$project->path} doesn't exist.");
		}

		if (empty($plan) && empty($databaseItems) && !$hasDirectory) {
			error("Nothing to delete for '{$project->name}'.");
			return self::FAILURE;
		}

		info("Preparing to delete '{$project->name}'" . ($hasDirectory ? " at {$project->path}" : '') . '. This removes:');
		foreach ($plan ?: ['nothing else'] as $item) {
			$this->line("  - $item");
		}

		$optional = array_filter([
			...$databaseItems,
			$hasDirectory ? "the project directory {$project->path}" : null,
		]);
		if ($optional) {
			$this->line('  and, only if you choose to (or pass --delete-database / --delete-files):');
			foreach ($optional as $item) {
				$this->line("  - $item");
			}
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

		$deleteDatabase = false;
		if ($databaseItems) {
			$deleteDatabase = $this->option('delete-database');
			if (!$deleteDatabase && $this->input->isInteractive()) {
				// Prompts truncates long labels, so the details (and the warning) go in the hint
				$deleteDatabase = confirm(
					'Also drop the database?',
					false,
					hint: 'Drops ' . implode(' and ', $databaseItems) . '. Its data is gone for good.',
				);
			}
		}

		if (!$deleteDatabase && $databaseItems) {
			$sameName = in_array($database['username'], [null, $database['database']], true) && $database['database'];
			note('Keeping ' . implode(' and ', $databaseItems) . '.' . ($sameName
				? " Drop them later with `winux postgres:delete {$database['database']} --pg={$database['version']}`."
				: ''));
			$database['database'] = $database['username'] = null;
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
