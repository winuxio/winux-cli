<?php

return [
	'paths' => [
		'home' => $_SERVER['HOME'],
		'winux' => '.winux',
		'sites' => [
			'available' => 'sites/available',
			'enabled' => 'sites/enabled',
		],
	],

	'nginx' => [
		'config' => '/etc/nginx/nginx.conf',
	]
];