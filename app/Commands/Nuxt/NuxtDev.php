<?php

namespace App\Commands\Nuxt;

use App\Classes\Certificate;
use App\Classes\OpenSSL;
use App\Classes\Project;
use App\Enums\Frameworks;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{info, alert, confirm, error};

class NuxtDev extends Command
{
	/**
	 * The signature of the command.
	 *
	 * @var string
	 */
	protected $signature = 'nuxt:dev';

	/**
	 * The description of the command.
	 *
	 * @var string
	 */
	protected $description = 'Execute npm run dev but taking into account SSL certificates';

	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function handle()
	{
		// $projectName = $this->argument('projectName');
		// Get the current directory name
		$projectName = basename(getcwd());
		$project = Project::create($projectName);
		$domain = $project->getDomain();
		// dd($projectName);

		info("Running Nuxt dev server for <fg=bright-yellow>{$project->name}</> @ <fg=cyan>https://{$domain}:3000</>");

		// NODE_TLS_REJECT_UNAUTHORIZED=0 npm run dev -- --https --ssl-cert /home/chris/.winux/ssl/custom/nuxt-chat.local.winux.io/nuxt-chat.local.winux.io.crt --ssl-key /home/chris/.winux/ssl/custom/nuxt-chat.local.winux.io/nuxt-chat.local.winux.io.key
		$sslPath = Frameworks::Nuxt->sslPath($domain);
		$sslCert = join(DIRECTORY_SEPARATOR, [$sslPath, $domain . '.crt']);
		$sslKey = join(DIRECTORY_SEPARATOR, [$sslPath, $domain . '.key']);
		$command = "NODE_TLS_REJECT_UNAUTHORIZED=0 npm run dev -- --https --ssl-cert {$sslCert} --ssl-key {$sslKey}";
		// dd($sslCert, $sslKey);
		// dd($command);
		$process = Process::run($command, function ($type, $buffer) {
			$this->output->write($buffer);
		});

		if ($process->failed()) {
			alert('Failed to run: ' . $command);
			error($process->errorOutput());
			return false;
		}
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
