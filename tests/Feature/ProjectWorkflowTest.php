<?php

use App\Classes\Platform;
use App\Classes\Scaffold;
use App\Classes\Site;
use App\Services\Postgres;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Fakes every process for project:create / project:import. `composer create-project` creates the
 * directory (with an .env.example) like the real one would. $state:
 *  - gitIdentity: whether `git config --get user.*` answers
 *  - fail: a command name (e.g. 'install', 'key:generate', 'migrate') whose process fails
 *  - gitRemote: whether `git remote` lists one
 */
function fakeWorkflowProcesses(array $state = []): void
{
	$state += ['gitIdentity' => true, 'fail' => null, 'gitRemote' => false];

	Process::fake(function (PendingProcess $process) use ($state) {
		$command = (array) $process->command;

		if ($state['fail'] && in_array($state['fail'], $command, true)) {
			return Process::result(output: "{$state['fail']} output", errorOutput: "{$state['fail']} went wrong", exitCode: 1);
		}

		if (($command[0] ?? null) === 'pg_lsclusters') {
			return Process::result("16 main 5433 online postgres x y\n");
		}

		if (($command[0] ?? null) === 'sh' && str_contains($command[2] ?? '', 'command -v composer')) {
			return Process::result("/usr/local/bin/composer\n");
		}

		if (in_array('create-project', $command, true)) {
			$target = end($command);
			$path = str_starts_with($target, '/') ? $target : getcwd() . '/' . $target;
			File::ensureDirectoryExists("$path/public");
			File::put("$path/.env.example", "APP_NAME=Laravel\nAPP_KEY=\nDB_CONNECTION=sqlite\n");
			File::put("$path/README.md", "# Laravel\n");
			File::put("$path/.gitignore", "/vendor\n/node_modules\n.env\n");
			return Process::result();
		}

		if (($command[0] ?? null) === 'git' && ($command[1] ?? null) === 'remote') {
			return Process::result($state['gitRemote'] ? "origin\n" : '');
		}

		if (($command[0] ?? null) === 'git' && ($command[1] ?? null) === 'config') {
			return $state['gitIdentity'] ? Process::result("someone\n") : Process::result(exitCode: 1);
		}

		return Process::result();
	});
}

function ran(callable $matches): bool
{
	// assertNotRan visits every recorded process (and, unlike assertRan, passes when none ran)
	$found = false;
	Process::assertNotRan(function ($process) use ($matches, &$found) {
		$found = $found || $matches((array) $process->command, $process);
		return false;
	});
	return $found;
}

beforeEach(function () {
	$this->root = winuxSandbox(['8.3', '8.4']);
	config(['winux.nginx.config' => "{$this->root}/nginx.conf"]);
	File::put("{$this->root}/nginx.conf", "http {\n\tinclude /etc/nginx/conf.d/*.conf;\n}\n");
	Platform::fakeEnvironment(wsl: false, systemd: true);
	Postgres::flushClusterCache();

	File::ensureDirectoryExists("{$this->root}/work");
	$this->cwd = getcwd();
	chdir("{$this->root}/work");
});

afterEach(function () {
	chdir($this->cwd);
	Postgres::flushClusterCache();
});

#region project:create
it('creates a project with --no-interaction: migrations, but no git, GitHub, packages or openers', function () {
	fakeWorkflowProcesses();

	expect(Artisan::call('project:create', ['projectName' => 'blog', '--no-interaction' => true]))->toBe(0)
		->and(Artisan::output())->toContain('blog is live at https://blog.local.winux.io');

	expect(ran(fn ($c) => in_array('migrate', $c, true)))->toBeTrue()
		->and(ran(fn ($c) => ($c[0] ?? null) === 'git'))->toBeFalse()
		->and(ran(fn ($c) => ($c[0] ?? null) === 'gh'))->toBeFalse()
		->and(ran(fn ($c) => in_array('filament/filament', $c, true)))->toBeFalse()
		->and(ran(fn ($c) => in_array($c[0] ?? null, ['code', 'explorer.exe', 'phpstorm64.exe'], true)))->toBeFalse();
});

it('creates the repository, GitHub repo and packages the options ask for', function () {
	fakeWorkflowProcesses();

	expect(Artisan::call('project:create', ['projectName' => 'blog', '--github' => 'private', '--filament' => true, '--no-interaction' => true]))->toBe(0);

	expect(ran(fn ($c) => $c === ['git', 'init', '-b', 'main']))->toBeTrue()
		->and(ran(fn ($c) => $c === ['git', 'commit', '-m', 'Initial commit']))->toBeTrue()
		->and(ran(fn ($c) => ($c[0] ?? null) === 'gh' && in_array('--private', $c, true)))->toBeTrue()
		->and(ran(fn ($c) => in_array('filament/filament', $c, true)))->toBeTrue()
		->and(ran(fn ($c) => $c === ['git', 'commit', '-m', 'Installed Filament']))->toBeTrue();
});

