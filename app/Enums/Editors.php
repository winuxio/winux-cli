<?php

namespace App\Enums;

enum Editors
{
	case VSCODE;
	case PHPSTORM;

	public function getCommand(): string
	{
		return match ($this) {
			self::VSCODE => 'code',
			self::PHPSTORM => 'phpstorm64.exe',
		};
	}
}