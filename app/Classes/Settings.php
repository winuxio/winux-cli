<?php

namespace App\Classes;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

/**
 * User preferences persisted to ~/.winux/config.json
 */
class Settings
{
	public static function path(): string
	{
		return config('winux.paths.base') . DIRECTORY_SEPARATOR . 'config.json';
	}

	public static function all(): array
	{
		$path = static::path();
		if (!is_file($path)) {
			return [];
		}

		return json_decode(File::get($path), true) ?: [];
	}

	public static function get(string $key, mixed $default = null): mixed
	{
		return Arr::get(static::all(), $key, $default);
	}

	public static function set(string $key, mixed $value): void
	{
		$values = static::all();
		Arr::set($values, $key, $value);

		File::ensureDirectoryExists(dirname(static::path()));
		File::put(static::path(), json_encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
	}

	public static function phpVersion(): string
	{
		return (string) static::get('php.default', config('winux.php.default'));
	}

	public static function postgresVersion(): string
	{
		return (string) static::get('postgres.default', config('winux.postgres.default'));
	}
}
