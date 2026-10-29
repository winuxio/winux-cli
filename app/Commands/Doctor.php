<?php

namespace App\Commands;

use App\Classes\AptRepository;
use App\Classes\Mkcert;
use App\Classes\Platform;
use App\Classes\Releases;
use App\Classes\ServiceUser;
use App\Classes\Site;
use App\Classes\Sudoers;
use App\Classes\UpdateCheck;
use App\Classes\Upgrades;
use App\Services\Nginx;
use App\Services\PhpFpm;
use App\Services\Postgres;
use App\Services\Redis;
use Closure;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{confirm, error, info, note, table, warning};

class Doctor extends Command
{
	protected $signature = 'doctor
		{--fix : Fix the problems winux can fix itself}
		{--upgrade : Apply the setup changes of a new version (self-update runs this)}';

	protected $description = 'Check this machine is set up correctly for winux (and fix it with --fix)';

	/** @var array<int, array{0: string, 1: bool, 2: string, 3: string, 4: ?Closure}> name, ok, details, hint, repair */
	protected array $checks = [];

	public function handle(): int
	{
		$upgrade = (bool) $this->option('upgrade');
		$pending = Upgrades::pending();
		if ($upgrade && !$pending) {
			return self::SUCCESS;
		}

		if ($upgrade) {
			info('This version of winux changes how your machine is set up:');
			foreach ($pending as $version => $change) {
				$this->line("  • <options=bold>$version</>: {$change['summary']}");
			}
		}

		$this->runChecks();
		$this->render();

		// An upgrade only touches what the new version changed; --fix takes on everything it can
		$repairable = collect($this->checks)
			->filter(fn ($check) => !$check[1] && $check[4] !== null)
			->when($upgrade, fn ($checks) => $checks->filter(fn ($check) => in_array(trim($check[0]), Upgrades::pendingChecks(), true)));

		if (($this->option('fix') || $upgrade) && $repairable->isNotEmpty()) {
			$names = $repairable->map(fn ($check) => trim($check[0]))->values();
			if ($this->input->isInteractive() && !confirm('Fix ' . $names->implode(', ') . '? This may ask for your password.', true)) {
				return self::FAILURE;
			}

			foreach ($repairable as [$name, , , , $repair]) {
				info('==> Fixing ' . trim($name));
				if (!$repair()) {
					error('Couldn\'t fix ' . trim($name) . '.');
				}
			}

			Postgres::flushClusterCache();
			$this->runChecks();
			$this->render();
		}

		$failures = collect($this->checks)->reject(fn ($check) => $check[1]);

		// The machine has a version's changes once its checks pass (or don't apply here)
		if ($failures->every(fn ($check) => !in_array(trim($check[0]), Upgrades::pendingChecks(), true))) {
			Upgrades::markApplied();
		}

		if ($failures->isEmpty()) {
			info('Everything looks good!');
			return self::SUCCESS;
		}

		foreach ($failures as [$name, , , $fix]) {
			if ($fix) {
				warning(trim($name) . ': ' . $fix);
			}
		}

		if (!$this->option('fix') && $failures->contains(fn ($check) => $check[4] !== null)) {
			note('Run `winux doctor --fix` to fix what winux can fix itself.');
		}

		return self::FAILURE;
	}

