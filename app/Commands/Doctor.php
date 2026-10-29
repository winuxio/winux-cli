<?php

namespace App\Commands;

use App\Classes\AptRepository;
use App\Classes\Mkcert;
use App\Classes\Platform;
use App\Classes\Releases;
use App\Classes\ServiceUser;
use App\Classes\Site;
use App\Classes\UpdateCheck;
use App\Services\Nginx;
use App\Services\PhpFpm;
use App\Services\Postgres;
use App\Services\Redis;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{info, table, warning};

class Doctor extends Command
{
	protected $signature = 'doctor';

	protected $description = 'Check this machine is set up correctly for winux';

	/** @var array<int, array{0: string, 1: bool, 2: string, 3: string}> */
	protected array $checks = [];

	public function handle(): int
	{
		$available = UpdateCheck::available();
		$this->check('winux', $available === null, (Releases::current() ?? config('app.version')) . ($available ? " ($available available)" : ''), 'Run `winux self-update`.');

		$this->check('Supported OS', Platform::isSupported(), Platform::prettyName(), 'winux supports Ubuntu and Debian.');
		$this->check('WSL', true, Platform::isWsl() ? 'yes' : 'no (native Linux)');
		$this->check(
			'systemd',
			Platform::hasSystemd(),
			Platform::hasSystemd() ? 'running' : 'not running',
			Platform::isWsl() ? "Add [boot] systemd=true to /etc/wsl.conf, then run `wsl --shutdown` from Windows." : ''
		);

		$this->check('PHP repository', AptRepository::hasPhp(), AptRepository::hasPhp() ? 'configured' : 'missing', 'Run `winux php:install` to add ondrej/sury.');
		$this->check('PGDG repository', AptRepository::hasPostgres(), AptRepository::hasPostgres() ? 'configured' : 'missing', 'Run `winux service:install postgres` to add it.');

		$nginx = Nginx::make();
		$this->check('Nginx', $nginx->isInstalled() && $nginx->isActive(), $this->state($nginx), 'Run `winux service:install nginx`.');
		$nginxConf = @file_get_contents(config('winux.nginx.config')) ?: '';
		$this->check('Nginx includes winux sites', str_contains($nginxConf, Nginx::enabledPath()), '', 'Run `winux service:install nginx` to patch nginx.conf.');

		$phpVersions = PhpFpm::installedVersions();
		$this->check('PHP-FPM', !empty($phpVersions), implode(', ', $phpVersions) ?: 'none', 'Run `winux php:install`.');
		foreach ($phpVersions as $version) {
			$fpm = PhpFpm::make($version);
			$this->check("  PHP $version FPM", $fpm->isActive(), $this->state($fpm), "Run `winux service:start php $version`.");
		}

		if ($nginx->isInstalled() || $phpVersions) {
			$user = ServiceUser::current()['name'] ?? 'you';
			$wrong = ServiceUser::misconfigured();
			$this->check("nginx/PHP-FPM run as $user", empty($wrong), $wrong ? 'not yet: ' . implode(', ', $wrong) : '', 'Run `winux service:configure`.');
		}

		$missingPhp = collect(Site::all())
			->filter(fn (Site $site) => $site->isEnabled() && $site->phpVersion() && !in_array($site->phpVersion(), $phpVersions, true))
			->map(fn (Site $site) => "{$site->domain} ({$site->phpVersion()})");
		$this->check('Sites use installed PHP', $missingPhp->isEmpty(), $missingPhp->implode(', '), 'Install the version or run `winux site:php <version> --site=<site>`.');

		$clusters = Postgres::clusters();
		$this->check('Postgres', !empty($clusters), collect($clusters)->map(fn ($c, $v) => "$v:{$c['port']} {$c['status']}")->implode(', ') ?: 'none', 'Run `winux service:install postgres ' . Postgres::defaultVersion() . '`.');

		$redis = Redis::make();
		$this->check('Redis', $redis->isInstalled() && $redis->isActive(), $this->state($redis), 'Run `winux service:install redis`.');

		// Pre-mkcert CAs are adopted in place (a copy within ~/.winux) so the checks below see them
		Mkcert::adoptLegacyRoot();

		$this->check('Composer', Platform::commandExists('composer'), '', 'See https://getcomposer.org/download/');
		$this->check('mkcert', Mkcert::isInstalled(), '', 'Run `winux ssl:root:install`.');
		$this->check('Root CA', Mkcert::hasRootCA(), Mkcert::hasRootCA() ? Mkcert::rootCert() : 'missing', 'Run `winux ssl:root:install`.');
		if (Platform::isWsl() && Mkcert::hasRootCA()) {
			$this->check('Root CA trusted by Windows', Mkcert::isTrustedByWindows(), '', 'Run `winux ssl:root:install` and accept the Windows prompt.');
		}

		table(
			['Check', 'OK', 'Details'],
			collect($this->checks)->map(fn ($check) => [$check[0], $check[1] ? '✓' : '✗', $check[2]])->all()
		);

		$failures = collect($this->checks)->reject(fn ($check) => $check[1]);
		if ($failures->isEmpty()) {
			info('Everything looks good!');
			return self::SUCCESS;
		}

		foreach ($failures as [$name, , , $fix]) {
			if ($fix) {
				warning(trim($name) . ': ' . $fix);
			}
		}

		return self::FAILURE;
	}

	protected function check(string $name, bool $ok, string $details = '', string $fix = ''): void
	{
		$this->checks[] = [$name, $ok, $details, $fix];
	}

	protected function state($service): string
	{
		if (!$service->isInstalled()) {
			return 'not installed';
		}
		return $service->isActive() ? 'running' : 'stopped';
	}
}
