<?php

use App\Classes\Settings;
use App\Classes\UpdateCheck;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

beforeEach(function () {
	$this->ci = getenv('CI');
	putenv('CI');
	putenv('WINUX_NO_UPDATE_CHECK');

	$root = winuxSandbox();
	config(['app.version' => 'v1.0.0', 'winux.binary' => "$root/winux"]);
	Process::fake();
});

afterEach(function () {
	if ($this->ci !== false) {
		putenv("CI={$this->ci}");
	}
});

function finishCommand(string $command = 'site:list', bool $tty = true): string
{
	$output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, $tty);
	UpdateCheck::afterCommand(new CommandFinished($command, new ArrayInput([]), $output, 0));

	return $output->fetch();
}

it('mentions a newer version after a command', function () {
	UpdateCheck::store(['checked_at' => time(), 'latest' => '1.1.0']);

	expect(finishCommand())->toContain('winux 1.1.0 is available')->toContain('you have 1.0.0');
	Process::assertNothingRan();
});

it('stays quiet when up to date, piped, disabled, or updating', function () {
	UpdateCheck::store(['checked_at' => time(), 'latest' => '1.0.0']);
	expect(finishCommand())->toBe('');

	UpdateCheck::store(['latest' => '1.1.0']);
	expect(finishCommand(tty: false))->toBe('')
		->and(finishCommand('self-update'))->toBe('');

	Settings::set('update.check', false);
	expect(finishCommand())->toBe('');
});

it('checks for updates in the background once a day', function () {
	UpdateCheck::store(['checked_at' => time() - UpdateCheck::INTERVAL - 1]);

	finishCommand();
	finishCommand();

	Process::assertRanTimes(fn ($process) => in_array('update:refresh', (array) $process->command, true), 1);
	expect(UpdateCheck::isStale())->toBeFalse();
});

it('never checks when running from source', function () {
	config(['winux.binary' => null, 'app.version' => 'abc1234']);

	finishCommand();
	Process::assertNothingRan();
});
