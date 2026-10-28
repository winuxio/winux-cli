<?php

use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class)->in('Feature', 'Unit');

/**
 * Points winux at a throwaway ~/.winux and /etc/php so tests never touch the real machine.
 * Returns the sandbox root.
 */
function winuxSandbox(array $phpVersions = ['8.3', '8.4']): string
{
	$root = sys_get_temp_dir() . '/winux-test-' . bin2hex(random_bytes(6));
	$base = "$root/.winux";

	File::ensureDirectoryExists("$base/sites/available");
	File::ensureDirectoryExists("$base/sites/enabled");
	foreach ($phpVersions as $version) {
		File::ensureDirectoryExists("$root/etc/php/$version/fpm");
		File::put("$root/etc/php/$version/fpm/php-fpm.conf", '');
	}

	config([
		'winux.paths.home' => $root,
		'winux.paths.base' => $base,
		'winux.php.root' => "$root/etc/php",
	]);

	test()->sandboxRoot = $root;

	return $root;
}

/**
 * Installs the legacy (pre-modernization, hand-edited) fixture as an enabled site.
 */
function legacySite(string $root, string $name = 'legacy-app'): string
{
	$domain = "$name.local.winux.io";
	$base = "$root/.winux";
	$project = "$root/projects/$name";
	File::ensureDirectoryExists("$project/public");

	$contents = str_replace(
		['{{BASE}}', '{{PROJECT}}', 'legacy-app'],
		[$base, $project, $name],
		File::get(__DIR__ . '/Fixtures/legacy-site.conf')
	);

	File::put("$base/sites/available/$domain", $contents);
	symlink("$base/sites/available/$domain", "$base/sites/enabled/$domain");

	return $domain;
}
