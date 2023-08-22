<?php

namespace App\Commands\Projects;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
// use Symfony\Component\Process\Exception\ProcessFailedException;
// use Symfony\Component\Process\Process;

use function Laravel\Prompts\info;

class CreateProject extends Command
{
	/**
	 * The signature of the command.
	 *
	 * @var string
	 */
	protected $signature = 'project:create {projectName : The name of your new project that will be created in the local directory}'; // {--type=laravel}';

	/**
	 * The description of the command.
	 *
	 * @var string
	 */
	protected $description = 'Create a new project';

	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function handle()
	{
		$projectName = $this->argument('projectName');
		// $projectType = $this->option('type');

		info('Creating project: ' . $projectName);
		$this->createLaravel($projectName);

		// get the path of the new project

		// and then setup the nginx site for it

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

	public function createLaravel(string $projectName): void
	{
		// Ensure the laravel installer is installed.
		// Check if the laravel installer is installed.
		$process = Process::run(['laravel', '--version']);
		if ($process->failed()) {
			$process = Process::run(['composer', 'global', 'require', 'laravel/installer']);
			if ($process->failed()) {
				throw new ProcessFailedException($process);
			}
		}

		$process = Process::run(['laravel', 'new', $projectName], function ($type, $buffer) {
			if ($type === 'out') {
				// $this->line($buffer);
				$this->output->write($buffer);
			} else if ($type === 'err') {
				$this->error($buffer);
			}
		});

		if ($process->failed()) {
			throw new ProcessFailedException($process);
		}
	}
}
