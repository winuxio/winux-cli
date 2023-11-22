<?php

namespace App\Commands\Nginx;

use App\Classes\Certificate;
use App\Classes\OpenSSL;
use App\Classes\Project;
use App\Classes\Site;
use App\Classes\Winux;
use App\Services\Nginx;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{alert, confirm, error, info, text, warning};

class CreateNginxSite extends Command
{
	/**
	 * The signature of the command.
	 *
	 * @var string
	 */
	// protected $signature = 'nginx:create {domain} {path}';
	protected $signature = 'nginx:create {projectName : The name of your new project that will be created in the local directory}'; // {--type=laravel}';

	/**
	 * The description of the command.
	 *
	 * @var string
	 */
	protected $description = 'Create and configure an Nginx site for a Laravel project';

	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function handle(): mixed
	{
		$projectName = $this->argument('projectName');
		// $projectType = $this->option('type');

		Winux::SetupEnvironment();

		$project = Project::create($projectName);

		info("Creating project '{$project->name}' at '{$project->path}'");

		info('Performing infrastructure configuration...');

		if (!$project->setupNginx()) {
			alert('Failed to create the nginx site.');
			return Command::FAILURE;
		}

		Nginx::make()->reload();

		return Command::SUCCESS;


		// $domain = $this->argument('domain');
		// $path = $this->argument('path');

		// // Temp check to make sure Root CA generation works
		// OpenSSL::init();
		// // OpenSSL::generateRootAuthority();

		// // info('Root CA generated successfully!');
		// // return 0;

		// $site = Site::create($domain, $path);
		// $config = $site->generate();

		// info('Generated Nginx Configuration:');
		// $finalOutput = Site::configDiff($config);
		// $this->line($finalOutput);

		// if (!confirm('Do you want to save this configuration?', true)) {
		// 	warning('Configuration not saved.');
		// 	return 1;
		// }

		// if ($this->saveConfig($site, $config)) {
		// 	// Ask if we should symlink if the config file was updated
		// 	if (confirm('Do you want to enable this site?', true, hint: 'This will create a symlink in sites-enabled.') && !$site->enable()) {
		// 		return 1;
		// 	}
		// }

		// Certificate::create($domain);

		// // Restart Nginx
		// if (confirm('Do you want to restart Nginx?', true) && $this->call(RestartNginx::class) !== 0) {
		// 	return 1;
		// }

		// return 0;
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

	public function saveConfig(Site $site, string $config): bool
	{
		if (File::exists($site->configFile) && !confirm("File {$site->configFile} already exists. Do you want to overwrite it?")) {
			warning('Configuration NOT overwritten.');
			return false;
		}

		// dump('Saving configuration (saveConfig)...');
		// dump($config);
		if (!$site->save($config)) {
			error('Failed to save the configuration. Permission denied.');
			return false;
		}

		info('Configuration saved successfully!');
		return true;
	}
}
