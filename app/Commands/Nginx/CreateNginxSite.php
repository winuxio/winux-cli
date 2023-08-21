<?php

namespace App\Commands\Nginx;

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

		$templatePath = storage_path('templates/site-template.conf');
		$templateConfig = File::get($templatePath);
		$config = str_replace(['{{PROJECT_DOMAIN}}', '{{PROJECT_PATH}}'], [$domain, $path], $templateConfig);

		$this->info('Generated Nginx Configuration:');
		// $this->line($config);
		// Run a diff between the $templateConfig and the generated $config and display it to the user
		$process = new Process(['diff', '--color=always', '--unified=1', $templatePath, '-'], null, null, $config);
		$process->run();
		$diffOutput = $process->getOutput();
		$lines = explode("\n", $diffOutput);

		// Skip the header lines, including the line starting with @@
		$changes = array_slice($lines, 3);

		// Combine the filtered lines back into a string
		$finalOutput = implode("\n", $changes);
		$this->line($finalOutput);

		if (!$this->confirm('Do you want to save this configuration?', true)) {
			$this->warn('Configuration not saved.');
			return 1;
		}

		$destFile = "/etc/nginx/sites-available/$domain";
		if ($this->saveConfig($destFile, $config)) {
			// Ask if we should symlink if the config file was updated
			if ($this->confirm('Do you want to enable this site?', true) && $this->call(EnableNginxSite::class, ['domain' => $domain]) !== 0) {
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

	public function saveConfig(string $path, string $config): bool
	{
		if (File::exists($path) && !$this->confirm("File $path already exists. Do you want to overwrite it?")) {
			$this->warn('Configuration NOT overwritten.');
			return false;
		}

		$process = new Process(['sudo', 'tee', $path], null, null, $config);
		$process->run();

		if (!$process->isSuccessful()) {
			$this->error('Failed to save the configuration. Permission denied.');
			return false;
		}

		$this->info('Configuration saved successfully!');
		return true;
	}
}
