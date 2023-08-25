<?php

namespace App\Enums;

enum GitHubVisibility: string
{
	case Public = 'public';
	case Private = 'private';

	public static function selectValues(): array
	{
		return [
			self::Public->value => self::Public->name,
			self::Private->value => self::Private->name,
		];}
}
