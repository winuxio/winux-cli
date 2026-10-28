<?php

$home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/root';

return [
	'paths' => [
		'home' => $home,
		'winux' => '.winux',
		'base' => $home . DIRECTORY_SEPARATOR . '.winux',
		'sites' => [
			'available' => 'sites/available',
			'enabled' => 'sites/enabled',
		],
	],

	'nginx' => [
		'config' => '/etc/nginx/nginx.conf',
		'domain' => 'local.winux.io',
	],

	'php' => [
		// Used when nothing has been chosen via `winux php:default`
		'default' => '8.4',
		'root' => '/etc/php',
		// Older versions are hidden from php:list / php:install prompts
		'minimum' => '8.1',
		// Extensions installed alongside each php{version}-fpm package
		'extensions' => [
			'cli', 'common', 'bcmath', 'curl', 'gd', 'intl', 'mbstring', 'mysql',
			'pgsql', 'readline', 'redis', 'sqlite3', 'xml', 'zip',
		],
	],

	'postgres' => [
		// Installed by `winux setup` / `service:install postgres` when no version is given.
		// Projects use `winux postgres:default`, else the newest installed version.
		'default' => '16',
		'supported' => ['14', '15', '16', '17', '18'],
	],

	'ssl' => [
		// mkcert's CAROOT. The legacy winux_root.* files are adopted as rootCA*.pem
		'root' => [
			'cert' => 'rootCA.pem',
			'key' => 'rootCA-key.pem',
			'legacy_cert' => 'winux_root.crt',
			'legacy_key' => 'winux_root.key',
		],
	],

	'github' => [
		'repository' => 'winuxio/winux-cli',
		'asset' => 'winux',
	],

	// The binary self-update replaces; null means the running PHAR
	'binary' => null,
];
