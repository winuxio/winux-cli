<?php

use App\Classes\Project;
use App\Classes\Site;
use App\Services\Postgres;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Fakes every process. Postgres answers from $state (databases, users, superusers);
 * `nginx -t` passes unless $state['nginxError'] is set.
 */
function fakeProjectProcesses(array $state = []): void
{
	$state += ['databases' => [], 'users' => [], 'superusers' => ['postgres'], 'nginxError' => null];

	Process::fake(function (PendingProcess $process) use ($state) {
		$command = (array) $process->command;
		$sql = (string) $process->input;

		if (($command[0] ?? null) === 'pg_lsclusters') {
			return Process::result("16 main 5433 online postgres x y\n");
		}

		if (in_array('nginx', $command, true) && in_array('-t', $command, true)) {
			return $state['nginxError'] ? Process::result(errorOutput: $state['nginxError'], exitCode: 1) : Process::result();
		}

		if (preg_match("/^SELECT pg_get_userbyid\(datdba\) FROM pg_database WHERE datname = '(.+)'/", $sql, $m)) {
			return Process::result(in_array($m[1], $state['databases'], true) ? 'postgres' : '');
		}

		if (preg_match("/FROM pg_database WHERE datname = '(.+)'/", $sql, $m)) {
			return Process::result(in_array($m[1], $state['databases'], true) ? '1' : '');
		}

		if (preg_match("/WHERE rolsuper AND rolname = '(.+)'/", $sql, $m)) {
			return Process::result(in_array($m[1], $state['superusers'], true) ? '1' : '');
		}

		if (preg_match("/FROM pg_roles WHERE rolname = '(.+)'/", $sql, $m)) {
			return Process::result(in_array($m[1], $state['users'], true) ? '1' : '');
		}

		return Process::result();
	});
}

/** SQL statements (not lookups) sent to the superuser psql. */
function superuserStatements(): array
{
	$statements = [];
	Process::assertRan(function ($process) use (&$statements) {
		$command = (array) $process->command;
		if (in_array('psql', $command, true) && in_array('postgres', $command, true) && !in_array('-tA', $command, true) && !in_array('-tAc', $command, true)) {
			$statements[] = (string) $process->input;
		}
		return true;
	});
	return $statements;
}

function ranCommand(string $needle): bool
{
	$found = false;
	Process::assertRan(function ($process) use ($needle, &$found) {
		$found = $found || in_array($needle, (array) $process->command, true);
		return true;
	});
	return $found;
}

function writeEnv(string $project, array $values): void
{
	File::ensureDirectoryExists($project);
	File::put("$project/.env", collect($values)->map(fn ($value, $key) => "$key=$value")->implode("\n") . "\n");
}

beforeEach(function () {
	$this->root = winuxSandbox(['8.4']);
	Postgres::flushClusterCache();

	File::ensureDirectoryExists("{$this->root}/work");
	$this->cwd = getcwd();
	chdir("{$this->root}/work");
});

afterEach(function () {
	chdir($this->cwd);
	Postgres::flushClusterCache();
});

#region Naming
it('rejects project names that would not map cleanly to a directory, domain, and database', function (string $name) {
	fakeProjectProcesses();

	foreach (['project:create', 'project:import', 'project:delete', 'nginx:create'] as $command) {
		// The project commands take paths too; those are covered below
		if (in_array($command, ['project:create', 'project:import', 'project:delete'], true) && ($name === '..' || str_contains($name, '/'))) {
			continue;
		}
		expect(Artisan::call($command, ['projectName' => $name, '--no-interaction' => true]))->toBe(1)
			->and(Artisan::output())->toContain("isn't a valid project name");
	}

	expect(ranCommand('create-project'))->toBeFalse()
		->and(superuserStatements())->toBe([]);
})->with(['my app', 'foo/bar', '..', '.hidden', '-app', 'app_', 'my.app', 'café']);

