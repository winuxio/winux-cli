<?php

use App\Classes\ServiceUser;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Fakes every process; `find ... -print` reports $foreign as the files not owned by the user.
 */
function fakePermissionProcesses(array $foreign = []): void
{
	Process::fake(function (PendingProcess $process) use ($foreign) {
		$command = (array) $process->command;

		if (($command[0] ?? null) === 'dpkg-query') {
			return Process::result('install ok installed');
		}

		if (($command[1] ?? null) === 'find' && end($command) === '-print') {
			return Process::result(implode("\n", $foreign) . ($foreign ? "\n" : ''));
		}

		return Process::result();
	});
}

function ranWith(string $program): ?array
{
	$found = null;
	Process::assertRan(function ($process) use ($program, &$found) {
		$command = (array) $process->command;
		if (in_array($program, $command, true) && ($program !== 'find' || in_array('-exec', $command, true))) {
			$found = $command;
		}
		return true;
	});

	return $found;
}

beforeEach(function () {
	$this->root = winuxSandbox(['8.4']);
	ServiceUser::fake(['name' => 'dev', 'group' => 'devs', 'uid' => 1000]);

	// Services already run as dev, so no warning unless a test says otherwise
	config(['winux.nginx.config' => "{$this->root}/nginx.conf"]);
	File::put("{$this->root}/nginx.conf", "user dev devs;\n");
	File::ensureDirectoryExists("{$this->root}/etc/php/8.4/fpm/pool.d");
	File::put("{$this->root}/etc/php/8.4/fpm/pool.d/www.conf", "[www]\nuser = dev\ngroup = devs\nlisten.owner = dev\nlisten.group = devs\n");

	$this->domain = legacySite($this->root, 'shop');
	$this->project = "{$this->root}/projects/shop";
	File::ensureDirectoryExists("{$this->project}/storage/logs");
	File::ensureDirectoryExists("{$this->project}/bootstrap/cache");
});

it('gives files back to the user and makes storage writable', function () {
	fakePermissionProcesses(["{$this->project}/storage/logs/laravel.log", "{$this->project}/public/uploads/a.jpg"]);

	expect(Artisan::call('site:permissions', ['--site' => 'shop']))->toBe(0)
		->and(Artisan::output())->toContain("Gave 2 file(s) in {$this->project} back to dev")
		->not->toContain('service:configure');

	expect(ranWith('find'))->toBe(['sudo', 'find', $this->project, '!', '-user', 'dev', '-exec', 'chown', '-h', 'dev:devs', '{}', '+'])
		->and(ranWith('chmod'))->toBe(['chmod', '-R', 'u+rwX', "{$this->project}/storage", "{$this->project}/bootstrap/cache"]);
});

it('only lists the files with --dry-run', function () {
	fakePermissionProcesses(["{$this->project}/storage/logs/laravel.log"]);

	expect(Artisan::call('site:permissions', ['--site' => 'shop', '--dry-run' => true]))->toBe(0)
		->and(Artisan::output())->toContain("1 file(s) in {$this->project} aren't owned by dev")->toContain('storage/logs/laravel.log');

	expect(ranWith('find'))->toBeNull()
		->and(ranWith('chmod'))->toBeNull();
});

it('skips chown when everything is already yours', function () {
	fakePermissionProcesses();

	expect(Artisan::call('site:permissions', ['--site' => 'shop']))->toBe(0)
		->and(Artisan::output())->toContain('already owned by dev');

	expect(ranWith('find'))->toBeNull()
		->and(ranWith('chmod'))->not->toBeNull();
});

it('refuses to sweep a home directory', function () {
	fakePermissionProcesses(['x']);
	$config = "{$this->root}/.winux/sites/available/{$this->domain}";
	File::put($config, str_replace("{$this->project}/public", "{$this->root}/public", File::get($config)));
	File::ensureDirectoryExists("{$this->root}/public");

	expect(Artisan::call('site:permissions', ['--site' => 'shop']))->toBe(1)
		->and(Artisan::output())->toContain("that's not a project directory");

	Process::assertNotRan(fn ($process) => in_array('find', (array) $process->command, true));
});

it('warns when the services would keep writing files as someone else', function () {
	fakePermissionProcesses();
	File::put("{$this->root}/nginx.conf", "user www-data;\n");

	Artisan::call('site:permissions', ['--site' => 'shop']);

	expect(Artisan::output())->toContain('nginx (www-data)')->toContain('winux service:configure');
});

it('fixes every site with --all', function () {
	fakePermissionProcesses();
	legacySite($this->root, 'blog');

	expect(Artisan::call('site:permissions', ['--all' => true]))->toBe(0)
		->and(Artisan::output())->toContain("{$this->root}/projects/shop")->toContain("{$this->root}/projects/blog");
});
