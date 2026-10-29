<?php

namespace App\Commands\Projects;

use App\Classes\Winux;
use App\Commands\Concerns\PreparesProject;
use App\Enums\Editors;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{alert, confirm, error, info, select, warning};

class ImportProject extends Command
{
	use PreparesProject;

	protected $signature = 'project:import
		{projectName? : The project to configure: a directory in the current one, a path, or . (the default) for the current directory}
		{--php= : PHP version (defaults to `winux php:default`)}
		{--pg= : Postgres version (defaults to the one matching DB_PORT in .env, then `winux postgres:default`)}
		{--domain=* : Extra domains the site should also answer to}
		{--migrate : Run migrations without asking (with --no-interaction they are skipped otherwise)}';

	protected $description = 'Configure nginx, SSL, a database, and .env for an existing project (reusing its database when .env already works)';

	public function handle(): int
	{
		$target = $this->resolveTarget($this->argument('projectName'));
		if (!$target) {
			return self::FAILURE;
		}

		Winux::SetupEnvironment();

		$project = $this->prepareProject(...$target);
		if (!$project) {
			return self::FAILURE;
		}

		if (!is_dir($project->path)) {
			error("No project directory at {$project->path}.");
			return self::FAILURE;
		}

		// .env is configured from .env.example when it's missing; with neither, this isn't a Laravel app to import
		if (!is_file("{$project->path}/.env") && !is_file("{$project->path}/.env.example")) {
			error("{$project->path} has no .env or .env.example. Is it a Laravel project?");
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

		// A fresh checkout has no vendor/ and (from .env.example) no APP_KEY; without them every request fails
		if (!$project->installDependencies()) {
			alert("The site is configured, but the app can't run until its Composer dependencies are installed.");
			return self::FAILURE;
		}

		if (!$project->ensureAppKey()) {
			alert("The site is configured, but the app can't run without an APP_KEY. Run `php artisan key:generate` in {$project->path}.");
			return self::FAILURE;
		}

		$migrated = $this->offerMigrations($project, $this->option('migrate') ? true : null);

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

		if ($migrated) {
			info("{$project->name} is live at https://{$project->getDomain()}");
		} else {
			warning("{$project->name} is served at https://{$project->getDomain()}, but its migrations failed (see above).");
		}
		$this->reportExtraDomains($project);

		return self::SUCCESS;
	}

	/**
	 * The project name and the directory holding it. A bare name is a directory in the current one;
	 * `.` (or nothing) and paths like ../app or ~/dev/app are resolved to the real directory.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	protected function resolveTarget(?string $target): ?array
	{
		$target ??= '.';
		if (!str_contains($target, '/') && !in_array($target, ['.', '..', '~'], true)) {
			return [$target, getcwd()];
		}

		$expanded = preg_replace('#^~(?=/|$)#', rtrim((string) config('winux.paths.home'), '/'), $target);
		$path = realpath($expanded);
		if ($path === false || !is_dir($path)) {
			error("No project directory at $target.");
			return null;
		}
		if ($path === '/') {
			error("Can't import / as a project.");
			return null;
		}

		return [basename($path), dirname($path)];
	}
}
