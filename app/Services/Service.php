<?php

namespace App\Services;

use App\Classes\Apt;
use App\Classes\Platform;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\{alert, error, info};

abstract class Service
{
	/** The systemd unit / init script name */
	protected static ?string $serviceName = null;

	protected function serviceName(): string
	{
		if (static::$serviceName === null) {
			throw new \LogicException(static::class . ' must define a service name.');
		}
		return static::$serviceName;
	}

	public function label(): string
	{
		return $this->serviceName();
	}

	public static function make(): static
	{
		return new static();
	}

	/**
	 * Every instance of this service that stack-wide commands should act on.
	 * Overridden by services that can have multiple versions installed side by side.
	 *
	 * @return static[]
	 */
	public static function instances(): array
	{
		return [static::make()];
	}

	public function start(): bool
	{
		return $this->control('start');
	}

	public function stop(): bool
	{
		return $this->control('stop');
	}

	public function restart(): bool
	{
		return $this->control('restart');
	}

	public function reload(): bool
	{
		return $this->control('reload');
	}

	public function isActive(): bool
	{
		if (Platform::hasSystemd()) {
			return Process::run(['systemctl', 'is-active', '--quiet', $this->serviceName()])->successful();
		}

		return Process::run(['sudo', 'service', $this->serviceName(), 'status'])->successful();
	}

	public function isInstalled(): bool
	{
		return collect($this->packages())->every(fn ($package) => Apt::isInstalled($package));
	}

	protected function control(string $command): bool
	{
		$commandParts = Platform::hasSystemd()
			? ['sudo', 'systemctl', $command, $this->serviceName()]
			: ['sudo', 'service', $this->serviceName(), $command];

		info('Running command: ' . implode(' ', $commandParts));
		$process = Process::timeout(120)->run($commandParts);

		if ($process->failed()) {
			alert('Failed to ' . $command . ' ' . $this->label() . '.');
			error($process->errorOutput());
			return false;
		}

		return true;
	}

	/**
	 * The apt packages that make up this service.
	 */
	protected function packages(): array
	{
		return [$this->serviceName()];
	}

	/**
	 * Hook for adding third-party apt repositories before installing.
	 */
	protected function preInstall(): bool
	{
		return true;
	}

	/**
	 * Installs the service's packages and returns true if successful.
	 */
	public function install(): bool
	{
		if (!$this->preInstall()) return false;

		if (!Apt::update()) return false;

		if (!Apt::install($this->packages())) {
			alert("Failed to install {$this->label()}.");
			return false;
		}

		if (!$this->postInstall()) return false;

		if (Platform::hasSystemd()) {
			$process = Process::timeout(120)->run(['sudo', 'systemctl', 'enable', '--now', $this->serviceName()]);
			if ($process->failed()) {
				alert("{$this->label()} was installed, but failed to start.");
				error($process->errorOutput());
				return false;
			}
		} elseif (!$this->isActive() && !$this->start()) {
			// Without systemd nothing starts it at boot either; `winux service:start` brings it back
			return false;
		}

		info("{$this->label()} installed successfully!");
		return true;
	}

	/**
	 * Hook for configuration that must happen after the packages are installed.
	 */
	protected function postInstall(): bool
	{
		return true;
	}
}
