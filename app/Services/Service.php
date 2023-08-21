<?php

namespace App\Services;

use Symfony\Component\Process\Process;

abstract class Service
{
	protected static string $initPath = '/etc/init.d/';

	protected static string $serviceName = '';

	public static function start(): bool
	{
		return static::init(__FUNCTION__);
	}

	public static function stop(): bool
	{
		return static::init(__FUNCTION__);
	}

	public static function restart(): bool
	{
		return static::init(__FUNCTION__);
	}

	protected static function init(string $command): bool
	{
		$process = new Process(['sudo', static::$initPath . static::$serviceName, $command]);
		$process->run();

		return $process->isSuccessful();
	}
}