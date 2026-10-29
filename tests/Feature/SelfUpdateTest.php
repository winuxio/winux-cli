<?php

use App\Classes\Releases;
use App\Classes\UpdateCheck;
use App\Http\Integrations\GitHub\Requests\ListReleasesRequest;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

beforeEach(function () {
	$this->root = winuxSandbox();
	$this->binary = "{$this->root}/bin/winux";
	mkdir(dirname($this->binary));
	file_put_contents($this->binary, 'winux 1.0.0');
	config(['app.version' => 'v1.0.0', 'winux.binary' => $this->binary]);

	$this->tokens = ['GH_TOKEN' => getenv('GH_TOKEN'), 'GITHUB_TOKEN' => getenv('GITHUB_TOKEN')];
	putenv('GH_TOKEN');
	putenv('GITHUB_TOKEN');

	// Releases: 1.2.0 (asset 100), 1.1.0 (101), 1.0.0 (102)
	MockClient::destroyGlobal();
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

	Process::preventStrayProcesses();
	$this->replaced = fakeProcesses();
});

afterEach(function () {
	MockClient::destroyGlobal();
	@chmod(dirname($this->binary), 0755);
	foreach ($this->tokens as $name => $value) {
		putenv($value === false ? $name : "$name=$value");
	}
});

/**
 * Fakes `php <file> --version` (reporting whatever version the file contains) and the step that
 * replaces the binary (`mv`, or `sudo install` when it isn't writable), which exits with
 * $exitCode. Returns a record of each replace: the command, its source file's contents and timeout.
 */
function fakeProcesses(int $exitCode = 0): ArrayObject
{
	$replaced = new ArrayObject;

	Process::fake(function (PendingProcess $process) use ($replaced, $exitCode) {
		$command = (array) $process->command;

		if (in_array('--version', $command, true)) {
			return preg_match('/winux (\S+)/', (string) @file_get_contents($command[1]), $m)
				? Process::result(" v{$m[1]}\n")
				: Process::result(exitCode: 1);
		}

		if (in_array($command[0], ['mv', 'sudo'], true)) {
			$source = $command[0] === 'mv' ? $command[1] : $command[4];
			$replaced[] = [
				'command' => $command,
				'source' => $source,
				'contents' => @file_get_contents($source),
				'timeout' => $process->timeout,
			];

			return $exitCode === 0
				? Process::result()
				: Process::result(errorOutput: "sudo: 3 incorrect password attempts\n", exitCode: $exitCode);
		}

		return Process::result(exitCode: 127);
	});

	return $replaced;
}

/**
 * Makes the binary's directory read-only, so self-update has to use sudo.
 */
function requireSudo(string $binary): void
{
	if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
		test()->markTestSkipped('Running as root, so every file is writable.');
	}

	chmod(dirname($binary), 0555);
}

/**
 * Leftover winux.previous.* stash files in ~/.winux.
 */
function stashedCopies(): array
{
	return glob(dirname(Releases::previousBinary()) . '/winux.previous.*') ?: [];
}

it('updates to the latest release, keeps the old binary, and shows what changed', function () {
	Artisan::call('self-update');
	$output = Artisan::output();

	expect($this->replaced)->toHaveCount(1)
		->and($this->replaced[0]['command'])->toBe(['mv', $this->replaced[0]['source'], $this->binary])
		->and($this->replaced[0]['contents'])->toBe('winux 1.2.0')
		->and($this->replaced[0]['timeout'])->toBeNull()
		->and(file_get_contents(Releases::previousBinary()))->toBe('winux 1.0.0')
		->and(stashedCopies())->toBe([])
		->and($this->replaced[0]['source'])->not->toBeFile()
		->and(UpdateCheck::cached()['latest'])->toBeNull()
		->and($output)->toContain('1.0.0 → 1.2.0')
		->toContain('site: add site:php')
		->toContain('keep APP_NAME on import')
		->toContain('Updated winux to 1.2.0')
		->not->toContain('abc1234')
		->not->toContain('https://example.test');
});

