<?php

namespace App\Commands\Stack;

use App\Classes\Sudoers;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{error, info, note};

class ManageSudoers extends Command
{
	protected $signature = 'sudoers
		{--remove : Remove the rules, so these commands ask for your password again}';

	protected $description = 'Let winux check and reload nginx and PHP-FPM without asking for your password (safe to run again)';

	public function handle(): int
	{
		if ($this->option('remove')) {
			if (!Sudoers::remove()) {
				error("Couldn't remove " . Sudoers::path() . '.');
				return self::FAILURE;
			}

			Sudoers::setDeclined(true);
			info('Removed ' . Sudoers::path() . '. winux will ask for your password again (and doctor won\'t suggest the rules).');
			return self::SUCCESS;
		}

		if (!Sudoers::install()) {
			return self::FAILURE;
		}
		Sudoers::setDeclined(false);

		info('Installed ' . Sudoers::path() . '. These run without a password now:');
		foreach (Sudoers::commands() as $command) {
			$this->line('  sudo ' . implode(' ', $command));
		}
		note('Installing another PHP version updates the list. Remove the rules with `winux sudoers --remove`.');

		return self::SUCCESS;
	}
}
