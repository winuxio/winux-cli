<?php

namespace App\Enums;

use Illuminate\Support\Facades\Process;

// use function Laravel\Prompts\alert;
// use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\warning;

// use function Laravel\Prompts\note;
// use function Laravel\Prompts\warning;

enum Packages: string
{
	case Filament = 'filament';

	public function label(): string
	{
		return match ($this) {
			self::Filament => 'Filament',
		};
	}

	public function install(string $path): bool
	{
		return match ($this) {
			self::Filament => $this->installFilament($path),
		};
	}

	protected static function installFilament(string $path): bool
	{
		$process = Process::path($path)->run(['composer', 'require', 'filament/filament:^3.0-stable', '-W']);
		if ($process->failed()) {
			error('Failed to require Filament via Composer.');
			warning($process->errorOutput());
			return false;
		}

		$process = Process::path($path)->run(['php', 'artisan', 'filament:install', '--panels']);
		if ($process->failed()) {
			error('Failed to install Filament.');
			warning($process->errorOutput());
			return false;
		}

		return true;
	}
}
