<?php

namespace App\Classes;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class Site
{
	protected static string $configPath = '/etc/nginx/sites-available';
	protected static string $linkPath = '/etc/nginx/sites-enabled';

	public function __construct(public string $domain, public string $path)
	{
	}

	public static function create(string $domain, string $path)
	{
		$newSite = new static($domain, $path);
		// $config = $newSite->generate();
		return $newSite;
	}

	public static function enable(string $domain): bool
	{
		$destFile = static::$configPath . "/$domain";
		$link = static::$linkPath . "/$domain";;

		// Check if the symbolic link exists
		if (file_exists($link)) {
			// Check if the symbolic link points to the correct file
			if (readlink($link) === $destFile) {
				// $this->info('Symbolic link already exists and is configured correctly.');
				return true;
			} else {
				// Remove the existing symbolic link if it doesn't point to the correct file
				unlink($link);
			}
		}

		// Create the symbolic link
		$process = new Process(['sudo', 'ln', '-s', $destFile, $link]);
		$process->run();

		if (!$process->isSuccessful()) {
			// $this->error('Failed to activate the site.');
			return false;
		}

		// $this->info('Site activated successfully!');
		return true;
	}

	public static function disable(string $domain): bool
	{
		$link = static::$linkPath . "/$domain";

		// Check if the symbolic link exists
		if (!file_exists($link)) {
			// $this->info("Symbolic link $link does not exist. Site is already disabled.");
			return true;
		}

		// Remove the symbolic link
		$process = new Process(['sudo', 'rm', $link]);
		$process->run();

		if (!$process->isSuccessful()) {
			// $this->error('Failed to disable the site.');
			return false;
		}

		// $this->info('Site disabled successfully!');
		return true;
	}

	public function generate(): string
	{
		$configTemplate = static::templateData();
		$config = str_replace(
			['{{PROJECT_DOMAIN}}', '{{PROJECT_PATH}}'],
			[$this->domain, $this->path],
			$configTemplate
		);
		return $config;
	}

	public function save(string $config): bool
	{
		$process = new Process(['sudo', 'tee', $this->path], null, null, $config);
		$process->run();

		return $process->isSuccessful();
	}

	private static function templatePath(): string
	{
		return storage_path('templates/site-template.conf');
	}

	private static function templateData(): string
	{
		return File::get(static::templatePath());
	}

	public static function configDiff(string $configuration): string
	{
		// Run a diff between the $templateConfig and the generated $config and display it to the user
		$process = new Process([
			'diff', '--color=always', '--unified=1', static::templatePath(), '-'
		], null, null, $configuration);
		$process->run();
		$diffOutput = $process->getOutput();
		$lines = explode("\n", $diffOutput);

		// Skip the header lines, including the line starting with @@
		$changes = array_slice($lines, 3);

		// Remove any lines that don't start with a '+'
		$changes = array_filter($changes, function ($line) {
			return strpos($line, '+') === 5;
		});

		// Combine the filtered lines back into a string
		return implode("\n", $changes);
	}
}
