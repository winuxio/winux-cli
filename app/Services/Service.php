<?php

namespace App\Services;

use Symfony\Component\Process\Process;

use function Laravel\Prompts\info;

abstract class Service
{
	protected static string $initPath = '/etc/init.d/';

	// Meant to be overridden
	protected function serviceName(): string
	{
		return static::$serviceName;
	}

	protected static ?string $serviceName = null;

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
		$commandParts = [
			'sudo',
			static::$initPath . (static::$serviceName ?? $this->serviceName()),
			$command
		];
		info('Running command: ' . implode(' ', $commandParts));
		if ($this->commandSuffix) {
			$commandParts[] = $this->commandSuffix;
		}
		$process = new Process($commandParts);
		$process->run();

		return $process->isSuccessful();
	}
}
