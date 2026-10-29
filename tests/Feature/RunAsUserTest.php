<?php

use App\Classes\ServiceUser;
use App\Services\Nginx;
use App\Services\PhpFpm;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

const UBUNTU_POOL = <<<'CONF'
[www]

; Unix user/group of the child processes. This can be used only if the master
; process running user is root. It is set after the child process is created.
; Note: The user is mandatory. If the group is not set, the default user's group
;       will be used.
user = www-data
group = www-data

listen = /run/php/php8.4-fpm.sock

; Set permissions for unix socket, if one is used. In Linux, read/write
; permissions must be set in order to allow connections from a web server.
listen.owner = www-data
listen.group = www-data
;listen.mode = 0660

pm = dynamic
CONF;

/**
 * Fakes every process. `sudo tee <file>` inside the sandbox really writes the file, so the end
 * state can be checked; `nginx -t` / `php-fpm -t` pass unless told otherwise.
 */
function fakeServiceProcesses(string $root, array $state = []): void
{
	$state += ['nginxTest' => true, 'fpmTest' => true, 'active' => true];

	Process::fake(function (PendingProcess $process) use ($root, $state) {
		$command = (array) $process->command;

		if (($command[0] ?? null) === 'dpkg-query') {
			return Process::result('install ok installed');
		}

		if ($command === ['sudo', 'nginx', '-t']) {
			return $state['nginxTest'] === true ? Process::result() : Process::result(errorOutput: $state['nginxTest'], exitCode: 1);
		}

		if (($command[0] ?? null) === 'sudo' && str_starts_with($command[1] ?? '', 'php-fpm') && ($command[2] ?? null) === '-t') {
			return $state['fpmTest'] === true ? Process::result() : Process::result(errorOutput: $state['fpmTest'], exitCode: 1);
		}

		if (($command[0] ?? null) === 'sudo' && ($command[1] ?? null) === 'tee' && str_starts_with($command[2] ?? '', $root)) {
			File::put($command[2], (string) $process->input);
			return Process::result();
		}

		if (($command[0] ?? null) === 'systemctl' && ($command[1] ?? null) === 'is-active') {
			return Process::result(exitCode: $state['active'] ? 0 : 3);
		}

		return Process::result();
	});
}

beforeEach(function () {
	$this->root = winuxSandbox(['8.3', '8.4']);
	config(['winux.nginx.config' => "{$this->root}/nginx.conf"]);
	File::put("{$this->root}/nginx.conf", "user www-data;\nworker_processes auto;\n\nhttp {\n\tinclude /etc/nginx/conf.d/*.conf;\n}\n");

	foreach (['8.3', '8.4'] as $version) {
		File::ensureDirectoryExists("{$this->root}/etc/php/$version/fpm/pool.d");
		File::put("{$this->root}/etc/php/$version/fpm/pool.d/www.conf", UBUNTU_POOL);
	}

	ServiceUser::fake(['name' => 'dev', 'group' => 'devs', 'uid' => 1000]);
	\App\Classes\Platform::fakeEnvironment(wsl: false, systemd: true);
});

it('sets the nginx user directive, or adds one', function () {
	expect(Nginx::withUser("user www-data;\nworker_processes auto;\n", 'dev', 'devs'))->toBe("user dev devs;\nworker_processes auto;\n")
		->and(Nginx::withUser("  user nginx nginx;\n", 'dev', 'devs'))->toBe("  user dev devs;\n")
		->and(Nginx::withUser("worker_processes auto;\n", 'dev', 'devs'))->toBe("user dev devs;\nworker_processes auto;\n")
		// user_agent-like names and commented directives aren't the user directive
		->and(Nginx::withUser("#user nobody;\nuser_x y;\n", 'dev', 'devs'))->toBe("user dev devs;\n#user nobody;\nuser_x y;\n");
});

it('changes only the user, group and socket owner of a pool', function () {
	$updated = PhpFpm::withPoolUser(UBUNTU_POOL, 'dev', 'devs');

	expect($updated)->toContain("\nuser = dev\ngroup = devs\n")
		->toContain("listen.owner = dev\nlisten.group = devs\n;listen.mode = 0660")
		->toContain('listen = /run/php/php8.4-fpm.sock')
		->toContain("; Note: The user is mandatory. If the group is not set, the default user's group")
		->and(substr_count($updated, 'user = '))->toBe(1)
		->and(PhpFpm::withPoolUser($updated, 'dev', 'devs'))->toBe($updated);

	// Commented-out directives are uncommented; missing ones are appended
	expect(PhpFpm::withPoolUser("[www]\n;listen.owner = nobody\n", 'dev', 'devs'))
		->toBe("[www]\nlisten.owner = dev\nuser = dev\ngroup = devs\nlisten.group = devs\n");
});

