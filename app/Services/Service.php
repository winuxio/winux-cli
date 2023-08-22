<?php

namespace App\Services;

use Symfony\Component\Process\Process;

abstract class Service
{
	protected static string $initPath = '/etc/init.d/';

	protected static string $serviceName = '';

	protected ?string $commandSuffix = null;

	public function start(): bool
	{
		return self::init(__FUNCTION__);
	}

	public function stop(): bool
	{
		return self::init(__FUNCTION__);
	}

	public function restart(): bool
	{
		return self::init(__FUNCTION__);
	}

	protected function init(string $command): bool
	{
		$commandParts = ['sudo', static::$initPath . static::$serviceName, $command];
		if ($this->commandSuffix) {
			$commandParts[] = $this->commandSuffix;
		}
		$process = new Process($commandParts);
		$process->run();

		return $process->isSuccessful();
	}
}