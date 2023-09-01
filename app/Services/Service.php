<?php

namespace App\Services;

use App\Classes\Apt;
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

	/**
	 * Intended to be overridden if the service's name in apt is different than the service's name
	 * or if the service has additional dependencies that need to be installed.
	 */
	protected function packages(): array
	{
		if ($this->serviceName() === null)
			throw new \Exception('Can\'t install a service without a name!');
		return [ $this->serviceName() ];
	}

	/**
	 * Installs the service's packages and returns true if successful.
	 * Can be overridden, if the pre(todo) or post install hooks are not sufficient.
	 */
	public function install(): bool
	{
		if (!Apt::update()) return false;

		if (!Apt::install($this->packages())) {
			alert("Failed to install {$this->serviceName()}.");
			return false;
		}

		info("{$this->serviceName()} installed successfully!");
		return true;
	}

	/**
	 * Intended to be overridden if the service needs to do something after it's installed.
	 */
	protected function postInstall(): bool
	{
		return true;
	}
}
