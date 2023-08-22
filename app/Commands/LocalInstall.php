<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\text;

class LocalInstall extends Command
{
	/**
	 * The signature of the command.
	 *
	 * @var string
	 */
	protected $signature = 'app:install:local';

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
		// $this->call('app:build');
		$version = text(label: 'What is the build version?', placeholder: 'unreleased');
		if ($version === '')
			$version = 'unreleased';

		$buildParts = ['php', 'winux', 'app:build', '--build-version=' . $version];
		// $this->call('app:build', ['--build-version' => $version]);
		// return 0;

		$build = Process::run($buildParts);
		if ($build->failed()) {
			error('Failed to build the application.');
			throw new ProcessFailedException($build);
		}

		// Copy the binary to /usr/sbin/winux
		$process = Process::run(['sudo', 'cp', 'builds/winux', '/usr/sbin/winux']);
		if ($process->failed()) {
			error('Failed to copy the binary to /usr/sbin/winux.');
			throw new ProcessFailedException($process);
		}

		// Set the permissions
		$process = Process::run(['sudo', 'chmod', '+x', '/usr/sbin/winux']);
		if ($process->failed()) {
			error('Failed to set permissions.');
			throw new ProcessFailedException($process);
			
		}

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
