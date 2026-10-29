<?php

namespace App\Commands\Php;

use App\Services\PhpFpm;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{error, info};

class RestartPhpFpm extends Command
{
	protected $signature = 'php:restart {version? : Only restart this version (default: all installed)}';

	protected $description = 'Restart PHP-FPM';

	public function handle(): int
	{
		$version = $this->argument('version');
		$services = $version ? [PhpFpm::make($version)] : PhpFpm::instances();

		$failed = false;
		foreach ($services as $php) {
			if ($php->restart()) {
				info("{$php->label()} restarted.");
			} else {
				error("Failed to restart {$php->label()}.");
				$failed = true;
			}
		}

		return $failed ? self::FAILURE : self::SUCCESS;
	}
}
