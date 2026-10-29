<?php

use App\Classes\Hosts;
use App\Classes\Mkcert;
use App\Classes\Platform;
use App\Classes\Project;
use App\Classes\Settings;
use App\Enums\Editors;
use App\Services\Nginx;
use App\Services\Postgres;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
	$this->root = winuxSandbox(['8.2', '8.4']);
	Platform::fakeEnvironment(wsl: false, systemd: true);
	Postgres::flushClusterCache();
	$this->wslpath = '';
	Process::fake(fn (PendingProcess $process) => match (((array) $process->command)[0] ?? null) {
		'pg_lsclusters' => Process::result("16 main 5433 online postgres x y\n17 main 5432 online postgres x y\n"),
		'wslpath' => Process::result($this->wslpath),
		default => Process::result(),
	});
});

afterEach(function () {
	Postgres::flushClusterCache();
});

it('rejects an unknown service in service:status', function () {
	expect(Artisan::call('service:status', ['service' => 'foo']))->toBe(1)
		->and(Artisan::output())->toContain("Unknown service 'foo'. Choose from: nginx, php, postgres, redis, mysql");
});

it('rejects a postgres version that is not installed, listing the installed ones', function (string $command, array $arguments) {
	expect(Artisan::call($command, [...$arguments, '--pg' => '99', '--no-interaction' => true]))->toBe(1)
		->and(Artisan::output())->toContain("Postgres 99 isn't installed. Installed: 16, 17.");

	expect(Artisan::call($command, [...$arguments, '--pg' => 'abc', '--no-interaction' => true]))->toBe(1)
		->and(Artisan::output())->toContain("'abc' isn't a Postgres version");
})->with([
	['postgres:create', ['database' => 'x']],
	['postgres:delete', ['database' => 'x', '--force' => true]],
]);

it('asks for --force instead of failing silently without a terminal', function (string $command, array $arguments) {
	legacySite($this->root);

	expect(Artisan::call($command, [...$arguments, '--no-interaction' => true]))->toBe(1)
		->and(Artisan::output())->toContain('Pass --force');
})->with([
	['postgres:delete', ['database' => 'x']],
	['nginx:delete', ['--site' => 'legacy-app']],
]);

it('explains that there are no sites for site:secure --all', function () {
	expect(Artisan::call('site:secure', ['--all' => true]))->toBe(1)
		->and(Artisan::output())->toContain('No sites exist yet');
});

it('accepts --site (and the old positional name) in site:secure and nginx:*', function () {
	legacySite($this->root);

	expect(Artisan::call('site:secure', ['--site' => 'legacy-app']))->toBe(0)
		->and(Artisan::call('site:secure', ['name' => 'legacy-app']))->toBe(0)
		->and(Artisan::call('nginx:disable', ['--site' => 'legacy-app']))->toBe(0)
		->and(is_link("{$this->root}/.winux/sites/enabled/legacy-app.local.winux.io"))->toBeFalse()
		->and(Artisan::call('nginx:enable', ['domain' => 'legacy-app']))->toBe(0)
		->and(is_link("{$this->root}/.winux/sites/enabled/legacy-app.local.winux.io"))->toBeTrue();

	Process::assertRan(fn ($process) => ($process->command[0] ?? null) === 'mkcert' && in_array('legacy-app.local.winux.io', $process->command, true));
});

it('leaves the default php alone when --cli is refused', function () {
	Settings::set('php.default', '8.4');

	expect(Artisan::call('php:default', ['version' => '8.2', '--cli' => true]))->toBe(1)
		->and(Artisan::output())->toContain('Nothing was changed');

	expect(Settings::phpVersion())->toBe('8.4');
	Process::assertNotRan(fn ($process) => in_array('update-alternatives', (array) $process->command, true));
});

it('validates setup options before installing anything', function () {
	Platform::fake(['ID' => 'ubuntu', 'PRETTY_NAME' => 'Ubuntu 24.04']);

	expect(Artisan::call('setup', ['--php' => ['8.4', 'latest'], '--no-interaction' => true]))->toBe(1)
		->and(Artisan::output())->toContain("'latest' isn't a PHP version");

	Process::assertNotRan(fn ($process) => in_array('apt-get', (array) $process->command, true));
	Platform::fake([]);
});

it('says login is not available yet instead of waiting forever', function () {
	expect(Artisan::call('login'))->toBe(1)
		->and(Artisan::output())->toContain('not available yet');

	Process::assertNothingRan();
});

