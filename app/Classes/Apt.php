<?php

namespace App\Classes;

use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\{alert, info, error};

class Apt
{
	public static function update()
	{
		$process = Process::run(['sudo', 'apt', 'update']);
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
		if (is_array($packages)) {
			$packages = join(' ', $packages);
		}
		$process = Process::run(['sudo', 'apt', 'install', '-y', $packages]);
		if ($process->failed()) {
			alert("Failed to install the packages: $packages.");
			error($process->errorOutput());
			return false;
		}
		info("Installed the packages: $packages.");
		return true;
	}
}