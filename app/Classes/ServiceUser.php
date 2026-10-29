<?php

namespace App\Classes;

use App\Services\Nginx;
use App\Services\PhpFpm;

use function Laravel\Prompts\{error, info};

/**
 * nginx and PHP-FPM run as the user running winux (Valet-style), so they can read projects in a
 * private home directory (Ubuntu 24.04 creates homes 0750) and write to storage/ without chmod games.
 */
class ServiceUser
{
	/** @var array{name: string, group: string, uid: int}|null */
	protected static ?array $fake = null;

	/**
	 * The user running winux and their primary group, or null when that can't be determined.
	 *
	 * @return array{name: string, group: string, uid: int}|null
	 */
	public static function current(): ?array
	{
		if (static::$fake !== null) {
			return static::$fake;
		}

		if (!function_exists('posix_geteuid') || !($user = posix_getpwuid(posix_geteuid()))) {
			return null;
		}

		$group = posix_getgrgid($user['gid']);

		return [
			'name' => $user['name'],
			'group' => $group ? $group['name'] : $user['name'],
			'uid' => $user['uid'],
		];
	}

	/**
	 * The user the services should run as, or null (with an error) for root or an unknown user.
	 *
	 * @return array{name: string, group: string, uid: int}|null
	 */
	public static function resolve(): ?array
	{
		$user = static::current();

		if ($user === null) {
			error("Couldn't determine the current user, so nginx and PHP-FPM weren't configured to run as you.");
			return null;
		}

		if ($user['uid'] === 0) {
			error('winux is running as root. Run it as your normal user so nginx and PHP-FPM run as you (it uses sudo when needed).');
			return null;
		}

		// Names go into nginx.conf and the pool files as-is
		if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]*\$?$/', $user['name']) || !preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]*\$?$/', $user['group'])) {
			error("The user '{$user['name']}' or group '{$user['group']}' has characters winux can't write into the nginx and PHP-FPM configs.");
			return null;
		}

		return $user;
	}

	/**
	 * Points nginx and every installed PHP-FPM version at the current user. Safe to run repeatedly.
	 */
	public static function configure(): bool
	{
		if (!($user = static::resolve())) {
			return false;
		}

		$ok = true;
		$nginx = Nginx::make();
		if ($nginx->isInstalled() && !$nginx->runAs($user['name'], $user['group'])) {
			$ok = false;
		}

		foreach (PhpFpm::installedVersions() as $version) {
			if (!PhpFpm::make($version)->runAs($user['name'], $user['group'])) {
				$ok = false;
			}
		}

		if ($ok) {
			info("nginx and PHP-FPM run as {$user['name']}.");
		}

		return $ok;
	}

	/**
	 * Services (nginx, PHP x.y) not configured to run as the current user.
	 *
	 * @return string[]
	 */
	public static function misconfigured(): array
	{
		$user = static::current();
		if ($user === null) {
			return ['unknown user'];
		}

		$wrong = [];
		if (Nginx::make()->isInstalled() && Nginx::configuredUser() !== $user['name']) {
			$wrong[] = 'nginx (' . (Nginx::configuredUser() ?? 'default') . ')';
		}

		foreach (PhpFpm::installedVersions() as $version) {
			$pool = PhpFpm::make($version)->poolUser();
			if ($pool['user'] !== $user['name'] || $pool['listen.owner'] !== $user['name']) {
				$wrong[] = "PHP $version (" . ($pool['user'] ?? 'unset') . ')';
			}
		}

		return $wrong;
	}

	/** For tests */
	public static function fake(?array $user): void
	{
		static::$fake = $user;
	}
}
