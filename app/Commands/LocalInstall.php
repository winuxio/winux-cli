<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\alert;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;

class LocalInstall extends Command
{
	/**
	 * The signature of the command.
	 *
	 * @var string
	 */
	protected $signature = 'app:install:local {--build-version=}';

	/**
	 * The description of the command.
	 *
	 * @var string
	 */
	protected $description = 'Install the application locally';

	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function handle()
	{
		// Build the App
		$version = $this->option('build-version');
		if (empty($version)) {
			// $this->call('app:build');
			$version = text(label: 'What is the build version?', placeholder: 'unreleased');
			if ($version === '') {
				$version = 'unreleased';
			}
		}
		$buildParts = ['php', 'winux', 'app:build', '--build-version=' . $version];
		// $this->call('app:build', ['--build-version' => $version]);
		// $this->call('app:build');
		// return 0;

		alert('Building the application... Please wait...');
		$build = Process::run($buildParts, function ($type, $buffer) {
			if ($type === 'out') {
				// $this->line($buffer);
				$this->output->write($buffer);
			} else if ($type === 'err') {
				error($buffer);
			}
		});
		if ($build->failed()) {
			error('Failed to build the application.');
			throw new ProcessFailedException($build);
		}

		info('Application built successfully!');

		// return 0;
		// Copy the binary to /usr/sbin/winux
		$process = Process::run(['sudo', 'cp', 'builds/winux', '/usr/sbin/winux']);
		if ($process->failed()) {
			error('Failed to copy the binary to /usr/sbin/winux.');
			throw new ProcessFailedException($process);
		}

		info('Copied the binary to /usr/sbin/winux successfully!');

		// Set the permissions
		$process = Process::run(['sudo', 'chmod', '+x', '/usr/sbin/winux']);
		if ($process->failed()) {
			error('Failed to set permissions.');
			throw new ProcessFailedException($process);
		}

		info('Permissions configured.');
		$this->line('======================================');
		info('Winux installed locally successfully!');
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
}
