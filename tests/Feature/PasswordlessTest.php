<?php

use App\Classes\Platform;
use App\Classes\ServiceUser;
use App\Classes\Settings;
use App\Classes\Sudoers;
use App\Classes\Upgrades;
use App\Services\Postgres;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Fakes every process. $state:
 *  - peer: whether dev can already connect as its own superuser role
 *  - roles: other roles on the cluster
 *  - passwordless: commands (as run after sudo) that `sudo -n -k -l` allows, or true for all
 *  - visudo: whether visudo accepts the file
 *  - owner: who owns every database
 */
function fakePasswordlessProcesses(array $state = []): void
{
	$state += ['peer' => false, 'roles' => [], 'passwordless' => [], 'visudo' => true, 'owner' => ''];

	Process::fake(function (PendingProcess $process) use (&$state) {
		$command = (array) $process->command;
		$sql = (string) $process->input;

		if (($command[0] ?? null) === 'pg_lsclusters') {
			return Process::result("16 main 5432 online postgres x y\n");
		}

		// The peer probe: psql over the socket as dev
		if (($command[0] ?? null) === 'psql' && in_array('-tAc', $command, true)) {
			return $state['peer'] ? Process::result("t\n") : Process::result(errorOutput: 'role "dev" does not exist', exitCode: 2);
		}

		if (str_starts_with($sql, 'CREATE ROLE "dev"')) {
			$state['peer'] = true;
			return Process::result();
		}

		if (str_starts_with($sql, 'SELECT pg_get_userbyid(datdba)')) {
			return Process::result($state['owner']);
		}

		if (preg_match("/FROM pg_roles WHERE rolname = '(.+)'/", $sql, $m)) {
			return Process::result(in_array($m[1], $state['roles'], true) ? '1' : '');
		}

		if (array_slice($command, 0, 4) === ['sudo', '-n', '-k', '-l']) {
			$allowed = $state['passwordless'] === true || in_array(implode(' ', array_slice($command, 4)), $state['passwordless'], true);
			return Process::result(exitCode: $allowed ? 0 : 1);
		}

		if (array_slice($command, 0, 2) === ['sudo', 'visudo']) {
			return $state['visudo'] ? Process::result() : Process::result(errorOutput: 'syntax error', exitCode: 1);
		}

		if (array_slice($command, 0, 2) === ['sudo', 'install']) {
			$state['passwordless'] = true;
			return Process::result();
		}

		if (($command[0] ?? null) === 'dpkg-query') {
			return Process::result('install ok installed');
		}

		if (($command[0] ?? null) === 'systemctl' && ($command[1] ?? null) === 'is-active') {
			return Process::result();
		}

		return Process::result();
	});
}

beforeEach(function () {
	$this->root = winuxSandbox(['8.4']);
	ServiceUser::fake(['name' => 'dev', 'group' => 'dev', 'uid' => 1000]);
	Platform::fakeEnvironment(wsl: false, systemd: true);
	Postgres::flushClusterCache();
	config(['winux.nginx.config' => "{$this->root}/nginx.conf"]);
	File::put("{$this->root}/nginx.conf", "user dev dev;\n");
	File::ensureDirectoryExists("{$this->root}/etc/php/8.4/fpm/pool.d");
	File::put("{$this->root}/etc/php/8.4/fpm/pool.d/www.conf", "[www]\nuser = dev\ngroup = dev\nlisten.owner = dev\nlisten.group = dev\n");

	// Fake programs where sudo would find them
	File::ensureDirectoryExists("{$this->root}/sbin");
	foreach (['nginx', 'systemctl', 'php-fpm8.4'] as $program) {
		File::put("{$this->root}/sbin/$program", '');
		chmod("{$this->root}/sbin/$program", 0755);
	}
	config(['winux.sudo_secure_path' => ["{$this->root}/sbin"]]);
});

afterEach(function () {
	Postgres::flushClusterCache();
});

#region Postgres
it('connects as your own role, without sudo, once you have one', function () {
	fakePasswordlessProcesses(['peer' => true]);

	Postgres::make('16')->databaseExists('shop');

	Process::assertRan(fn ($process) => array_slice((array) $process->command, 0, 6) === ['psql', '-w', '-h', '/var/run/postgresql', '-U', 'dev']);
	Process::assertNotRan(fn ($process) => in_array('sudo', (array) $process->command, true));
});

it('falls back to sudo -u postgres without your role', function () {
	fakePasswordlessProcesses();

	Postgres::make('16')->databaseExists('shop');

	Process::assertRan(fn ($process) => array_slice((array) $process->command, 0, 4) === ['sudo', '-u', 'postgres', 'psql']);
});

it('creates your superuser role once', function () {
	fakePasswordlessProcesses();

	expect(Postgres::make('16')->grantPeerAccess())->toBeTrue()
		->and(Postgres::make('16')->hasPeerAccess())->toBeTrue();

	Process::assertRan(fn ($process) => (string) $process->input === 'CREATE ROLE "dev" WITH LOGIN SUPERUSER');
});

it("won't turn a project's role with your name into a superuser", function () {
	fakePasswordlessProcesses(['roles' => ['dev']]);

	expect(Postgres::make('16')->grantPeerAccess())->toBeFalse();

	Process::assertNotRan(fn ($process) => str_contains((string) $process->input, 'CREATE ROLE'));
});

