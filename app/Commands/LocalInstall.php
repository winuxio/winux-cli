<?php

namespace App\Commands;

use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{error, info, warning, alert, note, text};

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
		$build = Process::forever()->run($buildParts, function ($type, $buffer) {
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
		// Copy the binary to /usr/local/bin/winux
		$process = Process::run(['sudo', 'install', '-m', '0755', 'builds/winux', '/usr/local/bin/winux']);
		if ($process->failed()) {
			error('Failed to copy the binary to /usr/local/bin/winux.');
			throw new ProcessFailedException($process);
		}

		info('Copied the binary to /usr/local/bin/winux successfully!');

		$this->line('======================================');
		info('Winux installed locally successfully!');
	}
}
