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
    protected $signature = 'create:nginx:site {domain} {path}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
	public function handle()
    {
        $domain = $this->argument('domain');
        $path = $this->argument('path');

        $config = $this->generateConfiguration($domain, $path);

        $this->info('Generated Nginx Configuration:');
        $this->line($config);

		if (!$this->confirm('Do you want to save this configuration?', true)) {
			return 1;
		}

        if (!$this->saveConfiguration($config, $domain)) {
            return 1;
        }

        if ($this->confirm('Do you want to activate this site?', true) && !$this->activateSite($domain)) {
            return 1;
        }

        if ($this->confirm('Do you want to restart Nginx?', true) && !$this->restartNginx()) {
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

	protected function generateConfiguration($domain, $path)
    {
        $templatePath = storage_path('templates/site-template.conf');
        return str_replace(['{{PROJECT_DOMAIN}}', '{{PROJECT_PATH}}'], [$domain, $path], File::get($templatePath));
    }

    protected function saveConfiguration($config, $domain)
    {
        $destFile = "/etc/nginx/sites-available/$domain";
        if (File::exists($destFile) && !$this->confirm("File $destFile already exists. Do you want to overwrite it?")) {
            $this->info('Configuration not saved.');
            return false;
        }

        $process = new Process(['sudo', 'tee', $destFile], null, null, $config);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->error('Failed to save the configuration. Permission denied.');
            return false;
        }

        $this->info('Configuration saved successfully!');
        return true;
    }

	protected function activateSite($domain)
	{
		$link = "/etc/nginx/sites-enabled/$domain";
		$destFile = "/etc/nginx/sites-available/$domain";
	
		// Check if the symbolic link exists
		if (file_exists($link)) {
			// Check if the symbolic link points to the correct file
			if (readlink($link) === $destFile) {
				$this->info('Symbolic link already exists and is configured correctly.');
				return true;
			} else {
				// Remove the existing symbolic link if it doesn't point to the correct file
				unlink($link);
			}
		}
	
		// Create the symbolic link
		$process = new Process(['sudo', 'ln', '-s', $destFile, $link]);
		$process->run();
	
		if (!$process->isSuccessful()) {
			$this->error('Failed to activate the site.');
			return false;
		}
	
		$this->info('Site activated successfully!');
		return true;
	}
	

    protected function restartNginx()
    {
        $process = new Process(['sudo', '/etc/init.d/nginx', 'restart']);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->error('Failed to restart Nginx.');
            return false;
        }

        $this->info('Nginx restarted successfully!');
        return true;
    }
}