it('refuses to import a path that is not a Laravel project', function () {
	fakeProjectProcesses();
	File::ensureDirectoryExists(getcwd() . '/foo/bar');

	expect(Artisan::call('project:import', ['projectName' => 'foo/bar', '--no-interaction' => true]))->toBe(1)
		->and(Artisan::output())->toContain('has no .env or .env.example');

	expect(glob("{$this->root}/.winux/sites/available/*"))->toBe([])
		->and(superuserStatements())->toBe([]);
});

it('never turns the home directory into a project', function (string $command, string $path) {
	fakeProjectProcesses();
	chdir($this->root);

	expect(Artisan::call($command, ['projectName' => $path, '--no-interaction' => true]))->toBe(1)
		->and(Artisan::output())->toContain('Refusing to use');

	Process::assertNotRan(fn ($process) => in_array('create-project', (array) $process->command, true));
	expect(glob("{$this->root}/.winux/sites/available/*"))->toBe([]);
})->with([
	['project:create', '.'],
	['project:import', '.'],
	['project:create', '~'],
	['project:import', 'work/..'],
]);

it('accepts letters, digits, hyphens and underscores', function () {
	expect(Project::nameError('my-app'))->toBeNull()
		->and(Project::nameError('My_App2'))->toBeNull()
		->and(Project::nameError(str_repeat('a', 64)))->toContain('63 characters')
		->and(Project::slug('My_App'))->toBe('my-app');
});

it('keeps the directory name for the path and uses a lowercase slug for the domain and database', function () {
	fakeProjectProcesses();

	$project = Project::create('My_App');

	expect($project->path)->toBe("{$this->root}/work/My_App")
		->and($project->name)->toBe('My_App')
		->and($project->getDomain())->toBe('my-app.local.winux.io')
		->and($project->site->path)->toBe("{$this->root}/work/My_App");
});

it('keeps the domain of a site that already serves the directory', function () {
	fakeProjectProcesses();
	$domain = legacySite($this->root, 'My_App');
	chdir("{$this->root}/projects");

	expect(Project::create('My_App')->getDomain())->toBe($domain);
});
#endregion

#region Collisions
it('refuses to create a project whose database another project uses', function () {
	fakeProjectProcesses(['databases' => ['my_app'], 'users' => ['my_app']]);
	legacySite($this->root, 'my_app');
	writeEnv("{$this->root}/projects/my_app", ['DB_CONNECTION' => 'pgsql', 'DB_PORT' => '5433', 'DB_DATABASE' => 'my_app', 'DB_USERNAME' => 'my_app']);

	expect(Artisan::call('project:create', ['projectName' => 'my-app', '--no-interaction' => true]))->toBe(1)
		->and(Artisan::output())->toContain("database 'my_app'")->toContain('used by my_app.local.winux.io');

	// Checked before composer creates anything, and nothing was reset
	expect(ranCommand('create-project'))->toBeFalse()
		->and(superuserStatements())->toBe([]);
});

it('ignores projects on another cluster or host when checking who uses a database', function () {
	fakeProjectProcesses(['databases' => ['my_app'], 'users' => ['my_app']]);
	legacySite($this->root, 'other');
	writeEnv("{$this->root}/projects/other", ['DB_CONNECTION' => 'pgsql', 'DB_PORT' => '5432', 'DB_DATABASE' => 'my_app']);

	expect(Project::create('my-app')->otherProjectUsing('my_app', 'my_app'))->toBeNull();
});

it('picks up the database a deleted project with the same name left behind', function () {
	fakeProjectProcesses(['databases' => ['my_app'], 'users' => ['my_app']]);

	$project = Project::create('my-app');

	// Outside a command, prompts take their default answer (see TestCase): reuse
	expect($project->claimExistingDatabase())->toBeTrue()
		->and($project->setupDatabase())->toBeTrue();

	$statements = superuserStatements();
	expect(collect($statements)->contains(fn ($sql) => str_starts_with($sql, 'CREATE')))->toBeFalse()
		->and(collect($statements)->contains(fn ($sql) => str_starts_with($sql, 'ALTER ROLE "my_app" WITH LOGIN PASSWORD')))->toBeTrue();
});

