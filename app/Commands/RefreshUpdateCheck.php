<?php

namespace App\Commands;

use App\Classes\UpdateCheck;
use LaravelZero\Framework\Commands\Command;
use Throwable;

/**
 * Run in the background by UpdateCheck once a day.
 */
class RefreshUpdateCheck extends Command
{
	protected $signature = 'update:refresh';

	protected $description = 'Check GitHub for a newer winux release';

	protected $hidden = true;

	public function handle(): int
	{
		try {
			UpdateCheck::refresh();
		} catch (Throwable) {
			return self::FAILURE;
		}

		return self::SUCCESS;
	}
}
