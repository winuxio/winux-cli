<?php

use App\Classes\Settings;
use App\Services\PhpFpm;
use App\Services\Postgres;
use Illuminate\Support\Facades\Process;

it('sorts php versions numerically', function () {
	expect(PhpFpm::sortVersions(['8.10', '8.4', '7.4', '8.4', '8.9']))->toBe(['7.4', '8.4', '8.9', '8.10']);
});

it('finds installed php-fpm versions', function () {
	winuxSandbox(['8.5', '8.1', '8.4']);

	expect(PhpFpm::installedVersions())->toBe(['8.1', '8.4', '8.5']);
});

it('defaults php to the configured version, then the newest installed', function () {
	winuxSandbox(['8.3', '8.5']);
	config(['winux.php.default' => '8.4']);
	expect(PhpFpm::defaultVersion())->toBe('8.5');

	Settings::set('php.default', '8.3');
	expect(PhpFpm::defaultVersion())->toBe('8.3');
});

it('targets the versioned systemd unit and socket', function () {
	$fpm = PhpFpm::make('8.3');

	expect($fpm->label())->toBe('PHP 8.3 FPM')
		->and($fpm->socket())->toBe('/run/php/php8.3-fpm.sock');
});

it('rejects malformed php versions', function () {
	PhpFpm::make('8.4; rm -rf /');
})->throws(InvalidArgumentException::class);

it('parses pg_lsclusters output', function () {
	$output = "16 main 5433 down   postgres /var/lib/postgresql/16/main /var/log/postgresql/postgresql-16-main.log\n"
		. "18 main 5432 online postgres /var/lib/postgresql/18/main /var/log/postgresql/postgresql-18-main.log\n"
		. "18 test 5434 online postgres /var/lib/postgresql/18/test /var/log/postgresql/postgresql-18-test.log\n";

	expect(Postgres::parseClusters($output))->toBe([
		'16' => ['port' => 5433, 'status' => 'down'],
		'18' => ['port' => 5432, 'status' => 'online'],
	]);
});

it('defaults postgres to the chosen version, else the newest installed', function () {
	winuxSandbox();
	Postgres::flushClusterCache();
	Process::preventStrayProcesses();
	Process::fake(['*pg_lsclusters*' => Process::result("14 main 5433 online postgres x y\n17 main 5432 online postgres x y\n")]);

	expect(Postgres::defaultVersion())->toBe('17');

	Settings::set('postgres.default', '14');
	expect(Postgres::defaultVersion())->toBe('14')
		->and(Postgres::make()->getPort())->toBe(5433);

	Postgres::flushClusterCache();
});

it('quotes identifiers and literals', function () {
	expect(Postgres::identifier('my"db'))->toBe('"my""db"')
		->and(Postgres::literal("it's"))->toBe("'it''s'");
});
