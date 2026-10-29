<?php

namespace App\Enums;

use App\Classes\Project;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\error;
use function Laravel\Prompts\warning;

enum Packages: string
{
	case Filament = 'filament';

	public function label(): string
	{
		return match ($this) {
			self::Filament => 'Filament',
		};
	}

	public function install(string $path, string $php = 'php'): bool
	{
		return match ($this) {
			self::Filament => $this->installFilament($path, $php),
		};
	}

	protected function installFilament(string $path, string $php): bool
	{
		$composer = Project::composerPath() ?? 'composer';

		// No constraint: composer picks the newest Filament compatible with the project
		$process = Process::path($path)->forever()->run([$php, $composer, 'require', 'filament/filament', '-W']);
		if ($process->failed()) {
			error('Failed to require Filament via Composer.');
			warning($process->errorOutput());
			return false;
		}

		$process = Process::path($path)->forever()->run([$php, 'artisan', 'filament:install', '--panels', '--no-interaction']);
		if ($process->failed()) {
			error('Failed to install Filament.');
			warning($process->errorOutput());
			return false;
		}

		return true;
	}
}
