<?php

namespace App\Commands\Projects;

use App\Classes\Project;
use App\Classes\Scaffold;
use App\Classes\Winux;
use App\Commands\Concerns\PreparesProject;
use App\Enums\Editors;
use App\Enums\GitHubVisibility;
use App\Enums\Packages;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{alert, confirm, error, info, multiselect, note, select, text, warning};

class CreateProject extends Command
{
	use PreparesProject;

	/**
	 * The signature of the command.
	 *
	 * @var string
	 */
	protected $signature = 'project:create
		{projectName? : The new project: a name (created in the current directory), a path, or . for the current directory (asked for when left out)}
		{--php= : PHP version (defaults to `winux php:default`)}
		{--pg= : Postgres version (defaults to `winux postgres:default`)}
		{--domain=* : Extra domains the site should also answer to}
		{--git : Initialize a git repository and make the initial commit}
		{--github= : Also create a GitHub repository with `gh`: private or public (implies --git)}
		{--package=* : Extra packages to install, e.g. filament}
		{--filament : Install Filament (same as --package=filament)}
		{--migrate : Run migrations without asking (they always run with --no-interaction)}';

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
		if ($projectName === null) {
			if (!$this->input->isInteractive()) {
				error('Pass a project name, or . to create the project in the current directory.');
				return Command::FAILURE;
			}
			$projectName = text('Project name', placeholder: 'my-app', required: true, validate: fn ($name) => Project::nameError($name), hint: 'Creates ' . getcwd() . '/<name>; enter . to use this directory');
		}

		// Inside ~/sites/blog (with its README), `project:create blog` almost always means here, not ~/sites/blog/blog
		$cwd = getcwd();
		if ($projectName === basename($cwd) && $this->input->isInteractive() && Scaffold::entries($cwd)
			&& confirm("You're already in $projectName. Create the project in this directory?", true, hint: "No creates $cwd/$projectName instead.")) {
			$projectName = '.';
		}

		$target = $this->resolveTarget($projectName, mustExist: false);
		if (!$target) {
			return Command::FAILURE;
		}

		// Options are checked before anything is created
		$github = $this->option('github');
		if ($github !== null && !GitHubVisibility::tryFrom($github)) {
			error("--github must be 'private' or 'public', not '$github'.");
			return Command::FAILURE;
		}

		$packages = [];
		foreach ([...$this->option('package'), ...($this->option('filament') ? ['filament'] : [])] as $name) {
			if (!($package = Packages::tryFrom(strtolower($name)))) {
				error("Unknown package '$name'. Choose from: " . implode(', ', array_map(fn ($case) => $case->value, Packages::cases())));
				return Command::FAILURE;
			}
			$packages[$package->value] = $package;
		}

		Winux::SetupEnvironment();

		$project = $this->prepareProject(...$target);
		if (!$project) {
			return Command::FAILURE;
		}

		if (is_file("{$project->path}/artisan") && is_file("{$project->path}/composer.json")) {
			error("{$project->path} is already a Laravel app.");
			note("Set it up with `winux project:import {$project->path}` instead.");
			return Command::FAILURE;
		}

		// A directory with a README, docs, or a .git gets the app added around them
		if ($existing = Scaffold::entries($project->path)) {
			$shown = array_slice($existing, 0, 5);
			$list = implode(', ', $shown) . (count($existing) > count($shown) ? ', …' : '');
			// The question stays short: prompts cut off a long one at the terminal's width
			if ($this->input->isInteractive() && !confirm('Add a Laravel app to this directory?', true, hint: "{$project->path} already has $list")) {
				note('Cancelled. Nothing was created.');
				return Command::FAILURE;
			}
			note("Adding the app around what's already in {$project->path}; existing files are never overwritten.");
		}
		$hadRepository = $project->hasGitRepository();

		// Never another project's database (e.g. my_app's, for my-app); a deleted project's leftovers can be picked back up
		if (!$project->claimExistingDatabase()) {
			return Command::FAILURE;
		}

