<?php

namespace App\Enums;

use App\Services\MySql;
use App\Services\Nginx;
use App\Services\PhpFpm;
use App\Services\Postgres;
use App\Services\Redis;
use App\Services\Service;

enum ServiceType: string
{
	case NGINX = 'nginx';
	case PHP = 'php';
	case POSTGRES = 'postgres';
	case REDIS = 'redis';
	case MYSQL = 'mysql';

	public function label(): string
	{
		return match ($this) {
			self::NGINX => 'Nginx',
			self::PHP => 'PHP-FPM',
			self::POSTGRES => 'Postgres',
			self::REDIS => 'Redis',
			self::MYSQL => 'MySQL',
		};
	}

	/** @return class-string<Service> */
	public function serviceClass(): string
	{
		return match ($this) {
			self::NGINX => Nginx::class,
			self::PHP => PhpFpm::class,
			self::POSTGRES => Postgres::class,
			self::REDIS => Redis::class,
			self::MYSQL => MySql::class,
		};
	}

	public function isVersioned(): bool
	{
		return in_array($this, [self::PHP, self::POSTGRES], true);
	}

	/**
	 * The services managed when no specific service is named. MySQL is opt-in.
	 *
	 * @return self[]
	 */
	public static function defaultStack(): array
	{
		return [self::NGINX, self::PHP, self::POSTGRES, self::REDIS];
	}

	/**
	 * Resolves the service instances a stack command should act on.
	 *
	 * @return Service[]
	 */
	public static function resolve(?string $service, ?string $version = null): array
	{
		$types = $service ? [self::from($service)] : self::defaultStack();

		return collect($types)
			->flatMap(function (self $type) use ($version) {
				$class = $type->serviceClass();
				if ($type->isVersioned() && $version) {
					return [$class::make($version)];
				}
				return $class::instances();
			})
			->all();
	}

	public static function values(): array
	{
		return array_map(fn (self $type) => $type->value, self::cases());
	}
}