it('adds the app around the files already in the directory', function () {
	fakeWorkflowProcesses();
	$project = "{$this->root}/work/blog";
	File::ensureDirectoryExists("$project/docs");
	File::put("$project/docs/plan.md", "# Plan\n");
	File::put("$project/README.md", "# Blog\n");
	File::put("$project/.gitignore", "/vendor\n.DS_Store\n");
	chdir($project);

	expect(Artisan::call('project:create', ['projectName' => '.', '--no-interaction' => true]))->toBe(0)
		->and(Artisan::output())->toContain('blog is live at https://blog.local.winux.io')->toContain('Kept your README.md, .gitignore');

	// composer installed beside the directory, not into it
	expect(ran(fn ($c) => in_array('create-project', $c, true) && str_starts_with(end($c), "{$this->root}/work/.blog.winux-")))->toBeTrue();

	expect(File::get("$project/README.md"))->toBe("# Blog\n")
		->and(File::get("$project/docs/plan.md"))->toBe("# Plan\n")
		->and(File::get("$project/.gitignore"))->toBe("/vendor\n.DS_Store\n\n# Added with the Laravel app\n/node_modules\n.env\n")
		->and(is_dir("$project/public"))->toBeTrue()
		->and(glob("{$this->root}/work/.blog.winux-*"))->toBe([]);
});

it('takes back out only the app when deleting a project created around your files', function () {
	fakeWorkflowProcesses();
	$project = "{$this->root}/work/blog";
	File::ensureDirectoryExists("$project/docs");
	File::put("$project/docs/plan.md", "# Plan\n");
	File::put("$project/README.md", "# Blog\n");
	File::put("$project/.gitignore", "/vendor\n.DS_Store\n");
	chdir($project);
	Artisan::call('project:create', ['projectName' => '.', '--no-interaction' => true]);
	File::put("$project/phpstan.neon", 'later');

	expect(Artisan::call('project:delete', ['projectName' => '.', '--force' => true, '--delete-files' => true, '--no-interaction' => true]))->toBe(0)
		->and(Artisan::output())->toContain('your own files are still there')->toContain('Also left in place')->toContain('phpstan.neon');

	expect(Scaffold::entries($project))->toEqualCanonicalizing(['docs', 'README.md', '.gitignore', 'phpstan.neon'])
		->and(File::get("$project/.gitignore"))->toBe("/vendor\n.DS_Store\n")
		->and(Scaffold::recorded($project))->toBeNull();
});

it('stops before touching anything when existing files clash with the app', function () {
	fakeWorkflowProcesses();
	$project = "{$this->root}/work/blog";
	File::ensureDirectoryExists("$project/public");
	File::put("$project/public/index.html", 'mine');

	expect(Artisan::call('project:create', ['projectName' => 'blog', '--no-interaction' => true]))->toBe(1)
		->and(Artisan::output())->toContain('already has public, which the Laravel app also needs');

	expect(File::get("$project/public/index.html"))->toBe('mine')
		->and(Scaffold::entries($project))->toBe(['public'])
		->and(glob("{$this->root}/work/.blog.winux-*"))->toBe([])
		->and(glob("{$this->root}/.winux/sites/available/*"))->toBe([]);
});

it('commits to the repository a directory already has instead of creating one', function () {
	fakeWorkflowProcesses(['gitRemote' => true]);
	File::ensureDirectoryExists("{$this->root}/work/blog/.git");

	expect(Artisan::call('project:create', ['projectName' => 'blog', '--github' => 'private', '--no-interaction' => true]))->toBe(0)
		->and(Artisan::output())->toContain('already has a remote');

	expect(ran(fn ($c) => $c === ['git', 'commit', '-m', 'Add Laravel app']))->toBeTrue()
		->and(ran(fn ($c) => ($c[1] ?? null) === 'init'))->toBeFalse()
		->and(ran(fn ($c) => ($c[0] ?? null) === 'gh'))->toBeFalse();
});

it('asks before adding the app to a directory with files', function () {
	fakeWorkflowProcesses();
	File::ensureDirectoryExists("{$this->root}/work/blog");
	File::put("{$this->root}/work/blog/README.md", "# Blog\n");

	$this->artisan('project:create', ['projectName' => 'blog'])
		->expectsConfirmation('Add a Laravel app to this directory?', 'no')
		->expectsOutputToContain('Cancelled. Nothing was created.')
		->assertFailed();

	expect(ran(fn ($c) => in_array('create-project', $c, true)))->toBeFalse();
});

