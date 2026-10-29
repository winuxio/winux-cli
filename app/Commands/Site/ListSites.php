<?php

namespace App\Commands\Site;

use App\Classes\Site;
use App\Services\PhpFpm;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{info, table};

class ListSites extends Command
{
	protected $signature = 'site:list';

	protected $description = 'List sites with their domains, PHP version, and project path';

	public function handle(): int
	{
		$sites = Site::all();
		if (empty($sites)) {
			info('No sites yet. Create one with `winux project:create <name>`.');
			return self::SUCCESS;
		}

		$installed = PhpFpm::installedVersions();

		table(
			['Site', 'Enabled', 'PHP', 'Extra domains', 'Path'],
			collect($sites)->map(function (Site $site) use ($installed) {
				$php = $site->phpVersion() ?? '-';
				if ($php !== '-' && !in_array($php, $installed, true)) {
					$php .= ' (not installed)';
				}

				return [
					$site->domain,
					$site->isEnabled() ? 'yes' : 'no',
					$php,
					implode("\n", $site->extraDomains()) ?: '-',
					$site->projectPath() ?? '-',
				];
			})->all()
		);

		return self::SUCCESS;
	}
}