	protected function runChecks(): void
	{
		$this->checks = [];

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
		$this->check('Nginx', $nginx->isInstalled() && $nginx->isActive(), $this->state($nginx), 'Run `winux service:install nginx`.', $nginx->isInstalled() ? fn () => $nginx->start() : null);
		$nginxConf = @file_get_contents(config('winux.nginx.config')) ?: '';
		$this->check('Nginx includes winux sites', str_contains($nginxConf, Nginx::enabledPath()), '', 'Run `winux service:install nginx` to patch nginx.conf.');

		$phpVersions = PhpFpm::installedVersions();
		$this->check('PHP-FPM', !empty($phpVersions), implode(', ', $phpVersions) ?: 'none', 'Run `winux php:install`.');
		foreach ($phpVersions as $version) {
			$fpm = PhpFpm::make($version);
			$this->check("  PHP $version FPM", $fpm->isActive(), $this->state($fpm), "Run `winux service:start php $version`.", fn () => $fpm->start());
		}

		if ($nginx->isInstalled() || $phpVersions) {
			$user = ServiceUser::current()['name'] ?? 'you';
			$wrong = ServiceUser::misconfigured();
			$this->check("nginx/PHP-FPM run as $user", empty($wrong), $wrong ? 'not yet: ' . implode(', ', $wrong) : '', 'Run `winux service:configure`.', fn () => $this->call('service:configure') === self::SUCCESS);

			$missing = Sudoers::declined() ? [] : Sudoers::missing();
			$this->check(
				'Passwordless nginx/PHP-FPM reloads',
				empty($missing),
				match (true) {
					Sudoers::declined() => 'turned off (`winux sudoers` turns it on)',
					(bool) $missing => count($missing) . ' command(s) still ask for your password',
					default => Sudoers::path(),
				},
				'Run `winux sudoers`.',
				fn () => Sudoers::install()
			);
		}

		$missingPhp = collect(Site::all())
			->filter(fn (Site $site) => $site->isEnabled() && $site->phpVersion() && !in_array($site->phpVersion(), $phpVersions, true))
			->map(fn (Site $site) => "{$site->domain} ({$site->phpVersion()})");
		$this->check('Sites use installed PHP', $missingPhp->isEmpty(), $missingPhp->implode(', '), 'Install the version or run `winux site:php <version> --site=<site>`.');

		$clusters = Postgres::clusters();
		$this->check('Postgres', !empty($clusters), collect($clusters)->map(fn ($c, $v) => "$v:{$c['port']} {$c['status']}")->implode(', ') ?: 'none', 'Run `winux service:install postgres ' . Postgres::defaultVersion() . '`.');

		// Your own superuser role, so projects don't need `sudo -u postgres`
		$online = collect($clusters)->filter(fn ($cluster) => $cluster['status'] === 'online')->keys();
		if ($online->isNotEmpty()) {
			$withoutAccess = $online->reject(fn ($version) => Postgres::make((string) $version)->hasPeerAccess())->values();
			$this->check(
				'Postgres without sudo',
				$withoutAccess->isEmpty(),
				$withoutAccess->isEmpty() ? 'role ' . Postgres::peerRole() : 'no role for ' . Postgres::peerRole() . ' in ' . $withoutAccess->implode(', '),
				'Run `winux doctor --fix` to create it.',
				fn () => $withoutAccess->every(fn ($version) => Postgres::make((string) $version)->grantPeerAccess())
			);
		}

		$redis = Redis::make();
		$this->check('Redis', $redis->isInstalled() && $redis->isActive(), $this->state($redis), 'Run `winux service:install redis`.', $redis->isInstalled() ? fn () => $redis->start() : null);

		// Pre-mkcert CAs are adopted in place (a copy within ~/.winux) so the checks below see them
		Mkcert::adoptLegacyRoot();

		$this->check('Composer', Platform::commandExists('composer'), '', 'See https://getcomposer.org/download/');
		$sslRoot = fn () => $this->call('ssl:root:install') === self::SUCCESS;
		$this->check('mkcert', Mkcert::isInstalled(), '', 'Run `winux ssl:root:install`.', $sslRoot);
		$this->check('Root CA', Mkcert::hasRootCA(), Mkcert::hasRootCA() ? Mkcert::rootCert() : 'missing', 'Run `winux ssl:root:install`.', $sslRoot);
		if (Platform::isWsl() && Mkcert::hasRootCA()) {
			$this->check('Root CA trusted by Windows', Mkcert::isTrustedByWindows(), '', 'Run `winux ssl:root:install` and accept the Windows prompt.', $sslRoot);
		}
	}

	protected function render(): void
	{
		table(
			['Check', 'OK', 'Details'],
			collect($this->checks)->map(fn ($check) => [$check[0], $check[1] ? '<fg=green>✓</>' : '<fg=red;options=bold>✗</>', $check[2]])->all()
		);
	}

	/**
	 * @param string $fix How to fix it by hand
	 * @param Closure|null $repair Fixes it (for --fix), returning whether that worked
	 */
	protected function check(string $name, bool $ok, string $details = '', string $fix = '', ?Closure $repair = null): void
	{
		$this->checks[] = [$name, $ok, $details, $fix, $repair];
	}

	protected function state($service): string
	{
		if (!$service->isInstalled()) {
			return 'not installed';
		}
		return $service->isActive() ? 'running' : 'stopped';
	}
}
