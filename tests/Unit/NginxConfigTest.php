<?php

use App\Classes\NginxConfig;

function legacyConfig(): NginxConfig
{
	return new NginxConfig(str_replace(
		['{{BASE}}', '{{PROJECT}}'],
		['/home/dev/.winux', '/home/dev/code/legacy-app'],
		file_get_contents(__DIR__ . '/../Fixtures/legacy-site.conf')
	));
}

it('reads domains, php version, and root from a legacy config', function () {
	$config = legacyConfig();

	expect($config->domains())->toBe(['legacy-app.local.winux.io'])
		->and($config->phpVersion())->toBe('8.4')
		->and($config->root())->toBe('/home/dev/code/legacy-app/public');
});

it('changes only the php-fpm socket when switching versions', function () {
	$original = legacyConfig();
	$updated = $original->withPhpVersion('8.3');

	expect($updated->phpVersion())->toBe('8.3')
		->and($updated->contents)->toContain('unix:/var/run/php/php8.3-fpm.sock')
		->and($updated->contents)->toContain('listen 127.0.0.1:9444 ssl;')
		->and(str_replace('php8.3-fpm', 'php8.4-fpm', $updated->contents))->toBe($original->contents);
});

it('updates every server_name and redirects to the requested host', function () {
	$updated = legacyConfig()->withDomains(['legacy-app.local.winux.io', 'legacy.test', 'api.legacy.test']);

	expect($updated->domains())->toBe(['legacy-app.local.winux.io', 'legacy.test', 'api.legacy.test'])
		->and(substr_count($updated->contents, 'server_name legacy-app.local.winux.io legacy.test api.legacy.test;'))->toBe(2)
		->and($updated->contents)->toContain('return 302 https://$host$request_uri;')
		->and($updated->contents)->not->toContain('$server_name$request_uri')
		->and($updated->contents)->toContain('listen 127.0.0.1:9444 ssl;');
});

it('round-trips removing extra domains', function () {
	$config = legacyConfig()
		->withDomains(['legacy-app.local.winux.io', 'legacy.test'])
		->withDomains(['legacy-app.local.winux.io']);

	expect($config->domains())->toBe(['legacy-app.local.winux.io']);
});
