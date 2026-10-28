<?php

namespace App\Classes;

use Illuminate\Support\Facades\Process;

/**
 * Detects the host we're running on: WSL vs native, distro, and init system.
 */
class Platform
{
	protected static ?array $osRelease = null;

	public static function isWsl(): bool
	{
		if (getenv('WSL_DISTRO_NAME')) {
			return true;
		}

		$version = @file_get_contents('/proc/version') ?: '';
		return stripos($version, 'microsoft') !== false;
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
		return is_dir('/run/systemd/system');
	}

	public static function commandExists(string $command): bool
	{
		return Process::run(['sh', '-c', 'command -v ' . escapeshellarg($command)])->successful();
	}

	/** For tests */
	public static function fake(array $osRelease): void
	{
		static::$osRelease = $osRelease;
	}
}