it('hands a database your role created over to the project user', function () {
	fakePasswordlessProcesses(['peer' => true, 'owner' => 'dev']);

	expect(Postgres::make('16')->grant('shop', 'shop'))->toBeTrue();

	Process::assertRan(fn ($process) => (string) $process->input === 'ALTER DATABASE "shop" OWNER TO "shop"');
});
#endregion

#region sudoers
it('allows only the exact nginx and PHP-FPM commands winux runs', function () {
	expect(Sudoers::contents('dev'))->toContain(
		"dev ALL=(root) NOPASSWD: \\\n"
		. "    {$this->root}/sbin/nginx -t, \\\n"
		. "    {$this->root}/sbin/systemctl reload nginx, \\\n"
		. "    {$this->root}/sbin/systemctl restart nginx, \\\n"
		. "    {$this->root}/sbin/systemctl start nginx, \\\n"
		. "    {$this->root}/sbin/php-fpm8.4 -t, \\\n"
		. "    {$this->root}/sbin/systemctl reload php8.4-fpm, \\\n"
		. "    {$this->root}/sbin/systemctl restart php8.4-fpm, \\\n"
		. "    {$this->root}/sbin/systemctl start php8.4-fpm\n"
	);
});

it('installs the rules only after visudo accepts them', function (bool $valid) {
	fakePasswordlessProcesses(['visudo' => $valid]);

	expect(Sudoers::install())->toBe($valid);

	$installed = fn ($process) => array_slice((array) $process->command, 0, 2) === ['sudo', 'install']
		&& end($process->command) === '/etc/sudoers.d/winux';
	$valid ? Process::assertRan($installed) : Process::assertNotRan($installed);
})->with(['valid' => true, 'invalid' => false]);

it('lists the commands that still need a password', function () {
	fakePasswordlessProcesses(['passwordless' => ['nginx -t', 'systemctl reload nginx']]);

	expect(Sudoers::missing())->toBe([
		'systemctl restart nginx', 'systemctl start nginx',
		'php-fpm8.4 -t', 'systemctl reload php8.4-fpm', 'systemctl restart php8.4-fpm', 'systemctl start php8.4-fpm',
	]);
});

it('remembers turning the rules off, and doctor stops suggesting them', function () {
	fakePasswordlessProcesses(['peer' => true]);

	Artisan::call('sudoers', ['--remove' => true]);
	expect(Sudoers::declined())->toBeTrue();

	Artisan::call('doctor');
	expect(Artisan::output())->toContain('turned off');
	Process::assertNotRan(fn ($process) => array_slice((array) $process->command, 0, 4) === ['sudo', '-n', '-k', '-l']);

	Artisan::call('sudoers');
	expect(Sudoers::declined())->toBeFalse();
});
#endregion

#region doctor and upgrades
it('fixes the Postgres role and sudoers rules with doctor --fix', function () {
	fakePasswordlessProcesses();

	Artisan::call('doctor', ['--fix' => true, '--no-interaction' => true]);

	expect(Artisan::output())->toContain('Fixing Postgres without sudo')->toContain('Fixing Passwordless nginx/PHP-FPM reloads')
		->and(Postgres::make('16')->hasPeerAccess())->toBeTrue()
		->and(Sudoers::missing())->toBe([])
		->and(Settings::get('setup.version'))->toBe(array_key_last(Upgrades::CHANGES));
});

it('reports without fixing unless asked', function () {
	fakePasswordlessProcesses();

	Artisan::call('doctor');

	expect(Artisan::output())->toContain('Run `winux doctor --fix`')
		->and(Settings::get('setup.version'))->toBeNull();
	Process::assertNotRan(fn ($process) => str_contains((string) $process->input, 'CREATE ROLE'));
});

it('does nothing with --upgrade when this version changed nothing for the machine', function () {
	fakePasswordlessProcesses();
	Settings::set('setup.version', array_key_last(Upgrades::CHANGES));

	expect(Artisan::call('doctor', ['--upgrade' => true]))->toBe(0)
		->and(trim(Artisan::output()))->toBe('');
});

it('applies only the pending changes with --upgrade', function () {
	fakePasswordlessProcesses(['passwordless' => true]);

	Artisan::call('doctor', ['--upgrade' => true, '--no-interaction' => true]);

	expect(Artisan::output())->toContain('This version of winux changes how your machine is set up')
		->toContain('Fixing Postgres without sudo')
		->and(Settings::get('setup.version'))->toBe(array_key_last(Upgrades::CHANGES));
});

it('reminds you after other commands until the changes are applied', function () {
	$remind = function () {
		$output = new BufferedOutput(decorated: true);
		Upgrades::afterCommand(new CommandFinished('php:list', new ArrayInput([]), $output, 0));
		return $output->fetch();
	};

	expect($remind())->toContain('winux doctor --fix');

	Upgrades::markApplied();
	expect($remind())->toBe('');
});

it('never reminds a machine winux has not set up yet', function () {
	File::deleteDirectory("{$this->root}/.winux/sites");

	expect(Upgrades::pending())->toBe([]);
});
#endregion
