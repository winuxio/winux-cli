<?php

namespace App\Commands\Nginx;

use App\Classes\Site;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{confirm, error, info, text, warning};

class CreateNginxSite extends Command
{
	/**
	 * The signature of the command.
	 *
	 * @var string
	 */
	protected $signature = 'nginx:create {domain} {path}';

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
		$domain = $this->argument('domain');
		$path = $this->argument('path');

		$site = Site::create($domain, $path);
		$config = $site->generate();

		info('Generated Nginx Configuration:');
		$finalOutput = Site::configDiff($config);
		$this->line($finalOutput);

		if (!confirm('Do you want to save this configuration?', true)) {
			warning('Configuration not saved.');
			return 1;
		}

		if ($this->saveConfig($site, $config)) {
			// Ask if we should symlink if the config file was updated
			if (confirm('Do you want to enable this site?', true, hint: 'This will create a symlink in sites-enabled.') && !$site->enable()) {
				return 1;
			}
		}

		// Restart Nginx
		if (confirm('Do you want to restart Nginx?', true) && $this->call(RestartNginx::class) !== 0) {
			return 1;
		}

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

	public function saveConfig(Site $site, string $config): bool
	{
		if (File::exists($site->configFile) && !confirm("File {$site->configFile} already exists. Do you want to overwrite it?")) {
			warning('Configuration NOT overwritten.');
			return false;
		}

		if (!$site->save($config)) {
			error('Failed to save the configuration. Permission denied.');
			return false;
		}

		info('Configuration saved successfully!');
		return true;
	}
}
