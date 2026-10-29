<?php

use App\Classes\Releases;
use App\Classes\Settings;
use App\Http\Integrations\GitHub\Requests\ListReleasesRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(fn () => MockClient::destroyGlobal());

it('lists releases with a binary, newest first, ignoring drafts', function () {
	$payload = githubReleases([
		['v1.0.0'],
		['v1.2.0-beta.1', true],
		['v1.1.0'],
		['v1.3.0', false, '', false], // published, binary not attached yet
	]);
	$payload[] = ['tag_name' => 'v9.0.0', 'draft' => true, 'assets' => [['id' => 1, 'name' => 'winux']]];

	$releases = Releases::parse($payload);

	expect(array_column($releases, 'version'))->toBe(['1.2.0-beta.1', '1.1.0', '1.0.0'])
		->and($releases[1])->toMatchArray(['tag' => 'v1.1.0', 'binary' => 102, 'checksum' => 202, 'prerelease' => false]);
});

it('picks the latest release for a channel', function () {
	$releases = Releases::parse(githubReleases([['v1.1.0'], ['v1.2.0-beta.2', true], ['v1.2.0-beta.10', true]]));

	expect(Releases::latest($releases)['version'])->toBe('1.1.0')
		->and(Releases::latest($releases, 'beta')['version'])->toBe('1.2.0-beta.10')
		->and(Releases::latest([], 'beta'))->toBeNull();
});

it('lists the releases an update brings', function () {
	$releases = Releases::parse(githubReleases([['v1.0.0'], ['v1.1.0'], ['v1.2.0-beta.1', true], ['v1.2.0'], ['v1.3.0']]));

	expect(array_column(Releases::between($releases, '1.0.0', '1.2.0'), 'version'))->toBe(['1.2.0', '1.1.0'])
		->and(array_column(Releases::between($releases, '1.0.0', '1.2.0', 'beta'), 'version'))->toBe(['1.2.0', '1.2.0-beta.1', '1.1.0']);
});

it('finds a release with or without the v prefix', function () {
	$releases = Releases::parse(githubReleases([['v1.0.0'], ['v1.1.0']]));

	expect(Releases::find($releases, 'v1.0.0')['tag'])->toBe('v1.0.0')
		->and(Releases::find($releases, '1.1.0')['tag'])->toBe('v1.1.0')
		->and(Releases::find($releases, '2.0.0'))->toBeNull();
});

it('only treats semver builds as having a current version', function () {
	config(['app.version' => 'v1.2.3']);
	expect(Releases::current())->toBe('1.2.3');

	config(['app.version' => 'dev-abc1234']);
	expect(Releases::current())->toBeNull()
		->and(Releases::isNewer('1.0.0', null))->toBeFalse();
});

it('parses sha256sum output', function () {
	$hash = hash('sha256', 'x');

	expect(Releases::parseChecksum("$hash  winux\n"))->toBe($hash)
		->and(Releases::parseChecksum(strtoupper($hash)))->toBe($hash);

	Releases::parseChecksum('<html>not found</html>');
})->throws(RuntimeException::class);

it('verifies downloads against the published checksum', function () {
	MockClient::global([
		'*/releases/assets/100' => MockResponse::make('the-binary'),
		'*/releases/assets/200' => MockResponse::make(hash('sha256', 'the-binary') . "  winux\n"),
		'*/releases/assets/101' => MockResponse::make('tampered'),
		'*/releases/assets/201' => MockResponse::make(hash('sha256', 'the-binary') . "  winux\n"),
	]);
	$releases = Releases::parse(githubReleases([['v1.1.0'], ['v1.0.0']]));

	[$path, $verified] = Releases::download($releases[0]);
	expect(file_get_contents($path))->toBe('the-binary')->and($verified)->toBeTrue();
	unlink($path);

	Releases::download($releases[1]);
})->throws(RuntimeException::class, 'Checksum mismatch');

it('remembers the update channel', function () {
	winuxSandbox();
	expect(Releases::channel())->toBe('stable');

	Settings::set('update.channel', 'beta');
	expect(Releases::channel())->toBe('beta');

	Settings::set('update.channel', 'nightly');
	expect(Releases::channel())->toBe('stable');
});

it('fetches releases from the GitHub API', function () {
	$mock = MockClient::global([ListReleasesRequest::class => MockResponse::make(githubReleases([['v1.0.0']]))]);

	expect(Releases::all()[0]['version'])->toBe('1.0.0');
	$mock->assertSent(fn ($request) => $request->resolveEndpoint() === '/repos/winuxio/winux-cli/releases');
});

it('requires a checksum from 0.2.0 on, and only allows older releases without one', function () {
	MockClient::global([
		'*/releases/assets/100' => MockResponse::make('new-binary'),
		'*/releases/assets/101' => MockResponse::make('old-binary'),
	]);
	$releases = array_map(
		fn (array $release) => ['checksum' => null] + $release,
		Releases::parse(githubReleases([['v0.2.1'], ['v0.0.1', true]]))
	);

	[$path, $verified] = Releases::download($releases[1]);
	expect(file_get_contents($path))->toBe('old-binary')->and($verified)->toBeFalse();
	unlink($path);

	Releases::download($releases[0]);
})->throws(RuntimeException::class, 'no published checksum');
