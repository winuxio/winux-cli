<?php

namespace App\Enums;

enum Editors: string
{
	case VSCODE = 'vscode';
	case PHPSTORM = 'phpstorm';

	public function getCommand(): string
	{
		return match ($this) {
			self::VSCODE => 'code',
			self::PHPSTORM => 'phpstorm64.exe',
		};
	}

	public function getLabel(): string
	{
		return match ($this) {
			self::VSCODE => 'Visual Studio Code',
			self::PHPSTORM => 'PhpStorm',
		};
	}

	/**
	 * Windows programs need the project as a Windows path (\\wsl.localhost\...). VS Code's `code`
	 * launcher understands WSL itself and opens the folder through its WSL extension.
	 */
	public function needsWindowsPath(): bool
	{
		return match ($this) {
			self::VSCODE => false,
			self::PHPSTORM => true,
		};
	}
}
