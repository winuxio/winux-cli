<?php

namespace App\Commands\Nginx;

use App\Classes\Site;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use LaravelZero\Framework\Commands\Command;
use function Laravel\Prompts\text;
use Symfony\Component\Process\Process;

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

		$this->info('Generated Nginx Configuration:');
		$finalOutput = Site::configDiff($config);
		$this->line($finalOutput);

		if (!$this->confirm('Do you want to save this configuration?', true)) {
			$this->warn('Configuration not saved.');
			return 1;
		}

		if ($this->saveConfig($site, $config)) {
			// Ask if we should symlink if the config file was updated
			if ($this->confirm('Do you want to enable this site?', true) && !Site::enable($domain)) {
				return 1;
			}
		}

		// Restart Nginx
		if ($this->confirm('Do you want to restart Nginx?', true) && $this->call(RestartNginx::class) !== 0) {
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
		if (File::exists($site->path) && !$this->confirm("File {$site->path} already exists. Do you want to overwrite it?")) {
			$this->warn('Configuration NOT overwritten.');
			return false;
		}

		if (!$site->save($config)) {
			$this->error('Failed to save the configuration. Permission denied.');
			return false;
		}

		$this->info('Configuration saved successfully!');
		return true;
	}
}