it('controls postgres clusters with pg_ctlcluster when systemd is not running', function () {
	expect(Postgres::make('16')->controlCommand('restart'))->toBe(['sudo', 'systemctl', 'restart', 'postgresql@16-main']);

	Platform::fakeEnvironment(wsl: false, systemd: false);
	expect(Postgres::make('16')->controlCommand('restart'))->toBe(['sudo', 'pg_ctlcluster', '16', 'main', 'restart']);

	expect(Postgres::make('16')->restart())->toBeTrue()
		->and(Postgres::make('16')->isActive())->toBeTrue()
		->and(Postgres::make('15')->isActive())->toBeFalse();

	Process::assertRan(fn ($process) => $process->command === ['sudo', 'pg_ctlcluster', '16', 'main', 'restart']);
	Process::assertNotRan(fn ($process) => in_array('service', (array) $process->command, true));
});

it('creates the mkcert root CA explicitly and checks it exists', function () {
	File::deleteDirectory(Mkcert::caRoot());
	Process::fake(function (PendingProcess $process) {
		$command = (array) $process->command;
		if (($command[0] ?? null) === 'sh') {
			return Process::result('/usr/bin/mkcert');
		}
		// mkcert writes the CA into CAROOT the first time it issues a certificate
		if (($command[0] ?? null) === 'mkcert' && in_array('-cert-file', $command, true)) {
			File::put(Mkcert::rootCert(), 'ca');
			File::put(Mkcert::rootKey(), 'key');
		}
		return Process::result();
	});

	expect(Mkcert::hasRootCA())->toBeFalse()
		->and(Mkcert::init())->toBeTrue()
		->and(Mkcert::hasRootCA())->toBeTrue();
	Process::assertNotRan(fn ($process) => in_array('-CAROOT', (array) $process->command, true));
});

it('fails init when mkcert does not create the root CA', function () {
	File::deleteDirectory(Mkcert::caRoot());
	Process::fake(['*command -v mkcert*' => Process::result('/usr/bin/mkcert'), '*' => Process::result()]);

	expect(Mkcert::init())->toBeFalse();
});

it('copies a staged file into the Windows hosts file instead of passing the contents inline', function () {
	$command = Hosts::windowsCopyCommand("C:\\Users\\O'Brien\\AppData\\Local\\Temp\\winux-hosts-abc");

	preg_match("/'-EncodedCommand','([^']+)'/", $command, $matches);
	$inner = mb_convert_encoding(base64_decode($matches[1]), 'UTF-8', 'UTF-16LE');

	expect($inner)->toContain("Copy-Item -LiteralPath 'C:\\Users\\O''Brien\\AppData\\Local\\Temp\\winux-hosts-abc'")
		->toContain('System32\drivers\etc\hosts')
		->toContain("Set-Content -LiteralPath 'C:\\Users\\O''Brien\\AppData\\Local\\Temp\\winux-hosts-abc.err'")
		->and($command)->toContain('-Verb RunAs')->toContain('exit $p.ExitCode')->toContain('exit ' . Hosts::UAC_DECLINED)
		// The size doesn't depend on the hosts file, so large ones stay far below the 32K limit
		->and(strlen($command))->toBeLessThan(2000);
});

it('opens PhpStorm with the Windows path of the project on WSL', function () {
	Platform::fakeEnvironment(wsl: true, systemd: true);
	$this->wslpath = "\\\\wsl.localhost\\Ubuntu\\home\\dev\\blog\n";
	$project = Project::create('blog');

	expect($project->openEditor(Editors::PHPSTORM))->toBeTrue()
		->and($project->openEditor(Editors::VSCODE))->toBeTrue();

	Process::assertRan(fn ($process) => $process->command === ['phpstorm64.exe', '\\\\wsl.localhost\\Ubuntu\\home\\dev\\blog']);
	Process::assertRan(fn ($process) => $process->command === ['code', $project->path]);
});

it('only writes the placeholder page when it is missing or out of date', function () {
	$dest = config('winux.nginx.default_root');
	File::ensureDirectoryExists($dest);
	foreach (File::files(storage_path('default-site/www')) as $file) {
		File::copy($file->getPathname(), "$dest/{$file->getFilename()}");
	}
	File::put("$dest/index.html", 'old');

	expect(Nginx::installDefaultPage())->toBeTrue();

	Process::assertRanTimes(fn ($process) => ($process->command[1] ?? null) === 'tee', 1);
	Process::assertRan(fn ($process) => $process->command === ['sudo', 'tee', "$dest/index.html"]);
});
