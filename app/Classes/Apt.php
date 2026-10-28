<?php

namespace App\Classes;

use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\{alert, info, error};

class Apt
{
	public static function update(): bool
	{
		$process = Process::forever()->run(['sudo', 'apt-get', 'update']);
		if ($process->failed()) {
			alert('Failed to update the package list.');
			error($process->errorOutput());
			return false;
		}
		info('Updated the package list.');
		return true;
	}

	public static function install(string|array $packages): bool
	{
		$packages = (array) $packages;
		$list = implode(' ', $packages);

		$process = Process::forever()
			->run(['sudo', 'DEBIAN_FRONTEND=noninteractive', 'apt-get', 'install', '-y', ...$packages]);

		if ($process->failed()) {
			alert("Failed to install the packages: $list.");
			error($process->errorOutput());
			return false;
		}
		info("Installed the packages: $list.");
		return true;
	}

	public static function isInstalled(string $package): bool
	{
		$process = Process::run(['dpkg-query', '-W', '-f=${Status}', $package]);
		return $process->successful() && str_contains($process->output(), 'install ok installed');
	}

	/**
	 * Returns the package names matching a regex, e.g. '^php[0-9.]+-fpm$'
	 */
	public static function search(string $pattern): array
	{
		$process = Process::run(['apt-cache', 'pkgnames']);
		if ($process->failed()) {
			return [];
		}

		return collect(explode("\n", $process->output()))
			->map(fn ($line) => trim($line))
			->filter(fn ($line) => $line !== '' && preg_match('/' . $pattern . '/', $line))
			->values()
			->all();
	}
}
