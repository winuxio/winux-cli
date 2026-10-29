<?php

namespace App\Classes;

use App\Services\PhpFpm;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\error;

/**
 * /etc/sudoers.d/winux: lets winux check and reload nginx and PHP-FPM without asking for a password.
 * Only these exact commands, with these exact arguments, so the rule can't be used to run anything else.
 */
class Sudoers
{
	/** Where sudo looks for commands (Ubuntu's and Debian's default secure_path), in order */
	protected const SECURE_PATH = ['/usr/local/sbin', '/usr/local/bin', '/usr/sbin', '/usr/bin', '/sbin', '/bin'];

	public static function path(): string
	{
		return (string) config('winux.paths.sudoers', '/etc/sudoers.d/winux');
	}

	/**
	 * The commands (as winux runs them, after `sudo`) that run without a password.
	 *
	 * @return array<int, string[]>
	 */
	public static function commands(): array
	{
		// Without systemd, checking whether a service runs also goes through sudo
		$actions = Platform::hasSystemd() ? ['reload', 'restart', 'start'] : ['reload', 'restart', 'start', 'status'];

		$commands = [['nginx', '-t']];
		foreach ($actions as $action) {
			$commands[] = static::serviceCommand('nginx', $action);
		}

		foreach (PhpFpm::installedVersions() as $version) {
			$commands[] = ["php-fpm$version", '-t'];
			foreach ($actions as $action) {
				$commands[] = static::serviceCommand("php$version-fpm", $action);
			}
		}

		return $commands;
	}

	/** The same command Service::control() runs */
	protected static function serviceCommand(string $service, string $action): array
	{
		return Platform::hasSystemd() ? ['systemctl', $action, $service] : ['service', $service, $action];
	}

	/**
	 * The sudoers file for $user, with each program resolved the way sudo resolves it.
	 */
	public static function contents(string $user): string
	{
		$rules = collect(static::commands())
			->map(fn (array $command) => ($program = static::resolve($command[0])) ? implode(' ', [$program, ...array_slice($command, 1)]) : null)
			->filter()
			->unique()
			->implode(", \\\n    ");

		return "# Managed by winux (`winux sudoers`; remove with `winux sudoers --remove`).\n"
			. "# Lets winux check and reload nginx and PHP-FPM without a password: these exact commands only.\n"
			. "$user ALL=(root) NOPASSWD: \\\n    $rules\n";
	}

	/**
	 * Commands that would still ask for a password. sudo -k ignores cached credentials for the check
	 * (without clearing them), so a recent password prompt doesn't count as passwordless.
	 *
	 * @return string[]
	 */
	public static function missing(): array
	{
		return collect(static::commands())
			->reject(fn (array $command) => Process::run(['sudo', '-n', '-k', '-l', ...$command])->successful())
			->map(fn (array $command) => implode(' ', $command))
			->values()
			->all();
	}

	/**
	 * Write the file for the current user: checked with visudo first, so a bad file can't break sudo.
	 */
	public static function install(): bool
	{
		$user = ServiceUser::resolve();
		if ($user === null) {
			return false;
		}

		$temp = tempnam(sys_get_temp_dir(), 'winux-sudoers');
		File::put($temp, static::contents($user['name']));

		try {
			$check = Process::run(['sudo', 'visudo', '-cf', $temp]);
			if ($check->failed()) {
				error('The sudoers rules failed visudo\'s check, so nothing was installed: ' . trim($check->errorOutput() ?: $check->output()));
				return false;
			}

			$install = Process::run(['sudo', 'install', '-m', '0440', '-o', 'root', '-g', 'root', $temp, static::path()]);
			if ($install->failed()) {
				error("Couldn't install " . static::path() . ': ' . trim($install->errorOutput()));
				return false;
			}
		} finally {
			@unlink($temp);
		}

		return true;
	}

	/** Whether you turned the rules down (in setup, or with `winux sudoers --remove`), so doctor leaves them be */
	public static function declined(): bool
	{
		return Settings::get('sudoers.declined', false) === true;
	}

	public static function setDeclined(bool $declined): void
	{
		Settings::set('sudoers.declined', $declined);
	}

	/** Whether winux's rules are in place (nginx -t is in every version of them) */
	public static function isInstalled(): bool
	{
		return Process::run(['sudo', '-n', '-k', '-l', 'nginx', '-t'])->successful();
	}

	public static function remove(): bool
	{
		return Process::run(['sudo', 'rm', '-f', static::path()])->successful();
	}

	/** The absolute path sudo runs for $program, or null when it isn't installed */
	protected static function resolve(string $program): ?string
	{
		foreach (config('winux.sudo_secure_path', static::SECURE_PATH) as $directory) {
			if (is_file("$directory/$program") && is_executable("$directory/$program")) {
				return "$directory/$program";
			}
		}

		return null;
	}
}
