<?php

namespace App\Classes;

use Illuminate\Support\Facades\Process;

/**
 * Detects the host we're running on: WSL vs native, distro, and init system.
 */
class Platform
{
	protected static ?array $osRelease = null;

	protected static ?bool $wsl = null;

	protected static ?bool $systemd = null;

	/**
	 * True inside a real WSL distro, where Windows (powershell.exe, the Windows hosts file) is reachable.
	 * A WSL kernel alone isn't enough: Docker containers on WSL also report "microsoft" in
	 * /proc/version, but have no interop with Windows.
	 */
	public static function isWsl(): bool
	{
		if (static::$wsl !== null) {
			return static::$wsl;
		}

		return getenv('WSL_DISTRO_NAME') !== false && getenv('WSL_DISTRO_NAME') !== ''
			|| is_dir('/run/WSL')
			|| !empty(glob('/proc/sys/fs/binfmt_misc/WSLInterop*'));
	}

	public static function osRelease(): array
	{
		if (static::$osRelease !== null) {
			return static::$osRelease;
		}

		$values = [];
		$contents = @file_get_contents('/etc/os-release') ?: '';
		foreach (explode("\n", $contents) as $line) {
			if (preg_match('/^([A-Z_]+)=(.*)$/', trim($line), $matches)) {
				$values[$matches[1]] = trim($matches[2], "\"'");
			}
		}

		return static::$osRelease = $values;
	}

	/** 'ubuntu', 'debian', etc. */
	public static function distro(): string
	{
		return strtolower(static::osRelease()['ID'] ?? 'unknown');
	}

	/** 'noble', 'bookworm', etc. */
	public static function codename(): string
	{
		$release = static::osRelease();
		return $release['VERSION_CODENAME'] ?? $release['UBUNTU_CODENAME'] ?? '';
	}

	public static function prettyName(): string
	{
		return static::osRelease()['PRETTY_NAME'] ?? static::distro();
	}

	public static function isUbuntu(): bool
	{
		return static::distro() === 'ubuntu';
	}

	public static function isDebian(): bool
	{
		return static::distro() === 'debian';
	}

	public static function isSupported(): bool
	{
		return static::isUbuntu() || static::isDebian();
	}

	public static function hasSystemd(): bool
	{
		return static::$systemd ?? is_dir('/run/systemd/system');
	}

	public static function commandExists(string $command): bool
	{
		return Process::run(['sh', '-c', 'command -v ' . escapeshellarg($command)])->successful();
	}

	/** For tests; null goes back to reading /etc/os-release */
	public static function fake(?array $osRelease): void
	{
		static::$osRelease = $osRelease;
	}

	/** For tests; null goes back to detecting */
	public static function fakeEnvironment(?bool $wsl = null, ?bool $systemd = null): void
	{
		static::$wsl = $wsl;
		static::$systemd = $systemd;
	}
}
