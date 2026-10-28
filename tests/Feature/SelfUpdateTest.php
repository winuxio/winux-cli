<?php

use App\Classes\Releases;
use App\Classes\UpdateCheck;
use App\Http\Integrations\GitHub\Requests\ListReleasesRequest;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function () {
	$this->root = winuxSandbox();
	$this->binary = "{$this->root}/bin/winux";
	mkdir(dirname($this->binary));
	file_put_contents($this->binary, 'winux 1.0.0');
	config(['app.version' => 'v1.0.0', 'winux.binary' => $this->binary]);

	// Releases: 1.2.0 (asset 100), 1.1.0 (101), 1.0.0 (102)
	MockClient::global([
		ListReleasesRequest::class => MockResponse::make(githubReleases([
			['v1.2.0', false, "## [1.2.0](https://example.test) (2026-10-01)\n\n### Features\n\n* **site:** add site:php ([abc1234](https://example.test/c))"],
			['v1.1.0', false, "### Bug Fixes\n\n* keep APP_NAME on import"],
			['v1.0.0'],
		])),
		'*/releases/assets/100' => MockResponse::make('winux 1.2.0'),
		'*/releases/assets/200' => MockResponse::make(hash('sha256', 'winux 1.2.0') . '  winux'),
		'*/releases/assets/101' => MockResponse::make('winux 1.1.0'),
		'*/releases/assets/201' => MockResponse::make(hash('sha256', 'winux 1.1.0') . '  winux'),
	]);

	// `php <file> --version` reports whatever version the file contains
	Process::fake(function (PendingProcess $process) {
		$command = (array) $process->command;
		if (in_array('--version', $command, true)) {
			return preg_match('/winux (\S+)/', (string) @file_get_contents($command[1]), $m)
				? Process::result(" v{$m[1]}\n")
				: Process::result(exitCode: 1);
		}

		return Process::result();
	});
});

afterEach(fn () => MockClient::destroyGlobal());

function installedWith(): ?string
{
	$installed = null;
	Process::assertRan(function ($process) use (&$installed) {
		$command = (array) $process->command;
		if ($command[0] === 'mv') {
			$installed = file_exists($command[1]) ? file_get_contents($command[1]) : 'consumed';
		}
		return true;
	});

	return $installed;
}

it('updates to the latest release, keeps the old binary, and shows what changed', function () {
	// The fake mv doesn't move anything, so capture the downloaded file before it's cleaned up
	$moved = null;
	Process::fake(function (PendingProcess $process) use (&$moved) {
		$command = (array) $process->command;
		if ($command[0] === 'mv') {
			$moved = file_get_contents($command[1]);
		}
		return in_array('--version', $command, true) ? Process::result(" v1.2.0\n") : Process::result();
	});

	Artisan::call('self-update');
	$output = Artisan::output();

	expect($moved)->toBe('winux 1.2.0')
		->and(file_get_contents(Releases::previousBinary()))->toBe('winux 1.0.0')
		->and($output)->toContain('1.0.0 → 1.2.0')
		->toContain('site: add site:php')
		->toContain('keep APP_NAME on import')
		->toContain('Updated winux to 1.2.0')
		->not->toContain('abc1234')
		->not->toContain('https://example.test');
});

it('only reports with --check', function () {
	Artisan::call('self-update', ['--check' => true]);

	expect(Artisan::output())->toContain('1.0.0 → 1.2.0');
	Process::assertNotRan(fn ($process) => ((array) $process->command)[0] === 'mv');
	expect(UpdateCheck::cached()['latest'])->toBe('1.2.0');
});

it('installs a pinned version', function () {
	Artisan::call('self-update', ['target' => 'v1.1.0']);

	expect(Artisan::output())->toContain('Updated winux to 1.1.0');
	Process::assertRan(fn ($process) => ((array) $process->command)[0] === 'mv' && ((array) $process->command)[2] === $this->binary);
});

it('says when already up to date', function () {
	config(['app.version' => 'v1.2.0']);

	Artisan::call('self-update');

	expect(Artisan::output())->toContain('up to date');
	Process::assertNotRan(fn ($process) => ((array) $process->command)[0] === 'mv');
});

it('refuses a binary that does not report the expected version', function () {
	MockClient::destroyGlobal();
	MockClient::global([
		ListReleasesRequest::class => MockResponse::make(githubReleases([['v1.2.0']])),
		'*/releases/assets/100' => MockResponse::make('winux 0.9.0'),
		'*/releases/assets/200' => MockResponse::make(hash('sha256', 'winux 0.9.0')),
	]);

	expect(Artisan::call('self-update'))->toBe(1)
		->and(Artisan::output())->toContain('failed to run');
	Process::assertNotRan(fn ($process) => ((array) $process->command)[0] === 'mv');
});

it('rolls back to the previous binary, and rolling back again undoes it', function () {
	file_put_contents(Releases::previousBinary(), 'winux 0.9.0');

	expect(Artisan::call('self-update', ['--rollback' => true]))->toBe(0)
		->and(Artisan::output())->toContain('Rolled back to winux 0.9.0')
		->and(file_get_contents(Releases::previousBinary()))->toBe('winux 1.0.0');
});

it('switches channels', function () {
	Artisan::call('self-update', ['--channel' => 'beta', '--check' => true]);
	expect(Releases::channel())->toBe('beta');

	expect(Artisan::call('self-update', ['--channel' => 'nightly']))->toBe(1);
});
