<?php

namespace App\Classes;

use Illuminate\Console\Events\CommandFinished;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Throwable;

/**
 * Changes to how winux sets up a machine, by the version that made them. A new install gets them from
 * `winux setup`; an existing one from `winux doctor --fix`, which self-update offers to run and every
 * command reminds you about until it's done.
 */
class Upgrades
{
	/**
	 * @var array<string, array{summary: string, checks: string[]}> The doctor checks that cover each change
	 */
	public const CHANGES = [
		'0.3.4' => [
			'summary' => "Creating and importing projects no longer asks for your password: Postgres gets a role named after you, and nginx/PHP-FPM reloads are allowed in /etc/sudoers.d/winux.",
			'checks' => ['Postgres without sudo', 'Passwordless nginx/PHP-FPM reloads'],
		],
		'0.3.5' => [
			'summary' => "0.3.4 could think nginx/PHP-FPM reloads were already passwordless when they weren't (if any of your sudo rules is NOPASSWD), and skip /etc/sudoers.d/winux.",
			'checks' => ['Passwordless nginx/PHP-FPM reloads'],
		],
	];

	/** Commands that apply the changes themselves, or shouldn't print anything extra */
	protected const QUIET_COMMANDS = ['doctor', 'setup', 'self-update', 'update:refresh', 'list', 'help', 'completion', '_complete'];

	/** The version whose setup changes this machine has */
	public static function applied(): ?string
	{
		return Settings::get('setup.version');
	}

	/**
	 * Changes this machine doesn't have yet. None before `winux setup` has run: setup applies them all.
	 *
	 * @return array<string, array{summary: string, checks: string[]}>
	 */
	public static function pending(): array
	{
		if (!static::isSetUp()) {
			return [];
		}

		$applied = static::applied() ?? '0.0.0';

		return array_filter(static::CHANGES, fn ($version) => version_compare($version, $applied, '>'), ARRAY_FILTER_USE_KEY);
	}

	/**
	 * The doctor checks the pending changes need to pass.
	 *
	 * @return string[]
	 */
	public static function pendingChecks(): array
	{
		return array_values(array_unique(array_merge(...array_values(array_map(fn ($change) => $change['checks'], static::pending())))));
	}

	/** Record that this machine has every change up to this version */
	public static function markApplied(): void
	{
		$latest = array_key_last(static::CHANGES);
		if (static::applied() === null || version_compare($latest, static::applied(), '>')) {
			Settings::set('setup.version', $latest);
		}
	}

	/** Whether winux has set this machine up before (as opposed to a fresh install) */
	protected static function isSetUp(): bool
	{
		return is_dir(config('winux.paths.base') . '/sites');
	}

	public static function afterCommand(CommandFinished $event): void
	{
		try {
			if (
				in_array($event->command, [null, ...static::QUIET_COMMANDS], true)
				|| !$event->output->isDecorated()
				|| $event->output->isQuiet()
				|| !static::pending()
			) {
				return;
			}

			$output = $event->output instanceof ConsoleOutputInterface ? $event->output->getErrorOutput() : $event->output;
			$output->writeln('');
			$output->writeln('  <fg=yellow>This version of winux changes how your machine is set up.</> <fg=gray>Run</> <options=bold>winux doctor --fix</> <fg=gray>to apply it.</>');
		} catch (Throwable) {
			// Never let the reminder break a command
		}
	}
}