it('stops when you decline the leftover database', function () {
	fakeProjectProcesses(['databases' => ['my_app'], 'users' => ['my_app']]);

	$this->artisan('project:create', ['projectName' => 'my-app'])
		->expectsConfirmation('Reuse them? Their data is kept, and the user gets a new password.', 'no')
		->assertFailed();

	expect(ranCommand('create-project'))->toBeFalse()
		->and(superuserStatements())->toBe([]);
});

it('refuses to import onto the project-named database when another project uses it', function () {
	fakeProjectProcesses(['databases' => ['my_app'], 'users' => ['my_app']]);
	legacySite($this->root, 'my_app');
	writeEnv("{$this->root}/projects/my_app", ['DB_CONNECTION' => 'pgsql', 'DB_PORT' => '5433', 'DB_DATABASE' => 'my_app', 'DB_USERNAME' => 'my_app']);
	File::ensureDirectoryExists("{$this->root}/work/my-app/public");
	File::put("{$this->root}/work/my-app/.env.example", "APP_NAME=Laravel\n");

	expect(Artisan::call('project:import', ['projectName' => 'my-app', '--no-interaction' => true]))->toBe(1)
		->and(Artisan::output())->toContain('is used by my_app.local.winux.io');

	expect(superuserStatements())->toBe([]);
});

it('refuses postgres:create when the user already exists', function () {
	fakeProjectProcesses(['users' => ['my_app']]);

	expect(Artisan::call('postgres:create', ['database' => 'my-app']))->toBe(1)
		->and(Artisan::output())->toContain("user 'my_app' already exist");

	expect(superuserStatements())->toBe([]);
});

it('only adopts an existing database or user when asked to', function () {
	fakeProjectProcesses(['databases' => ['my_app']]);
	$postgres = Postgres::make('16');

	expect($postgres->createAll('my_app'))->toBeFalse()
		->and(superuserStatements())->toBe([])
		->and($postgres->createAll('my_app', reuse: true))->toBeTrue()
		->and(collect(superuserStatements())->contains(fn ($sql) => str_starts_with($sql, 'CREATE ROLE "my_app"')))->toBeTrue();
});
#endregion

#region Delete
it('drops the database and user from .env, not the ones named after the project', function () {
	fakeProjectProcesses(['databases' => ['shop', 'shop_dev'], 'users' => ['shop', 'shop_user']]);
	writeEnv("{$this->root}/work/shop", ['DB_CONNECTION' => 'pgsql', 'DB_PORT' => '5433', 'DB_DATABASE' => 'shop_dev', 'DB_USERNAME' => 'shop_user']);

	expect(Artisan::call('project:delete', ['projectName' => 'shop', '--force' => true, '--delete-database' => true, '--no-interaction' => true]))->toBe(0)
		->and(Artisan::output())->toContain("the database 'shop_dev' on Postgres 16")->toContain("the Postgres user 'shop_user'");

	expect(superuserStatements())->toBe([
		'DROP DATABASE IF EXISTS "shop_dev" WITH (FORCE)',
		'DROP ROLE IF EXISTS "shop_user"',
	]);

	// Files are only deleted when asked
	expect(is_dir("{$this->root}/work/shop"))->toBeTrue();
});