		info("Creating project '{$project->name}' at '{$project->path}' (PHP {$project->phpVersion}, Postgres {$project->postgresVersion})");

		// Lets create the project now
		if (!$project->installFramework()) {
			alert('Failed to install the project\'s framework.');
			return 1;
		}
		info("Framework files installed for {$project->name}");

		info('Performing infrastructure configuration...');

		if (!$project->setupNginx()) {
			alert('Failed to create the nginx site.');
			return Command::FAILURE;
		}

		if (!$this->reloadNginx()) {
			return Command::FAILURE;
		}

		if (!$project->setupDatabase()) {
			alert('Failed to create all required postgres resources.');
			return Command::FAILURE;
		}

		if (!$project->initEnv()) {
			alert('Failed to create the .env file.');
			return Command::FAILURE;
		}

		info("{$project->name}'s .env file updated successfully!");

		// A brand-new project has nothing to lose, so migrations run by default without a terminal too
		$migrated = $this->offerMigrations($project, $this->option('migrate') ? true : null, default: true);

		$this->title('Project created successfully!');

		$interactive = $this->input->isInteractive();
		$problems = $migrated ? [] : ['migrations failed'];

		// Without a terminal, only what the options ask for happens
		$git = $this->option('git') || $github !== null
			|| ($interactive && confirm($hadRepository ? 'Commit the new app to the existing git repository?' : 'Initialize Git Repository and make initial commit?', true));

		if ($git) {
			$committed = $project->initializeGitRepository();
			// A repository that already pushes somewhere doesn't need a new GitHub repository
			$hasRemote = $project->hasGitRemote();

			if ($github === null && $committed && !$hasRemote && $interactive && confirm('Do you want to add the project to your GitHub account?', true)) {
				$github = select('Public or Private Repository?', GitHubVisibility::selectValues());
			}

			if ($github !== null && $hasRemote) {
				warning('Skipped creating a GitHub repository: this repository already has a remote. Push with `git push`.');
			} elseif ($github !== null) {
				if (!$committed) {
					warning('Skipped creating the GitHub repository: there is no commit to push yet.');
				} elseif (!$project->createGitHubRepo(GitHubVisibility::from($github))) {
					$problems[] = 'the GitHub repository was not created';
				}
			}
		}

		if (!$packages && $interactive) {
			$chosen = multiselect(
				'Install any additional packages?',
				collect(Packages::cases())->mapWithKeys(fn ($package) => [$package->value => "{$package->label()} ({$package->description()})"])->all(),
				scroll: 16,
				hint: 'Press space to select, enter to continue',
			);
			$packages = array_map(fn ($value) => Packages::from($value), $chosen);
		}

		// Packages' migrations run too, unless the app's own failed
		if ($packages && !$project->installAdditionalPackages(Packages::resolve(array_values($packages)), migrate: $migrated)) {
			$problems[] = 'some packages failed to install';
		}

		$ide = null;
		if ($interactive && confirm('Do you want to open the project in your IDE?', true)) {
			$ide = select(
				'Which IDE do you want to use?',
				collect(Editors::cases())
					->mapWithKeys(fn ($editor) => [$editor->value => $editor->getLabel()])
					->toArray()
			);
			$ide = Editors::from($ide);
		}

		if ($interactive && confirm('Do you want to open the project in your browser?', true)) {
			$project->openBrowser();
		}

		if ($ide) {
			$project->openEditor($ide);
		}

		if ($problems) {
			warning("{$project->name} is served at https://{$project->getDomain()}, but " . implode(', ', $problems) . ' (see above).');
		} else {
			info("{$project->name} is live at https://{$project->getDomain()}");
		}
		$this->reportExtraDomains($project);
		if (realpath(getcwd()) !== realpath($project->path)) {
			info("Switch into the new project directory with `cd {$project->path}`.");
		}

		return 0;
	}

}
