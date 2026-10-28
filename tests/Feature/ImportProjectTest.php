<?php

use App\Services\Postgres;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Fakes every process; Postgres answers according to $state:
 *  - envLoginWorks: whether `psql -h ...` with the .env password succeeds
 *  - databases / users: what already exists on the cluster
 */
function fakeImportProcesses(array $state): void
{
	Process::fake(function (PendingProcess $process) use ($state) {
		$command = (array) $process->command;
		$last = (string) end($command);

		if (($command[0] ?? null) === 'pg_lsclusters') {
			return Process::result("16 main 5433 online postgres x y\n");
		}

		if (($command[0] ?? null) === 'psql' && in_array('-h', $command, true)) {
			return $state['envLoginWorks'] ? Process::result('1') : Process::result(errorOutput: 'password authentication failed', exitCode: 2);
		}

		if (preg_match("/FROM pg_database WHERE datname = '(.+)'/", $last, $m)) {
			return Process::result(in_array($m[1], $state['databases'], true) ? '1' : '');
		}

		if (preg_match("/FROM pg_roles WHERE rolname = '(.+)'/", $last, $m)) {
			return Process::result(in_array($m[1], $state['users'], true) ? '1' : '');
		}

		return Process::result();
	});
}

function postgresQueries(): array
{
	$queries = [];
	Process::assertRan(function ($process) use (&$queries) {
		$command = (array) $process->command;
		if (in_array('psql', $command, true) && ($i = array_search('-c', $command, true)) !== false) {
			$queries[] = $command[$i + 1];
		}
		return true;
	});
	return $queries;
}

beforeEach(function () {
	$this->root = winuxSandbox(['8.4']);
	config(['winux.nginx.config' => "{$this->root}/nginx.conf"]);
	File::put("{$this->root}/nginx.conf", "http {\n\tinclude /etc/nginx/conf.d/*.conf;\n}\n");
	Postgres::flushClusterCache();

	$this->project = "{$this->root}/work/shop";
	File::ensureDirectoryExists("{$this->project}/public");
	File::put("{$this->project}/.env", <<<'ENV'
	APP_NAME="Shop Platform"
	APP_URL=http://localhost
	SESSION_DRIVER=database
	DB_CONNECTION=pgsql
	DB_HOST=127.0.0.1
	DB_PORT=5433
	DB_DATABASE=shop_dev
	DB_USERNAME=shop_user
	DB_PASSWORD=existing-secret

	ENV);

	$this->cwd = getcwd();
	chdir("{$this->root}/work");
});

afterEach(function () {
	chdir($this->cwd);
	Postgres::flushClusterCache();
});

it('reuses the database when the credentials in .env already work', function () {
	fakeImportProcesses(['envLoginWorks' => true, 'databases' => ['shop_dev'], 'users' => ['shop_user']]);

	$this->artisan('project:import', ['projectName' => 'shop', '--no-interaction' => true])->assertSuccessful();

	expect(postgresQueries())->toBe([]);

	$env = File::get("{$this->project}/.env");
	expect($env)
		->toContain('DB_DATABASE=shop_dev')
		->toContain('DB_USERNAME=shop_user')
		->toContain('DB_PASSWORD=existing-secret')
		->toContain('APP_URL="https://shop.local.winux.io"')
		// Settings an existing app already has are left alone; missing ones are filled in
		->toContain('APP_NAME="Shop Platform"')
		->toContain('SESSION_DRIVER=database')
		->toContain('CACHE_STORE=redis');
});

it('keeps the existing database and resets the user password when .env is stale', function () {
	fakeImportProcesses(['envLoginWorks' => false, 'databases' => ['shop_dev'], 'users' => ['shop_user']]);

	$this->artisan('project:import', ['projectName' => 'shop', '--no-interaction' => true])->assertSuccessful();

	$queries = postgresQueries();
	expect(collect($queries)->contains(fn ($q) => str_starts_with($q, 'CREATE')))->toBeFalse()
		->and(collect($queries)->contains(fn ($q) => str_starts_with($q, 'ALTER ROLE "shop_user" WITH LOGIN PASSWORD')))->toBeTrue()
		->and($queries)->toContain('ALTER DATABASE "shop_dev" OWNER TO "shop_user"');

	$env = File::get("{$this->project}/.env");
	expect($env)
		->toContain('DB_DATABASE="shop_dev"')
		->toContain('DB_USERNAME="shop_user"')
		->toContain('DB_PORT=5433')
		->not->toContain('existing-secret');
});

it('creates a user for an existing database that has none', function () {
	fakeImportProcesses(['envLoginWorks' => false, 'databases' => ['shop_dev'], 'users' => []]);

	$this->artisan('project:import', ['projectName' => 'shop', '--no-interaction' => true])->assertSuccessful();

	$queries = postgresQueries();
	expect(collect($queries)->contains(fn ($q) => str_starts_with($q, 'CREATE ROLE "shop_user"')))->toBeTrue()
		->and(collect($queries)->contains(fn ($q) => str_starts_with($q, 'CREATE DATABASE')))->toBeFalse()
		->and($queries)->toContain('GRANT ALL ON ALL TABLES IN SCHEMA public TO "shop_user"');
});

it('creates a database named after the project when .env points at nothing', function () {
	fakeImportProcesses(['envLoginWorks' => false, 'databases' => [], 'users' => []]);

	$this->artisan('project:import', ['projectName' => 'shop', '--no-interaction' => true])->assertSuccessful();

	$queries = postgresQueries();
	expect($queries)->toContain('CREATE DATABASE "shop"')
		->and(collect($queries)->contains(fn ($q) => str_starts_with($q, 'CREATE ROLE "shop"')))->toBeTrue();

	expect(File::get("{$this->project}/.env"))->toContain('DB_DATABASE="shop"');
});

it('keeps an existing site config, its php version, and its extra domains on re-import', function () {
	fakeImportProcesses(['envLoginWorks' => true, 'databases' => ['shop_dev'], 'users' => ['shop_user']]);
	File::ensureDirectoryExists("{$this->root}/etc/php/8.3/fpm");
	File::put("{$this->root}/etc/php/8.3/fpm/php-fpm.conf", '');

	$domain = legacySite($this->root, 'shop');
	$configFile = "{$this->root}/.winux/sites/available/$domain";
	File::put($configFile, str_replace(
		["server_name $domain;", 'php8.4-fpm', "{$this->root}/projects/shop"],
		["server_name $domain shop.test;", 'php8.3-fpm', $this->project],
		File::get($configFile)
	));
	$before = File::get($configFile);

	$this->artisan('project:import', ['projectName' => 'shop', '--no-interaction' => true])->assertSuccessful();

	expect(File::get($configFile))->toBe($before);

	// The certificate was missing, so it's issued for every domain the site already answers to
	Process::assertRan(fn ($process) => ($process->command[0] ?? null) === 'mkcert'
		&& array_slice((array) $process->command, -2) === [$domain, 'shop.test']);
});
