<?php

namespace App\Classes;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Throwable;

/**
 * Daily check for a newer release. The check runs in a detached background process,
 * so commands never wait on GitHub; the result is shown after the next command.
 */
class UpdateCheck
{
	public const INTERVAL = 86400;

	/** Commands that never show the notice */
	protected const QUIET_COMMANDS = ['self-update', 'update:refresh', 'list', 'help', 'completion', '_complete'];

	public static function path(): string
	{
		return config('winux.paths.base') . DIRECTORY_SEPARATOR . 'update-check.json';
	}

	public static function cached(): array
	{
		$path = static::path();

		return is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
	}

	public static function store(array $values): void
	{
		File::ensureDirectoryExists(dirname(static::path()));
		File::put(static::path(), json_encode($values + static::cached(), JSON_PRETTY_PRINT) . "\n");
	}

	public static function isStale(?int $now = null): bool
	{
		$checked = static::cached()['checked_at'] ?? 0;

		return ($now ?? time()) - $checked >= static::INTERVAL;
	}

	/**
	 * Fetches the newest release for the user's channel and caches it.
	 */
	public static function refresh(): ?string
	{
		$latest = Releases::latest(Releases::all(), Releases::channel());
		static::store(['checked_at' => time(), 'latest' => $latest['version'] ?? null]);

		return $latest['version'] ?? null;
	}

	/**
	 * The newer version that's available, according to the last check.
	 */
	public static function available(): ?string
	{
		$latest = static::cached()['latest'] ?? null;

		return $latest && Releases::isNewer($latest, Releases::current()) ? $latest : null;
	}

	public static function enabled(): bool
	{
		return Settings::get('update.check', true) !== false
			&& !getenv('WINUX_NO_UPDATE_CHECK')
			&& !getenv('CI');
	}

	public static function afterCommand(CommandFinished $event): void
	{
		try {
			if (
				!static::enabled()
				|| Releases::binary() === null
				|| Releases::current() === null
				|| in_array($event->command, [null, ...static::QUIET_COMMANDS], true)
				|| !$event->output->isDecorated()
				|| $event->output->isQuiet()
			) {
				return;
			}

			if (static::isStale()) {
				static::refreshInBackground();
			}

			if ($version = static::available()) {
				$output = $event->output instanceof ConsoleOutputInterface ? $event->output->getErrorOutput() : $event->output;
				$output->writeln('');
				$output->writeln(sprintf(
					'  <fg=yellow>winux %s is available</> <fg=gray>(you have %s). Run</> <options=bold>winux self-update</> <fg=gray>to update.</>',
					$version,
					Releases::current()
				));
			}
		} catch (Throwable) {
			// Never let the update check break a command
		}
	}

	protected static function refreshInBackground(): void
	{
		// Marked as checked up front so concurrent commands don't each spawn a check
		static::store(['checked_at' => time()]);

		Process::run([
			'sh', '-c', 'setsid "$@" >/dev/null 2>&1 </dev/null &', 'sh',
			PHP_BINARY, Releases::binary(), 'update:refresh',
		]);
	}
}
