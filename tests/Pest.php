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
	// A set-up machine already has its mkcert CA (faked mkcert processes can't create one)
	File::ensureDirectoryExists("$base/ssl/root");
	File::put("$base/ssl/root/rootCA.pem", 'fake ca');
	File::put("$base/ssl/root/rootCA-key.pem", 'fake key');
	foreach ($phpVersions as $version) {
		File::ensureDirectoryExists("$root/etc/php/$version/fpm");
		File::put("$root/etc/php/$version/fpm/php-fpm.conf", '');
	}

	config([
		'winux.paths.home' => $root,
		'winux.paths.base' => $base,
		'winux.php.root' => "$root/etc/php",
		'winux.php.bin' => "$root/usr/bin",
		'winux.nginx.pid' => "$root/run/nginx.pid",
		'winux.nginx.default_root' => "$root/var/www/html",
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

/**
 * A GitHub releases API payload. Each release: [tag, prerelease, notes, withBinary = true].
 * Asset ids are derived from the position: binary 100 + i, checksum 200 + i.
 */
function githubReleases(array $releases): array
{
	return collect($releases)->values()->map(fn (array $release, int $i) => [
		'tag_name' => $release[0],
		'prerelease' => $release[1] ?? false,
		'draft' => false,
		'body' => $release[2] ?? '',
		'html_url' => "https://github.com/winuxio/winux-cli/releases/tag/{$release[0]}",
		'assets' => ($release[3] ?? true) ? [
			['id' => 100 + $i, 'name' => 'winux'],
			['id' => 200 + $i, 'name' => 'winux.sha256'],
		] : [],
	])->all();
}

/** What Laravel Prompts (note(), warning(), …) printed while $callback ran */
function captureOutput(callable $callback): string
{
	$output = new \Symfony\Component\Console\Output\BufferedOutput();
	\Laravel\Prompts\Prompt::setOutput($output);

	try {
		$callback();
	} finally {
		\Laravel\Prompts\Prompt::setOutput(new \Symfony\Component\Console\Output\ConsoleOutput());
	}

	return $output->fetch();
}
