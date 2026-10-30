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
]);

it('installs Reverb without its own npm step, then adds the Echo packages itself', function (bool $npm) {
	fakePackageProcesses(npm: $npm);

	expect(Packages::Reverb->install($this->project, 'php8.4'))->toBeTrue();

	$expected = [['php8.4', 'artisan', 'install:broadcasting', '--reverb', '--composer=/usr/local/bin/composer', '--without-node', '--no-interaction']];
	if ($npm) {
		$expected[] = ['npm', 'install', '--save-dev', 'laravel-echo', 'pusher-js'];
	}
	expect(packageCommands())->toBe($expected);
})->with(['npm' => true, 'no npm' => false]);

it('says when a package made Composer downgrade Guzzle', function () {
	$lock = fn (string $version) => File::put("{$this->project}/composer.lock", json_encode(['packages' => [['name' => 'guzzlehttp/guzzle', 'version' => $version]]]));
	$lock('8.3.1');
	Process::fake(function (PendingProcess $process) use ($lock) {
		if (in_array('install:broadcasting', (array) $process->command, true)) {
			$lock('7.16.1');
		}
		return Process::result();
	});

	$output = captureOutput(fn () => Packages::Reverb->install($this->project, 'php8.4'));

	expect($output)->toContain('guzzlehttp/guzzle from 8.3.1 to 7.16.1');
});

it('wires Passport into the User model and config/auth.php, once', function () {
	fakePackageProcesses();
	File::ensureDirectoryExists("{$this->project}/app/Models");
	File::ensureDirectoryExists("{$this->project}/config");
	File::copy(base_path('tests/Fixtures/laravel/User.php'), "{$this->project}/app/Models/User.php");
	File::copy(base_path('tests/Fixtures/laravel/auth.php'), "{$this->project}/config/auth.php");

	Packages::Passport->install($this->project, 'php8.4');
	Packages::Passport->install($this->project, 'php8.4');

	$user = File::get("{$this->project}/app/Models/User.php");
	expect($user)->toContain("use Laravel\\Passport\\HasApiTokens;\nuse Laravel\\Passport\\Contracts\\OAuthenticatable;\n")
		->toContain('class User extends Authenticatable implements OAuthenticatable')
		->toContain('    use HasApiTokens, HasFactory, Notifiable;')
		->and(substr_count($user, 'HasApiTokens'))->toBe(2);

	$auth = require "{$this->project}/config/auth.php";
	expect($auth['guards'])->toBe([
		'web' => ['driver' => 'session', 'provider' => 'users'],
		'api' => ['driver' => 'passport', 'provider' => 'users'],
	]);
});

it('adds to an implements list the User model already has', function () {
	fakePackageProcesses();
	File::ensureDirectoryExists("{$this->project}/app/Models");
	File::put("{$this->project}/app/Models/User.php", str_replace('class User extends Authenticatable', 'class User extends Authenticatable implements MustVerifyEmail', File::get(base_path('tests/Fixtures/laravel/User.php'))));

	Packages::Passport->install($this->project, 'php8.4');

	expect(File::get("{$this->project}/app/Models/User.php"))->toContain('class User extends Authenticatable implements OAuthenticatable, MustVerifyEmail');
});

it('gives the User model Sanctum tokens, or says how when it cannot', function () {
	fakePackageProcesses();
	File::ensureDirectoryExists("{$this->project}/app/Models");
	File::copy(base_path('tests/Fixtures/laravel/User.php'), "{$this->project}/app/Models/User.php");

	Packages::Sanctum->install($this->project, 'php8.4');
	expect(File::get("{$this->project}/app/Models/User.php"))->toContain('use Laravel\\Sanctum\\HasApiTokens;')->toContain('use HasApiTokens, HasFactory, Notifiable;');

	File::put("{$this->project}/app/Models/User.php", "<?php\nclass User {}\n");
	expect(captureOutput(fn () => Packages::Sanctum->install($this->project, 'php8.4')))->toContain('Add the Laravel\\Sanctum\\HasApiTokens trait');
});

it("won't install Passport and Sanctum together", function () {
	expect(Packages::conflictError([Packages::Sanctum, Packages::Passport]))->toContain('Pick either Sanctum or Passport')
		->and(Packages::conflictError([Packages::Sanctum, Packages::Filament]))->toBeNull();
});

it("gives the panel a theme with Socialment's styles and builds it", function () {
	fakePackageProcesses();
	File::ensureDirectoryExists("{$this->project}/resources/css/filament/admin");
	File::put("{$this->project}/resources/css/filament/admin/theme.css", "@import '../../../../vendor/filament/filament/resources/css/theme.css';\n");
	File::copy(base_path('tests/Fixtures/laravel/vite.config.js'), "{$this->project}/vite.config.js");
	File::put("{$this->project}/app/Providers/Filament/AdminPanelProvider.php", "        return \$panel\n            ->id('admin')\n            ->login()\n            ->colors([]);\n");

	expect(Packages::Socialment->install($this->project, 'php8.4'))->toBeTrue();
	Packages::Socialment->install($this->project, 'php8.4');

	expect(File::get("{$this->project}/resources/css/filament/admin/theme.css"))->toEndWith("@source '../../../../vendor/chrisreedio/socialment/resources';\n")
		->and(substr_count(File::get("{$this->project}/resources/css/filament/admin/theme.css"), 'socialment'))->toBe(1)
		->and(substr_count(File::get("{$this->project}/app/Providers/Filament/AdminPanelProvider.php"), "            ->viteTheme('resources/css/filament/admin/theme.css')\n"))->toBe(1)
		->and(substr_count(File::get("{$this->project}/vite.config.js"), 'filament/admin/theme.css'))->toBe(1)
		->and(packageCommands())->toContain(['npm', 'run', 'build']);
});

it('creates the theme with Filament when the panel has none', function () {
	fakePackageProcesses();
	File::put("{$this->project}/app/Providers/Filament/AdminPanelProvider.php", "            ->login()\n");

	Packages::Socialment->install($this->project, 'php8.4');

	expect(packageCommands())->toContain(['php8.4', 'artisan', 'make:filament-theme', 'admin', '--pm=npm', '--no-interaction']);
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
