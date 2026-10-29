<?php

namespace App\Commands\Stack;

use App\Classes\ServiceUser;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\note;

class ConfigureStack extends Command
{
	protected $signature = 'service:configure';

	protected $description = 'Run nginx and every installed PHP-FPM version as you (safe to run again)';

	public function handle(): int
	{
		$changed = ServiceUser::misconfigured();

		if (!ServiceUser::configure()) {
			return self::FAILURE;
		}

		// Only worth mentioning when services used to run as someone else (e.g. www-data)
		if ($changed) {
			note('Projects may already have files written by www-data (in storage/, uploads, ...). Hand them back once with `winux site:permissions --all`.');
		}

		return self::SUCCESS;
	}
}
