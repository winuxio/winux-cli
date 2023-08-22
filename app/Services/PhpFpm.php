<?php

namespace App\Services;

use App\Enums\Versions\PhpVersion;
use Symfony\Component\Process\Process;

class PhpFpm extends Service
{

	protected array $installedVersions = [];

	protected function serviceName(): string
	{
		return 'php' . $this->version->value . '-fpm';
	}

	public PhpVersion $version = PhpVersion::v8_1;

	public function __construct()
	{
		$this->checkVersions();
		// Set the version to the last installed version
		$this->version = PhpVersion::from(end($this->installedVersions));
		// dump('Latest PHP Version: ' . $this->version->value);
		// dd($this->installedVersions);
	}

	public static function make(): self
	{
		$phpFpm = new static();
		return $phpFpm;
	}

	public function checkVersions(): void
	{
		// Run this: find /etc/init.d -name 'php*fpm' -exec basename {} \;
		$process = Process::fromShellCommandline('find /etc/init.d -name \'php*fpm\' -exec basename {} \\;');

		$process->run();
		if (!$process->isSuccessful()) {
			throw new \Exception($process->getErrorOutput());
		}
		$installedVersions = $process->getOutput();
		$installedVersions = explode("\n", $installedVersions);
		$installedVersions = array_filter($installedVersions);
		// dd($installedVersions);
		$this->installedVersions = collect($installedVersions)->map(function ($version) {
			// Use Regex to grab the version number
			preg_match('/php(\d+\.\d+)/', $version, $matches);
			// $this->installedVersions[PhpVersion::from($matches[1])->value] = $version;
			// return [PhpVersion::from($matches[1])->value => $version];
			return $matches[1];
		})->all();
	}
}