it('asks whether the name of the directory you are in means this directory', function () {
	fakeWorkflowProcesses();
	File::ensureDirectoryExists("{$this->root}/work/blog");
	File::put("{$this->root}/work/blog/README.md", "# Blog\n");
	chdir("{$this->root}/work/blog");

	$this->artisan('project:create', ['projectName' => 'blog', '--php' => 'abc'])
		->expectsConfirmation("You're already in blog. Create the project in this directory?", 'yes')
		->assertFailed();

	// --php=abc stops it right after choosing the directory
	expect(ran(fn ($c) => in_array('create-project', $c, true)))->toBeFalse();
});

it('points a directory that already holds a Laravel app at project:import', function () {
	fakeWorkflowProcesses();
	File::ensureDirectoryExists("{$this->root}/work/blog");
	File::put("{$this->root}/work/blog/artisan", '');
	File::put("{$this->root}/work/blog/composer.json", '{}');

	expect(Artisan::call('project:create', ['projectName' => 'blog', '--no-interaction' => true]))->toBe(1)
		->and(Artisan::output())->toContain('is already a Laravel app')->toContain("winux project:import {$this->root}/work/blog");

	expect(ran(fn ($c) => in_array('create-project', $c, true)))->toBeFalse();
});

it('needs a project name without a terminal', function () {
	fakeWorkflowProcesses();

	expect(Artisan::call('project:create', ['--no-interaction' => true]))->toBe(1)
		->and(Artisan::output())->toContain('Pass a project name, or . to create the project in the current directory.');
});

it('creates a project at a path', function () {
	fakeWorkflowProcesses();
	File::ensureDirectoryExists("{$this->root}/elsewhere");

	expect(Artisan::call('project:create', ['projectName' => '../elsewhere/shop', '--no-interaction' => true]))->toBe(0)
		->and(Artisan::output())->toContain("cd {$this->root}/elsewhere/shop");

	expect(is_dir("{$this->root}/elsewhere/shop/public"))->toBeTrue()
		->and(File::get("{$this->root}/.winux/sites/available/shop.local.winux.io"))->toContain("{$this->root}/elsewhere/shop/public");
});

it('skips committing (and GitHub) when git has no identity', function () {
	fakeWorkflowProcesses(['gitIdentity' => false]);

	expect(Artisan::call('project:create', ['projectName' => 'blog', '--github' => 'public', '--no-interaction' => true]))->toBe(0)
		->and(Artisan::output())->toContain("git doesn't know who you are yet")->toContain('Skipped creating the GitHub repository');

	expect(ran(fn ($c) => $c === ['git', 'init', '-b', 'main']))->toBeTrue()
		->and(ran(fn ($c) => ($c[1] ?? null) === 'commit'))->toBeFalse()
		->and(ran(fn ($c) => ($c[0] ?? null) === 'gh'))->toBeFalse();
});

it('rejects unknown --github and --package values before creating anything', function (array $options, string $message) {
	fakeWorkflowProcesses();

	expect(Artisan::call('project:create', ['projectName' => 'blog', '--no-interaction' => true, ...$options]))->toBe(1)
		->and(Artisan::output())->toContain($message);

	expect(ran(fn ($c) => in_array('create-project', $c, true)))->toBeFalse();
})->with([
	[['--github' => 'secret'], "--github must be 'private' or 'public'"],
	[['--package' => ['nova']], "Unknown package 'nova'"],
]);

it('does not claim the project is live when migrations fail', function () {
	fakeWorkflowProcesses(['fail' => 'migrate']);

	expect(Artisan::call('project:create', ['projectName' => 'blog', '--no-interaction' => true]))->toBe(0)
		->and(Artisan::output())->not->toContain('is live')->toContain('but migrations failed');
});
#endregion

#region project:import
it('installs composer dependencies and generates APP_KEY for a fresh checkout', function () {
	fakeWorkflowProcesses();
	File::ensureDirectoryExists("{$this->root}/work/shop/public");
	File::put("{$this->root}/work/shop/composer.json", '{}');
	File::put("{$this->root}/work/shop/artisan", '');
	File::put("{$this->root}/work/shop/.env.example", "APP_KEY=\n");
	// The site's PHP CLI
	File::ensureDirectoryExists("{$this->root}/usr/bin");
	File::put("{$this->root}/usr/bin/php8.4", '');
	chmod("{$this->root}/usr/bin/php8.4", 0755);
	$php = "{$this->root}/usr/bin/php8.4";

	expect(Artisan::call('project:import', ['projectName' => 'shop', '--php' => '8.4', '--no-interaction' => true]))->toBe(0)
		->and(Artisan::output())->toContain('shop is live');

	expect(ran(fn ($c) => $c === [$php, '/usr/local/bin/composer', 'install', '--no-interaction']))->toBeTrue()
		->and(ran(fn ($c) => $c === [$php, 'artisan', 'key:generate', '--force', '--no-interaction']))->toBeTrue()
		// Not asked for, and an existing app's database may matter
		->and(ran(fn ($c) => in_array('migrate', $c, true)))->toBeFalse();
});

