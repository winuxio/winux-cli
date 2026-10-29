<?php

use App\Classes\Settings;
use App\Classes\UpdateCheck;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

beforeEach(function () {
	$this->env = ['CI' => getenv('CI'), 'WINUX_NO_UPDATE_CHECK' => getenv('WINUX_NO_UPDATE_CHECK')];
	putenv('CI');
	putenv('WINUX_NO_UPDATE_CHECK');

	$root = winuxSandbox();
	config(['app.version' => 'v1.0.0', 'winux.binary' => "$root/winux"]);
	Process::fake();
});

afterEach(function () {
	foreach ($this->env as $name => $value) {
		if ($value !== false) {
			putenv("$name=$value");
		}
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

it('writes the notice to stderr, keeping stdout clean for pipes', function () {
	UpdateCheck::store(['checked_at' => time(), 'latest' => '1.1.0']);

	$output = new class(OutputInterface::VERBOSITY_NORMAL, true) extends BufferedOutput implements ConsoleOutputInterface {
		public OutputInterface $stderr;

		public function __construct(int $verbosity, bool $decorated)
		{
			parent::__construct($verbosity, $decorated);
			$this->stderr = new BufferedOutput($verbosity, $decorated);
		}

		public function getErrorOutput(): OutputInterface
		{
			return $this->stderr;
		}

		public function setErrorOutput(OutputInterface $error): void
		{
			$this->stderr = $error;
		}

		public function section(): ConsoleSectionOutput
		{
			throw new LogicException('Not used');
		}
	};

	UpdateCheck::afterCommand(new CommandFinished('site:list', new ArrayInput([]), $output, 0));

	expect($output->fetch())->toBe('')
		->and($output->getErrorOutput()->fetch())->toContain('winux 1.1.0 is available');
});

it('stores the cache atomically, merging with what is there', function () {
	UpdateCheck::store(['checked_at' => 100, 'latest' => '1.1.0']);
	clearstatcache();
	$inode = fileinode(UpdateCheck::path());

	UpdateCheck::store(['checked_at' => 200]);
	clearstatcache();

	// A new file renamed into place, rather than the old one rewritten where readers could catch it half-written
	expect(fileinode(UpdateCheck::path()))->not->toBe($inode)
		->and(UpdateCheck::cached())->toBe(['checked_at' => 200, 'latest' => '1.1.0'])
		->and(glob(dirname(UpdateCheck::path()) . '/update-check.*'))->toBe([UpdateCheck::path()]);
});
