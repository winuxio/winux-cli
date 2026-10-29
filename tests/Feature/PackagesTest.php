<?php

use App\Classes\Platform;
use App\Enums\Packages;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Fakes every process, finding composer at /usr/local/bin/composer; $npm says whether npm is on PATH.
 */
function fakePackageProcesses(bool $npm = true): void
{
	Process::fake(function (PendingProcess $process) use ($npm) {
		$command = (array) $process->command;
		$script = $command[2] ?? '';

		if (($command[0] ?? null) === 'sh' && str_contains($script, 'command -v composer')) {
			return Process::result("/usr/local/bin/composer\n");
		}
		if (($command[0] ?? null) === 'sh' && str_contains($script, 'command -v ') && str_contains($script, 'npm')) {
			return $npm ? Process::result("/usr/bin/npm\n") : Process::result(exitCode: 1);
		}

		return Process::result();
	});
}

/** Every command that ran, in order. */
function packageCommands(): array
{
	$commands = [];
	Process::assertRan(function ($process) use (&$commands) {
		$command = (array) $process->command;
		if (($command[0] ?? null) !== 'sh') {
			$commands[] = $command;
		}
		return true;
	});
	return $commands;
}

beforeEach(function () {
	$this->root = winuxSandbox(['8.4']);
	$this->project = "{$this->root}/work/blog";
	File::ensureDirectoryExists("{$this->project}/bootstrap");
	File::ensureDirectoryExists("{$this->project}/app/Providers/Filament");
	Platform::fake(null);
});

it('installs what a package needs first, in install order', function () {
	expect(Packages::resolve([Packages::ActivityLog, Packages::Socialment, Packages::Boost]))
		->toBe([Packages::Filament, Packages::Boost, Packages::Socialment, Packages::ActivityLog]);
});

it('requires dev tools with --dev and runs their installers', function () {
	fakePackageProcesses();

	expect(Packages::Boost->install($this->project, 'php8.4'))->toBeTrue();

	expect(packageCommands())->toBe([
		['php8.4', '/usr/local/bin/composer', 'require', 'laravel/boost', '-W', '--dev'],
		['php8.4', 'artisan', 'boost:install', '--guidelines', '--skills', '--mcp', '--no-interaction'],
	]);
});

it('lets the Laravel installers require Sanctum, Passport and Reverb themselves', function (Packages $package, array $artisan) {
	fakePackageProcesses();

	expect($package->install($this->project, 'php8.4'))->toBeTrue();

	expect(packageCommands())->toBe([['php8.4', 'artisan', ...$artisan, '--no-interaction']]);
})->with([
	'sanctum' => [Packages::Sanctum, ['install:api', '--composer=/usr/local/bin/composer', '--without-migration-prompt']],
	'passport' => [Packages::Passport, ['install:api', '--passport', '--composer=/usr/local/bin/composer', '--without-migration-prompt']],
	'reverb' => [Packages::Reverb, ['install:broadcasting', '--reverb', '--composer=/usr/local/bin/composer']],
]);

it('skips the node dependencies for Reverb without npm, and says what to add', function () {
	fakePackageProcesses(npm: false);

	Packages::Reverb->install($this->project, 'php8.4');

	expect(packageCommands()[0])->toContain('--without-node');
});

it('stops at the first failing step', function () {
	Process::fake(['*composer*' => Process::result(exitCode: 1), '*' => Process::result()]);

	expect(Packages::Telescope->install($this->project, 'php8.4'))->toBeFalse();

	Process::assertNotRan(fn ($process) => in_array('telescope:install', (array) $process->command, true));
});

it('wires Sentry into bootstrap/app.php once', function () {
	fakePackageProcesses();
	File::put("{$this->project}/bootstrap/app.php", <<<'PHP'
	<?php
	return Application::configure(basePath: dirname(__DIR__))
	    ->withExceptions(function (Exceptions $exceptions): void {
	        //
	    })->create();
	PHP);

	Packages::Sentry->install($this->project, 'php8.4');
	Packages::Sentry->install($this->project, 'php8.4');

	expect(File::get("{$this->project}/bootstrap/app.php"))->toContain(<<<'PHP'
	    ->withExceptions(function (Exceptions $exceptions): void {
	        \Sentry\Laravel\Integration::handles($exceptions);
	        //
	PHP)
		->and(substr_count(File::get("{$this->project}/bootstrap/app.php"), 'Integration::handles'))->toBe(1);
});

it('adds the Socialment plugin to the Filament panel', function () {
	fakePackageProcesses();
	File::put("{$this->project}/app/Providers/Filament/AdminPanelProvider.php", "        return \$panel\n            ->id('admin')\n            ->login()\n            ->colors([]);\n");

	expect(Packages::Socialment->install($this->project, 'php8.4'))->toBeTrue();

	expect(File::get("{$this->project}/app/Providers/Filament/AdminPanelProvider.php"))
		->toContain("            ->login()\n            ->plugin(\\ChrisReedIO\\Socialment\\SocialmentPlugin::make())\n            ->colors([]);");
});

it('gives Larastan a config unless the project has one', function () {
	fakePackageProcesses();

	Packages::Larastan->install($this->project, 'php8.4');
	expect(File::get("{$this->project}/phpstan.neon"))->toContain('vendor/larastan/larastan/extension.neon')->toContain('level: 5');

	File::put("{$this->project}/phpstan.neon", 'mine');
	Packages::Larastan->install($this->project, 'php8.4');
	expect(File::get("{$this->project}/phpstan.neon"))->toBe('mine');
});
