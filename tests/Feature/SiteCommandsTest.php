<?php

use App\Classes\Site;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
	$this->nginxTest = Process::result();
	Process::fake([
		'*nginx* *-t*' => fn () => $this->nginxTest,
		'*' => Process::result(),
	]);
	$this->root = winuxSandbox(['8.3', '8.4']);
	$this->domain = legacySite($this->root);
	$this->configFile = "{$this->root}/.winux/sites/available/{$this->domain}";
});

it('lists sites with their php version and path', function () {
	expect(Artisan::call('site:list'))->toBe(0)
		->and(Artisan::output())
		->toContain('legacy-app.local.winux.io')
		->toMatch('/│ 8\.4\s+│/')
		->toContain("{$this->root}/projects/legacy-app");
});

it('switches a site to another installed php version', function () {
	$this->artisan('site:php', ['version' => '8.3', '--site' => 'legacy-app'])->assertSuccessful();

	$contents = File::get($this->configFile);
	expect($contents)->toContain('php8.3-fpm.sock')
		->and($contents)->toContain('listen 127.0.0.1:9444 ssl;')
		->and(File::glob("{$this->root}/.winux/backups/{$this->domain}.*.conf"))->toHaveCount(1);

	Process::assertRan(fn ($process) => $process->command === ['sudo', 'nginx', '-t']);
	Process::assertRan(fn ($process) => in_array('reload', (array) $process->command, true));
});

it('finds the site from the current directory', function () {
	$cwd = getcwd();
	chdir("{$this->root}/projects/legacy-app/public");

	try {
		$this->artisan('site:php', ['version' => '8.3'])->assertSuccessful();
	} finally {
		chdir($cwd);
	}

	expect(Site::find('legacy-app')->phpVersion())->toBe('8.3');
});

it('restores the previous config when nginx rejects the change', function () {
	$this->nginxTest = Process::result(errorOutput: 'nginx: [emerg] boom', exitCode: 1);
	$before = File::get($this->configFile);

	$this->artisan('site:php', ['version' => '8.3', '--site' => 'legacy-app'])->assertFailed();

	expect(File::get($this->configFile))->toBe($before);
});

it('adds extra domains and reissues the certificate for all of them', function () {
	$this->artisan('site:domain:add', [
		'domains' => ['api.legacy-app.local.winux.io', 'Legacy.TEST'],
		'--site' => 'legacy-app',
		'--no-hosts' => true,
	])->assertSuccessful();

	expect(Site::find('legacy-app')->domains())
		->toBe(['legacy-app.local.winux.io', 'api.legacy-app.local.winux.io', 'legacy.test']);

	Process::assertRan(fn ($process) => ($process->command[0] ?? null) === 'mkcert'
		&& array_slice($process->command, -3) === ['legacy-app.local.winux.io', 'api.legacy-app.local.winux.io', 'legacy.test']
		&& ($process->environment['CAROOT'] ?? null) === "{$this->root}/.winux/ssl/root");
});

it('refuses a domain another site already uses', function () {
	legacySite($this->root, 'other-app');
	Site::find('other-app')->apply(Site::find('other-app')->config()->withDomains(['other-app.local.winux.io', 'taken.test']));

	$this->artisan('site:domain:add', ['domains' => ['taken.test'], '--site' => 'legacy-app', '--no-hosts' => true])
		->expectsOutputToContain('already belongs to other-app.local.winux.io')
		->assertFailed();
});

it('removes extra domains but never the primary one', function () {
	$this->artisan('site:domain:add', ['domains' => ['api.legacy-app.local.winux.io'], '--site' => 'legacy-app'])->assertSuccessful();

	$this->artisan('site:domain:remove', ['domains' => ['legacy-app.local.winux.io'], '--site' => 'legacy-app'])->assertFailed();

	$this->artisan('site:domain:remove', ['domains' => ['api.legacy-app.local.winux.io'], '--site' => 'legacy-app'])->assertSuccessful();

	expect(Site::find('legacy-app')->domains())->toBe(['legacy-app.local.winux.io']);
});
