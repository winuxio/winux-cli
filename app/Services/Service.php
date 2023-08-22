<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\{alert, error, info};

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

	public static function make(): static
	{
		$service = new static();
		return $service;
	}

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

		if ($this->commandSuffix) {
			$commandParts[] = $this->commandSuffix;
		}
		info('Running command: ' . implode(' ', $commandParts));
		$process = Process::run($commandParts);

		if ($process->failed()) {
			alert('Failed to ' . $command . ' ' . $this->serviceName() . '.');
			error($process->errorOutput());
			return false;
		}

		return $process->successful();
	}
}
