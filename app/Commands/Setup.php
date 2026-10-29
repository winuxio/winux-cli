<?php

namespace App\Commands;

use App\Classes\AptRepository;
use App\Classes\Platform;
use App\Classes\ServiceUser;
use App\Classes\Settings;
use App\Services\PhpFpm;
use App\Services\Postgres;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{alert, confirm, error, info, multiselect, select};

class Setup extends Command
{
	protected $signature = 'setup
		{--php=* : PHP versions to install (default: prompt)}
		{--pg= : Postgres version to install (default: prompt)}
		{--skip-windows : Only trust the root CA inside Linux/WSL, not in Windows}';

	protected $description = 'Set up this machine: repositories, Nginx, PHP, Postgres, Redis, and SSL';

	public function handle(): int
	{
		if (!Platform::isSupported()) {
			alert(Platform::prettyName() . ' is not supported. winux supports Ubuntu and Debian.');
			return self::FAILURE;
		}

		if (!Platform::hasSystemd()) {
			error('systemd is not running.');
			if (Platform::isWsl()) {
				info("Add the following to /etc/wsl.conf, run `wsl --shutdown` from Windows, then reopen your terminal:\n\n[boot]\nsystemd=true\n");
			}
			if (!confirm('Continue without systemd?', false)) {
				return self::FAILURE;
			}
		}

		// Checked before anything is installed
		foreach ($this->option('php') as $version) {
			if (!PhpFpm::isValidVersion($version)) {
				error("'$version' isn't a PHP version (expected something like 8.4).");
				return self::FAILURE;
			}
		}
		if (($pg = $this->option('pg')) !== null && !preg_match('/^\d+$/', $pg)) {
			error("'$pg' isn't a Postgres version (expected something like 16).");
			return self::FAILURE;
		}

		if (!ServiceUser::resolve()) {
			return self::FAILURE;
		}

		if (!AptRepository::ensurePhp() || !AptRepository::ensurePostgres()) {
			return self::FAILURE;
		}

		$phpVersions = $this->option('php') ?: multiselect(
			label: 'Which PHP versions do you want?',
			options: PhpFpm::availableVersions(),
			default: [Settings::phpVersion()],
			required: true,
		);
		$defaultPhp = count($phpVersions) === 1
			? $phpVersions[0]
			: select('Default PHP version for new sites', $phpVersions, default: in_array(Settings::phpVersion(), $phpVersions, true) ? Settings::phpVersion() : end($phpVersions));

		$pgVersion = $this->option('pg') ?: select(
			label: 'Which Postgres version?',
			options: config('winux.postgres.supported'),
			default: Settings::postgresVersion(),
			hint: 'Match production where you can.',
		);

		$steps = [
			'Nginx' => fn () => $this->call('service:install', ['service' => 'nginx']) === self::SUCCESS,
			...collect($phpVersions)->mapWithKeys(fn ($version) => [
				"PHP $version" => fn () => PhpFpm::make($version)->isInstalled() || PhpFpm::make($version)->install(),
			])->all(),
			"Postgres $pgVersion" => fn () => Postgres::make($pgVersion)->isInstalled() || $this->call('service:install', ['service' => 'postgres', 'version' => $pgVersion]) === self::SUCCESS,
			'Redis' => fn () => $this->call('service:install', ['service' => 'redis']) === self::SUCCESS,
			// Also covers PHP versions that were already installed (and so skipped above)
			'Run nginx and PHP-FPM as you' => fn () => $this->call('service:configure') === self::SUCCESS,
			'SSL' => fn () => $this->call('ssl:root:install', array_filter(['--skip-windows' => $this->option('skip-windows')])) === self::SUCCESS,
		];

		$failed = [];
		foreach ($steps as $name => $step) {
			info("==> $name");
			if (!$step()) {
				$failed[] = $name;
			}
		}

		Postgres::flushClusterCache();

		// Only versions that actually got installed become the defaults
		if (PhpFpm::make($defaultPhp)->isInstalled()) {
			Settings::set('php.default', $defaultPhp);
		}
		if (Postgres::make($pgVersion)->isInstalled()) {
			Settings::set('postgres.default', $pgVersion);
		}

		if ($failed) {
			error('Setup finished with failures: ' . implode(', ', $failed));
		}

		$this->call('doctor');

		return $failed ? self::FAILURE : self::SUCCESS;
	}
}