it('leaves vendor and APP_KEY alone when they are already there', function () {
	fakeWorkflowProcesses();
	File::ensureDirectoryExists("{$this->root}/work/shop/vendor");
	File::put("{$this->root}/work/shop/vendor/autoload.php", '');
	File::put("{$this->root}/work/shop/composer.json", '{}');
	File::put("{$this->root}/work/shop/artisan", '');
	File::put("{$this->root}/work/shop/.env", "APP_KEY=base64:abc\n");

	expect(Artisan::call('project:import', ['projectName' => 'shop', '--migrate' => true, '--no-interaction' => true]))->toBe(0);

	expect(ran(fn ($c) => in_array('install', $c, true)))->toBeFalse()
		->and(ran(fn ($c) => in_array('key:generate', $c, true)))->toBeFalse()
		->and(ran(fn ($c) => in_array('migrate', $c, true)))->toBeTrue();
});

it('stops without claiming the project is live when composer install fails', function () {
	fakeWorkflowProcesses(['fail' => 'install']);
	File::ensureDirectoryExists("{$this->root}/work/shop/public");
	File::put("{$this->root}/work/shop/composer.json", '{}');
	File::put("{$this->root}/work/shop/.env", "APP_KEY=\n");

	expect(Artisan::call('project:import', ['projectName' => 'shop', '--no-interaction' => true]))->toBe(1)
		->and(Artisan::output())->toContain('`composer install` failed')->not->toContain('is live');
});
#endregion

#region site:php
function platformCheckSite(string $root): string
{
	$domain = legacySite($root);
	File::ensureDirectoryExists("$root/projects/legacy-app/vendor/composer");
	File::put("$root/projects/legacy-app/vendor/composer/platform_check.php", '<?php');
	File::ensureDirectoryExists("$root/usr/bin");
	File::put("$root/usr/bin/php8.3", '');
	chmod("$root/usr/bin/php8.3", 0755);
	return $domain;
}

function fakePlatformCheck(bool $passes): void
{
	Process::fake(function (PendingProcess $process) use ($passes) {
		$command = (array) $process->command;
		if (($command[1] ?? null) === '-r' && str_contains($command[2] ?? '', 'platform_check.php')) {
			return $passes ? Process::result() : Process::result(
				errorOutput: "Composer detected issues in your platform:\n\nYour Composer dependencies require a PHP version \">= 8.4.1\". You are running 8.3.27.\n\nPHP Fatal error:  Uncaught RuntimeException: Composer detected issues in your platform: ... in /x/vendor/composer/platform_check.php:22\n",
				exitCode: 255,
			);
		}
		return Process::result();
	});
}

it('refuses to switch php when the locked dependencies need a newer version', function () {
	$domain = platformCheckSite($this->root);
	fakePlatformCheck(passes: false);

	expect(Artisan::call('site:php', ['version' => '8.3', '--site' => 'legacy-app', '--no-interaction' => true]))->toBe(1)
		->and(Artisan::output())
		->toContain('Your Composer dependencies require a PHP version ">= 8.4.1". You are running 8.3.27.')
		->toContain('Pass --force to switch anyway')
		->not->toContain('PHP Fatal error');

	expect(Site::find('legacy-app')->phpVersion())->toBe('8.4');
	Process::assertRan(fn ($process) => ($process->command[0] ?? null) === "{$this->root}/usr/bin/php8.3" && $process->path === "{$this->root}/projects/legacy-app");
});

it('switches anyway with --force, or after confirming', function () {
	platformCheckSite($this->root);
	fakePlatformCheck(passes: false);

	$this->artisan('site:php', ['version' => '8.3', '--site' => 'legacy-app'])
		->expectsConfirmation('Switch to PHP 8.3 anyway?', 'yes')
		->assertSuccessful();
	expect(Site::find('legacy-app')->phpVersion())->toBe('8.3');

	expect(Artisan::call('site:php', ['version' => '8.4', '--site' => 'legacy-app', '--no-interaction' => true]))->toBe(0);
	expect(Artisan::call('site:php', ['version' => '8.3', '--site' => 'legacy-app', '--force' => true, '--no-interaction' => true]))->toBe(0);
	expect(Site::find('legacy-app')->phpVersion())->toBe('8.3');
});

it('switches without asking when the platform check passes', function () {
	platformCheckSite($this->root);
	fakePlatformCheck(passes: true);

	expect(Artisan::call('site:php', ['version' => '8.3', '--site' => 'legacy-app', '--no-interaction' => true]))->toBe(0);
	expect(Site::find('legacy-app')->phpVersion())->toBe('8.3');
});
#endregion
