<?php

return [
	'paths' => [
		'home' => $_SERVER['HOME'],
		'winux' => '.winux',
		'base' => $_SERVER['HOME'] . DIRECTORY_SEPARATOR . '.winux',
		'sites' => [
			'available' => 'sites/available',
			'enabled' => 'sites/enabled',
		],
	],

	'nginx' => [
		'config' => '/etc/nginx/nginx.conf',
		'domain' => 'local.winux.io'
	],

	'ssl' => [
		'root' => [
			'key' => 'winux_root.key',
			'cert' => 'winux_root.crt',
		],
	]
];