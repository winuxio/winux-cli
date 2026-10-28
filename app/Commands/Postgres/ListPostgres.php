<?php

namespace App\Commands\Postgres;

use App\Classes\AptRepository;
use App\Services\Postgres;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{info, note, table};

class ListPostgres extends Command
{
	protected $signature = 'postgres:list';

	protected $description = 'List installed Postgres versions and their ports';

	public function handle(): int
	{
		$clusters = Postgres::clusters();
		if (empty($clusters)) {
			info('No Postgres clusters found. Install one with `winux service:install postgres ' . Postgres::defaultVersion() . '`.');
			return self::SUCCESS;
		}

		$default = Postgres::defaultVersion();
		table(
			['Version', 'Port', 'Status', 'Default'],
			collect($clusters)->map(fn ($cluster, $version) => [
				(string) $version,
				(string) $cluster['port'],
				$cluster['status'],
				(string) $version === $default ? '*' : '',
			])->values()->all()
		);

		if (!AptRepository::hasPostgres()) {
			note('The PGDG repository is not configured; only your distro\'s Postgres version is installable.');
		}

		return self::SUCCESS;
	}
}
