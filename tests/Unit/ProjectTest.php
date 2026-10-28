<?php

use App\Classes\Project;

it('derives postgres-safe database names', function () {
	expect(Project::databaseName('My-App.v2'))->toBe('my_appv2')
		->and(Project::databaseName('goby-cms'))->toBe('goby_cms');
});

it('replaces, uncomments, and appends .env values', function () {
	$env = <<<'ENV'
	APP_NAME=Laravel
	DB_CONNECTION=sqlite
	# DB_HOST=127.0.0.1
	# DB_PORT=3306
	CACHE_STORE=database
	ENV;

	$result = Project::applyEnvValues($env, [
		'APP_NAME' => '"my-app"',
		'DB_CONNECTION' => 'pgsql',
		'DB_HOST' => '127.0.0.1',
		'DB_PORT' => 5432,
		'CACHE_STORE' => 'redis',
		'DB_PASSWORD' => '"pa$$\\1word"',
	]);

	expect($result)->toBe(<<<'ENV'
	APP_NAME="my-app"
	DB_CONNECTION=pgsql
	DB_HOST=127.0.0.1
	DB_PORT=5432
	CACHE_STORE=redis
	DB_PASSWORD="pa$$\1word"

	ENV);
});