it('uses sudo, without a timeout, when the binary is not writable', function () {
	requireSudo($this->binary);

	expect(Artisan::call('self-update'))->toBe(0)
		->and($this->replaced[0]['command'])->toBe(['sudo', 'install', '-m', '0755', $this->replaced[0]['source'], $this->binary])
		->and($this->replaced[0]['timeout'])->toBeNull()
		->and($this->replaced[0]['source'])->not->toBeFile()
		->and(file_get_contents(Releases::previousBinary()))->toBe('winux 1.0.0');
});

it('leaves the binary and the saved previous version alone when the replace fails', function (bool $sudo) {
	if ($sudo) {
		requireSudo($this->binary);
	}
	file_put_contents(Releases::previousBinary(), 'winux 0.9.0');
	$this->replaced = fakeProcesses(exitCode: 1);

	expect(Artisan::call('self-update'))->toBe(1)
		->and(Artisan::output())->toContain('incorrect password')->toContain('Nothing was changed')
		->and($this->replaced[0]['command'][0])->toBe($sudo ? 'sudo' : 'mv')
		->and(file_get_contents($this->binary))->toBe('winux 1.0.0')
		->and(file_get_contents(Releases::previousBinary()))->toBe('winux 0.9.0')
		->and($this->replaced[0]['source'])->not->toBeFile()
		->and(stashedCopies())->toBe([])
		->and(UpdateCheck::cached()['latest'])->toBe('1.2.0');
})->with(['mv' => false, 'sudo install' => true]);

it('cleans up when the replace throws', function () {
	file_put_contents(Releases::previousBinary(), 'winux 0.9.0');
	$sources = [];
	Process::fake(function (PendingProcess $process) use (&$sources) {
		$command = (array) $process->command;
		if (in_array('--version', $command, true)) {
			return Process::result(' v' . substr((string) file_get_contents($command[1]), 6) . "\n");
		}
		$sources[] = $command[1];
		throw new RuntimeException('The process exceeded the timeout.');
	});

	expect(Artisan::call('self-update'))->toBe(1)
		->and(Artisan::output())->toContain('exceeded the timeout')
		->and(file_get_contents(Releases::previousBinary()))->toBe('winux 0.9.0')
		->and($sources[0])->not->toBeFile()
		->and(stashedCopies())->toBe([]);
});

it('only reports with --check', function () {
	Artisan::call('self-update', ['--check' => true]);

	expect(Artisan::output())->toContain('1.0.0 → 1.2.0')
		->and($this->replaced)->toHaveCount(0)
		->and(UpdateCheck::cached()['latest'])->toBe('1.2.0');
});

it('installs a pinned version', function () {
	Artisan::call('self-update', ['target' => 'v1.1.0']);

	expect(Artisan::output())->toContain('Updated winux to 1.1.0')
		->and($this->replaced[0]['command'][2])->toBe($this->binary)
		->and($this->replaced[0]['contents'])->toBe('winux 1.1.0');
});

it('says when already up to date', function () {
	config(['app.version' => 'v1.2.0']);

	Artisan::call('self-update');

	expect(Artisan::output())->toContain('up to date')
		->and($this->replaced)->toHaveCount(0);
});

it('refuses a binary that does not report the expected version', function () {
	MockClient::destroyGlobal();
	MockClient::global([
		ListReleasesRequest::class => MockResponse::make(githubReleases([['v1.2.0']])),
		'*/releases/assets/100' => MockResponse::make('winux 0.9.0'),
		'*/releases/assets/200' => MockResponse::make(hash('sha256', 'winux 0.9.0')),
	]);

	expect(Artisan::call('self-update'))->toBe(1)
		->and(Artisan::output())->toContain('failed to run')
		->and($this->replaced)->toHaveCount(0);
});

