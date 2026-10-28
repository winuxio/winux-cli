<?php

namespace App\Classes;

use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\{error, info, warning};

/**
 * Third-party apt repositories winux relies on:
 *  - PHP: ondrej/php PPA on Ubuntu, packages.sury.org on Debian
 *  - Postgres: apt.postgresql.org (PGDG)
 */
class AptRepository
{
	public static function hasPhp(): bool
	{
		return static::sourcesContain(['ondrej/php', 'ondrej-ubuntu-php', 'packages.sury.org/php']);
	}

	public static function hasPostgres(): bool
	{
		return static::sourcesContain(['apt.postgresql.org']);
	}

	public static function ensurePhp(): bool
	{
		if (static::hasPhp()) {
			return true;
		}

		if (Platform::isUbuntu()) {
			info('Adding the ondrej/php PPA...');
			if (!Apt::isInstalled('software-properties-common') && !Apt::install('software-properties-common')) {
				return false;
			}
			return static::run(['sudo', 'add-apt-repository', '-y', 'ppa:ondrej/php'], 'Failed to add the ondrej/php PPA.')
				&& Apt::update();
		}

		if (Platform::isDebian()) {
			info('Adding the packages.sury.org PHP repository...');
			$codename = Platform::codename();
			$keyring = '/usr/share/keyrings/debsuryorg-archive-keyring.gpg';
			$deb = sys_get_temp_dir() . '/debsuryorg-archive-keyring.deb';
			$source = "deb [signed-by=$keyring] https://packages.sury.org/php/ $codename main";

			return Apt::install(['ca-certificates', 'curl'])
				&& static::run(['curl', '-fsSLo', $deb, 'https://packages.sury.org/debsuryorg-archive-keyring.deb'], 'Failed to download the sury keyring.')
				&& static::run(['sudo', 'dpkg', '-i', $deb], 'Failed to install the sury keyring.')
				&& static::writeSource('/etc/apt/sources.list.d/php.list', $source)
				&& Apt::update();
		}

		warning('Unsupported distribution (' . Platform::prettyName() . '); skipping the PHP repository.');
		return false;
	}

	public static function ensurePostgres(): bool
	{
		if (static::hasPostgres()) {
			return true;
		}

		if (!Platform::isSupported()) {
			warning('Unsupported distribution (' . Platform::prettyName() . '); skipping the PGDG repository.');
			return false;
		}

		info('Adding the PostgreSQL (PGDG) repository...');

		// postgresql-common ships the official repository setup script
		return Apt::install(['postgresql-common', 'ca-certificates'])
			&& static::run(['sudo', '/usr/share/postgresql-common/pgdg/apt.postgresql.org.sh', '-y'], 'Failed to add the PGDG repository.');
	}

	protected static function sourcesContain(array $needles): bool
	{
		$files = array_merge(
			glob('/etc/apt/sources.list.d/*') ?: [],
			is_file('/etc/apt/sources.list') ? ['/etc/apt/sources.list'] : [],
		);

		foreach ($files as $file) {
			$contents = (basename($file) . "\n") . (@file_get_contents($file) ?: '');
			foreach ($needles as $needle) {
				if (str_contains($contents, $needle)) {
					return true;
				}
			}
		}

		return false;
	}

	protected static function writeSource(string $path, string $contents): bool
	{
		return static::run(
			['sudo', 'sh', '-c', 'printf "%s\n" ' . escapeshellarg($contents) . ' > ' . escapeshellarg($path)],
			"Failed to write $path."
		);
	}

	protected static function run(array $command, string $failure): bool
	{
		$process = Process::forever()->run($command);
		if ($process->failed()) {
			error($failure);
			error($process->errorOutput());
			return false;
		}
		return true;
	}
}
