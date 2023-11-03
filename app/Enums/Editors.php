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
}
