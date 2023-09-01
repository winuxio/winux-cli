<?php

namespace App\Enums;

use App\Services\MySql;
use App\Services\Nginx;
use App\Services\PhpFpm;
use App\Services\Postgres;
use App\Services\Redis;

enum ServiceType: string
{
	case POSTGRES = 'postgres';
	case MYSQL = 'mysql';
	case REDIS = 'redis';
	case NGINX = 'nginx';
	case PHP = 'php';
	

	public static function getLabels(): array
	{
		return [
			self::POSTGRES->value => 'Postgres',
			self::MYSQL->value => 'MySQL',
			self::REDIS->value => 'Redis',
			self::NGINX->value => 'Nginx',
			self::PHP->value => 'PHP',
		];
	}

	public static function services(): array
	{
		return [
			self::POSTGRES->value => Postgres::class,
			self::MYSQL->value => MySql::class,
			self::REDIS->value => Redis::class,
			self::NGINX->value => Nginx::class,
			self::PHP->value => PhpFpm::class,
		];
	}
}