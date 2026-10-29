<?php

namespace App\Commands\Nginx;

use App\Classes\Project;
use App\Classes\Winux;
use App\Services\Nginx;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{alert, error, info};

class CreateNginxSite extends Command
{
	protected $signature = 'nginx:create
		{projectName : The project directory (in the current directory) to serve}
		{--php= : PHP version (defaults to `winux php:default`)}';

	protected $description = 'Create an nginx site + certificate for a project, without touching its database or .env';

	public function handle(): int
	{
		Winux::SetupEnvironment();

		if ($reason = Project::validationError($this->argument('projectName'))) {
			error($reason);
			return self::FAILURE;
		}

		$project = Project::create($this->argument('projectName'), $this->option('php'));

		if (!$project->setupNginx()) {
			alert('Failed to create the nginx site.');
			return self::FAILURE;
		}

		if (!Nginx::make()->reload()) {
			error('nginx failed to reload, so the site is not live yet. Check `sudo nginx -t`, then run `winux nginx:restart`.');
			return self::FAILURE;
		}

		info("{$project->name} is live at https://{$project->getDomain()} (PHP {$project->phpVersion})");
		return self::SUCCESS;
	}
}