it('never drops the postgres database or a superuser', function (string $user) {
	fakeProjectProcesses(['databases' => ['postgres'], 'users' => ['postgres', 'admin'], 'superusers' => ['postgres', 'admin']]);
	writeEnv("{$this->root}/work/shop", ['DB_CONNECTION' => 'pgsql', 'DB_PORT' => '5433', 'DB_DATABASE' => 'postgres', 'DB_USERNAME' => $user]);

	expect(Artisan::call('project:delete', ['projectName' => 'shop', '--force' => true, '--delete-database' => true, '--no-interaction' => true]))->toBe(0)
		->and(Artisan::output())->toContain("Keeping the built-in database 'postgres'")->toContain("Keeping the superuser '$user'");

	expect(superuserStatements())->toBe([]);
})->with(['postgres', 'admin']);

it('leaves a database that is not on a winux cluster alone', function () {
	fakeProjectProcesses(['databases' => ['shop'], 'users' => ['shop']]);
	writeEnv("{$this->root}/work/shop", ['DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'db.example.com', 'DB_PORT' => '5433', 'DB_DATABASE' => 'shop']);

	Artisan::call('project:delete', ['projectName' => 'shop', '--force' => true, '--no-interaction' => true]);

	expect(Artisan::output())->toContain("isn't on a winux Postgres cluster")
		->and(superuserStatements())->toBe([]);
});

it('cleans up the site, certificate, and database after the directory is gone', function () {
	fakeProjectProcesses(['databases' => ['gone'], 'users' => ['gone']]);
	$domain = legacySite($this->root, 'gone');
	File::deleteDirectory("{$this->root}/projects/gone");
	File::ensureDirectoryExists("{$this->root}/.winux/ssl/certs/$domain");
	chdir("{$this->root}/projects");

	expect(Artisan::call('project:delete', ['projectName' => 'gone', '--force' => true, '--delete-database' => true, '--no-interaction' => true]))->toBe(0)
		->and(Artisan::output())->toContain("doesn't exist")->toContain("the nginx site $domain");

	expect(Site::make($domain)->exists())->toBeFalse()
		->and(is_link("{$this->root}/.winux/sites/enabled/$domain"))->toBeFalse()
		->and(is_dir("{$this->root}/.winux/ssl/certs/$domain"))->toBeFalse()
		->and(superuserStatements())->toContain('DROP DATABASE IF EXISTS "gone" WITH (FORCE)');
});

it('keeps the database unless asked to drop it', function () {
	fakeProjectProcesses(['databases' => ['shop'], 'users' => ['shop']]);
	File::ensureDirectoryExists("{$this->root}/work/shop");

	expect(Artisan::call('project:delete', ['projectName' => 'shop', '--force' => true, '--no-interaction' => true]))->toBe(0)
		->and(Artisan::output())->toContain("Keeping the database 'shop' on Postgres 16 and the Postgres user 'shop'")
		->toContain('winux postgres:delete shop --pg=16');

	expect(superuserStatements())->toBe([]);
});

it('asks before dropping the database', function (bool $answer) {
	fakeProjectProcesses(['databases' => ['shop'], 'users' => ['shop']]);
	File::ensureDirectoryExists("{$this->root}/work/shop");

	$this->artisan('project:delete', ['projectName' => 'shop'])
		->expectsConfirmation('Are you sure?', 'yes')
		->expectsQuestion("If you're absolutely sure, type 'shop' below.", 'shop')
		->expectsConfirmation('Also drop the database? Its data is gone for good.', $answer ? 'yes' : 'no')
		->expectsConfirmation("Do you want to delete the project directory at '{$this->root}/work/shop'?", 'no')
		->assertSuccessful();

	expect(collect(superuserStatements())->contains(fn ($sql) => str_starts_with($sql, 'DROP DATABASE')))->toBe($answer);
})->with(['drop' => true, 'keep' => false]);

it('needs --force to delete without prompts', function () {
	fakeProjectProcesses(['databases' => ['shop'], 'users' => ['shop']]);
	File::ensureDirectoryExists("{$this->root}/work/shop");

	expect(Artisan::call('project:delete', ['projectName' => 'shop', '--no-interaction' => true]))->toBe(1)
		->and(superuserStatements())->toBe([]);
});

it('deletes the files with --delete-files', function () {
	fakeProjectProcesses();
	File::ensureDirectoryExists("{$this->root}/work/shop");

	expect(Artisan::call('project:delete', ['projectName' => 'shop', '--force' => true, '--delete-files' => true, '--no-interaction' => true]))->toBe(0)
		->and(is_dir("{$this->root}/work/shop"))->toBeFalse();
});

it('fails when part of the deletion fails', function () {
	Process::fake(function (PendingProcess $process) {
		$command = (array) $process->command;
		if (($command[0] ?? null) === 'pg_lsclusters') {
			return Process::result("16 main 5433 online postgres x y\n");
		}
		if (str_contains((string) $process->input, 'DROP DATABASE')) {
			return Process::result(errorOutput: 'database is being accessed', exitCode: 3);
		}
		return Process::result(str_contains((string) $process->input, 'SELECT 1 FROM pg_database') ? '1' : '');
	});
	File::ensureDirectoryExists("{$this->root}/work/shop");

	expect(Artisan::call('project:delete', ['projectName' => 'shop', '--force' => true, '--delete-database' => true, '--no-interaction' => true]))->toBe(1);
});
#endregion

#region nginx
it('writes a quoted root and validates the new site with nginx -t', function () {
	fakeProjectProcesses();
	File::ensureDirectoryExists("{$this->root}/John Doe/work/blog/public");
	chdir("{$this->root}/John Doe/work");

	expect(Artisan::call('nginx:create', ['projectName' => 'blog', '--no-interaction' => true]))->toBe(0);

	$site = Site::make('blog.local.winux.io');
	expect(File::get($site->configFile))->toContain("root \"{$this->root}/John Doe/work/blog/public\";")
		->and($site->projectPath())->toBe("{$this->root}/John Doe/work/blog")
		->and($site->isEnabled())->toBeTrue();

	Process::assertRan(fn ($process) => $process->command === ['sudo', 'nginx', '-t']);
	expect(ranCommand('reload'))->toBeTrue();
});

it('removes a new site that nginx rejects, and does not reload', function () {
	fakeProjectProcesses(['nginxError' => 'nginx: [emerg] invalid parameter']);
	File::ensureDirectoryExists("{$this->root}/work/blog/public");

	expect(Artisan::call('nginx:create', ['projectName' => 'blog', '--no-interaction' => true]))->toBe(1)
		->and(Artisan::output())->toContain('nginx: [emerg] invalid parameter');

	expect(Site::make('blog.local.winux.io')->exists())->toBeFalse()
		->and(is_link("{$this->root}/.winux/sites/enabled/blog.local.winux.io"))->toBeFalse()
		->and(is_dir("{$this->root}/.winux/ssl/certs/blog.local.winux.io"))->toBeFalse()
		->and(ranCommand('reload'))->toBeFalse();
});

it('refuses project paths nginx cannot quote', function () {
	fakeProjectProcesses();
	File::ensureDirectoryExists("{$this->root}/we;ird/blog/public");
	chdir("{$this->root}/we;ird");

	expect(Artisan::call('nginx:create', ['projectName' => 'blog', '--no-interaction' => true]))->toBe(1)
		->and(Artisan::output())->toContain("characters nginx can't serve from");

	expect(Site::make('blog.local.winux.io')->exists())->toBeFalse();
});

it('does not claim the site is live when nginx fails to reload', function () {
	Process::fake(function (PendingProcess $process) {
		return in_array('reload', (array) $process->command, true)
			? Process::result(errorOutput: 'Job for nginx.service failed', exitCode: 1)
			: Process::result();
	});
	File::ensureDirectoryExists("{$this->root}/work/blog/public");

	expect(Artisan::call('nginx:create', ['projectName' => 'blog', '--no-interaction' => true]))->toBe(1)
		->and(Artisan::output())->not->toContain('is live');
});
#endregion