it('rolls back to the previous binary, and rolling back again undoes it', function () {
	file_put_contents(Releases::previousBinary(), 'winux 0.9.0');

	expect(Artisan::call('self-update', ['--rollback' => true]))->toBe(0)
		->and(Artisan::output())->toContain('Rolled back to winux 0.9.0')
		->and($this->replaced[0]['contents'])->toBe('winux 0.9.0')
		->and(file_get_contents(Releases::previousBinary()))->toBe('winux 1.0.0')
		->and($this->replaced[0]['source'])->not->toBeFile()
		->and(stashedCopies())->toBe([]);
});

it('keeps the saved previous version when a rollback fails', function (bool $sudo) {
	if ($sudo) {
		requireSudo($this->binary);
	}
	file_put_contents(Releases::previousBinary(), 'winux 0.9.0');
	$this->replaced = fakeProcesses(exitCode: 1);

	expect(Artisan::call('self-update', ['--rollback' => true]))->toBe(1)
		->and(Artisan::output())->toContain('Nothing was changed')
		->and(file_get_contents(Releases::previousBinary()))->toBe('winux 0.9.0')
		->and(file_get_contents($this->binary))->toBe('winux 1.0.0')
		->and($this->replaced[0]['source'])->not->toBeFile()
		->and(stashedCopies())->toBe([]);
})->with(['mv' => false, 'sudo install' => true]);

it('switches channels', function () {
	Artisan::call('self-update', ['--channel' => 'beta', '--check' => true]);
	expect(Releases::channel())->toBe('beta');

	expect(Artisan::call('self-update', ['--channel' => 'nightly']))->toBe(1);
});

it('explains GitHub failures without a stack trace', function (MockResponse $response, string $message) {
	MockClient::destroyGlobal();
	MockClient::global([ListReleasesRequest::class => $response]);

	expect(Artisan::call('self-update'))->toBe(1)
		->and(Artisan::output())->toContain($message)->not->toContain('Response:')
		->and($this->replaced)->toHaveCount(0);
})->with([
	'unreachable' => [
		MockResponse::make()->throw(fn ($pending) => new FatalRequestException(new Exception('cURL error 6: Could not resolve host'), $pending)),
		"GitHub couldn't be reached",
	],
	'rate limited' => [
		MockResponse::make(['message' => 'API rate limit exceeded for 203.0.113.1.'], 403, ['X-RateLimit-Remaining' => '0']),
		'rate limit was reached',
	],
	'server error' => [
		MockResponse::make('<html>oops</html>', 502),
		'HTTP 502',
	],
]);

it('retries without a GitHub token that is rejected', function () {
	putenv('GH_TOKEN=expired-token');
	MockClient::destroyGlobal();
	$mock = MockClient::global([
		ListReleasesRequest::class => fn (PendingRequest $request) => $request->headers()->get('Authorization')
			? MockResponse::make(['message' => 'Bad credentials'], 401)
			: MockResponse::make(githubReleases([['v1.0.0']])),
	]);

	expect(Artisan::call('self-update'))->toBe(0)
		->and(Artisan::output())->toContain('up to date');
	$mock->assertSentCount(2);
});

it('fails cleanly when GitHub rejects the request even without a token', function () {
	putenv('GITHUB_TOKEN=expired-token');
	MockClient::destroyGlobal();
	MockClient::global([ListReleasesRequest::class => MockResponse::make(['message' => 'Bad credentials'], 401)]);

	expect(Artisan::call('self-update'))->toBe(1)
		->and(Artisan::output())->toContain('GitHub rejected the request (401)');
});

it('fails silently in the background check', function (MockResponse $response) {
	MockClient::destroyGlobal();
	MockClient::global([ListReleasesRequest::class => $response]);

	expect(Artisan::call('update:refresh'))->toBe(1)
		->and(Artisan::output())->toBe('');
})->with([
	'unreachable' => MockResponse::make()->throw(fn ($pending) => new FatalRequestException(new Exception('timeout'), $pending)),
	'rate limited' => MockResponse::make(['message' => 'API rate limit exceeded'], 403, ['X-RateLimit-Remaining' => '0']),
]);
