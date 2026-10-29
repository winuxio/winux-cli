<?php

namespace App\Commands\Concerns;

use App\Classes\Hosts;
use App\Classes\Project;
use App\Services\Nginx;
use App\Services\PhpFpm;
use App\Services\Postgres;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\{confirm, error, info, warning};

/**
 * Shared --php / --pg / --domain handling for project:create and project:import.
 */
trait PreparesProject
{
	use ManagesHostsEntries;

	protected function prepareProject(string $name): ?Project
	{
		if ($reason = Project::validationError($name)) {
			error($reason);
			return null;
		}

		$project = Project::create($name, $this->option('php'), $this->option('pg'));

		if (!PhpFpm::isValidVersion($project->phpVersion)) {
			error("'{$project->phpVersion}' isn't a PHP version (expected something like 8.4).");
			return null;
		}

		$fpm = PhpFpm::make($project->phpVersion);
		if (!$fpm->isInstalled()) {
			warning("PHP {$project->phpVersion} FPM isn't installed.");
			if (!confirm("Install PHP {$project->phpVersion} now?", true) || !$fpm->install()) {
				return null;
			}
		}

		// An imported project whose .env already connects (even to a non-winux Postgres) needs nothing installed
		if (!$project->envDatabaseWorks() && !Postgres::make($project->postgresVersion)->isInstalled()) {
			error("Postgres {$project->postgresVersion} isn't installed. Run `winux service:install postgres {$project->postgresVersion}` or pass --pg.");
			return null;
		}

		$domains = array_map('strtolower', $this->option('domain'));
		foreach ($domains as $domain) {
			if (!Hosts::isValidDomain($domain)) {
				error("'$domain' isn't a valid domain name.");
				return null;
			}
		}
		$project->extraDomains = $domains;

		return $project;
	}

	/**
	 * Reloads nginx so the new site is served. Reports the failure rather than claiming it's live.
	 */
	protected function reloadNginx(): bool
	{
		if (Nginx::make()->reload()) {
			return true;
		}

		error('nginx failed to reload, so the site is not live yet. Check `sudo nginx -t`, then run `winux nginx:restart`.');
		return false;
	}

	protected function reportExtraDomains(Project $project): void
	{
		if (empty($project->extraDomains)) {
			return;
		}

		info('Also answering to: ' . implode(', ', $project->extraDomains));
		$this->offerHostsEntries($project->extraDomains);
	}

	protected function offerMigrations(Project $project): void
	{
		if (!$this->input->isInteractive() || !confirm('Run database migrations now?', true)) {
			return;
		}

		$process = Process::path($project->path)->forever()->run(
			[$project->phpBinary(), 'artisan', 'migrate', '--force'],
			fn ($type, $buffer) => $this->output->write($buffer)
		);

		if ($process->failed()) {
			warning('Migrations failed; fix the issue and run `php artisan migrate` yourself.');
		}
	}
}