it('normalizes nginx and every php-fpm pool with service:configure', function () {
	fakeServiceProcesses($this->root);
	// A machine where one pool was already changed by hand
	File::put("{$this->root}/etc/php/8.3/fpm/pool.d/www.conf", PhpFpm::withPoolUser(UBUNTU_POOL, 'dev', 'devs'));

	expect(ServiceUser::misconfigured())->toBe(['nginx (www-data)', 'PHP 8.4 (www-data)']);

	expect(Artisan::call('service:configure'))->toBe(0)
		->and(Artisan::output())->toContain('nginx and PHP-FPM run as dev')->toContain('winux site:permissions --all');

	expect(File::get("{$this->root}/nginx.conf"))->toStartWith("user dev devs;\n")
		->and(File::get("{$this->root}/etc/php/8.4/fpm/pool.d/www.conf"))->toContain("user = dev\ngroup = devs")
		->and(ServiceUser::misconfigured())->toBe([])
		->and(File::glob("{$this->root}/.winux/backups/*nginx.conf.*"))->toHaveCount(1);

	Process::assertRan(fn ($process) => $process->command === ['sudo', 'php-fpm8.4', '-t']);
	Process::assertRan(fn ($process) => $process->command === ['sudo', 'systemctl', 'restart', 'php8.4-fpm']);
	Process::assertRan(fn ($process) => $process->command === ['sudo', 'systemctl', 'reload', 'nginx']);
	// The pool that was already right is left alone
	Process::assertNotRan(fn ($process) => $process->command === ['sudo', 'systemctl', 'restart', 'php8.3-fpm']);

	// Running it again changes nothing
	expect(Artisan::call('service:configure'))->toBe(0)
		->and(Artisan::output())->not->toContain('site:permissions');
});

it('restores nginx.conf when nginx rejects the new user', function () {
	fakeServiceProcesses($this->root, ['nginxTest' => 'nginx: [emerg] getpwnam("dev") failed']);
	$before = File::get("{$this->root}/nginx.conf");

	expect(Artisan::call('service:configure'))->toBe(1)
		->and(Artisan::output())->toContain('getpwnam');

	expect(File::get("{$this->root}/nginx.conf"))->toBe($before);
});

it('restores a pool when php-fpm rejects it', function () {
	fakeServiceProcesses($this->root, ['fpmTest' => 'ERROR: [pool www] cannot get uid for user \'dev\'']);

	expect(Artisan::call('service:configure'))->toBe(1);

	expect(File::get("{$this->root}/etc/php/8.4/fpm/pool.d/www.conf"))->toBe(UBUNTU_POOL);
	Process::assertNotRan(fn ($process) => $process->command === ['sudo', 'systemctl', 'restart', 'php8.4-fpm']);
});

it('refuses to configure the services to run as root', function () {
	fakeServiceProcesses($this->root);
	ServiceUser::fake(['name' => 'root', 'group' => 'root', 'uid' => 0]);

	expect(Artisan::call('service:configure'))->toBe(1)
		->and(Artisan::output())->toContain('running as root');

	expect(File::get("{$this->root}/nginx.conf"))->toStartWith('user www-data;');
});

it('configures a php version as part of installing it', function () {
	fakeServiceProcesses($this->root);
	config(['winux.php.extensions' => []]);
	File::put("{$this->root}/etc/php/8.3/fpm/pool.d/www.conf", UBUNTU_POOL);

	expect(PhpFpm::make('8.3')->install())->toBeTrue();

	expect(File::get("{$this->root}/etc/php/8.3/fpm/pool.d/www.conf"))->toContain("user = dev\n");
	Process::assertRan(fn ($process) => $process->command === ['sudo', 'systemctl', 'enable', '--now', 'php8.3-fpm']);
});

it('fails the install when the service does not start', function () {
	Process::fake(function (PendingProcess $process) {
		$command = (array) $process->command;
		if (($command[1] ?? null) === 'systemctl' && ($command[2] ?? null) === 'enable') {
			return Process::result(errorOutput: 'Job for redis-server.service failed', exitCode: 1);
		}
		return Process::result();
	});

	expect(\App\Services\Redis::make()->install())->toBeFalse();
});

it('starts the service after installing it when systemd is not running', function () {
	\App\Classes\Platform::fakeEnvironment(systemd: false);
	Process::fake(function (PendingProcess $process) {
		$command = (array) $process->command;
		// `service redis-server status` says it's stopped
		return Process::result(exitCode: in_array('status', $command, true) ? 3 : 0);
	});

	expect(\App\Services\Redis::make()->install())->toBeTrue();

	Process::assertRan(fn ($process) => $process->command === ['sudo', 'service', 'redis-server', 'start']);
	Process::assertNotRan(fn ($process) => in_array('systemctl', (array) $process->command, true));
});

it('reports which services the doctor should fix', function () {
	fakeServiceProcesses($this->root);

	Artisan::call('doctor');

	expect(Artisan::output())->toContain('nginx/PHP-FPM run as dev')->toContain('Run `winux service:configure`.');
});
