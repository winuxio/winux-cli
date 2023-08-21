<?php

namespace App\Classes;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class Site
{
	protected static string $configPath = '/etc/nginx/sites-available';
	protected static string $linkPath = '/etc/nginx/sites-enabled';

	public string $configFile = '';
	public string $linkFile = '';

	public function __construct(public string $domain, public ?string $path = null)
	{
		$this->configFile = static::$configPath . "/$domain";
		$this->linkFile = static::$linkPath . "/$domain";
	}

	public static function create(string $domain, string $path): static
	{
		$newSite = new static($domain, $path);
		// $config = $newSite->generate();
		return $newSite;
	}

	public static function make(string $domain): static
	{
		return new static($domain);
	}

	public function enable(): bool
	{
		// Check if the symbolic link exists
		if (file_exists($this->linkFile)) {
			// Check if the symbolic link points to the correct file
			if (readlink($this->linkFile) === $this->configFile) {
				// $this->info('Symbolic link already exists and is configured correctly.');
				return true;
			} else {
				// Remove the existing symbolic link if it doesn't point to the correct file
				unlink($this->linkFile);
			}
		}

		// Create the symbolic link
		$process = new Process(['sudo', 'ln', '-s', $this->configFile, $this->linkFile]);
		$process->run();

		if (!$process->isSuccessful()) {
			// $this->error('Failed to activate the site.');
			return false;
		}

		// $this->info('Site activated successfully!');
		return true;
	}

	public function disable(): bool
	{
		// Check if the symbolic link exists
		if (!file_exists($this->linkFile)) {
			// $this->info("Symbolic link $link does not exist. Site is already disabled.");
			return true;
		}

		// Remove the symbolic link
		$process = new Process(['sudo', 'rm', $this->linkFile]);
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
		$process = new Process(['sudo', 'tee', $this->configFile], null, null, $config);
		$process->run();

		return $process->isSuccessful();
	}

	private static function templatePath(): string
	{
		return storage_path('templates/site-template.conf');
	}

	private static function templateData(): string
	{
		$templatePath = static::templatePath();
		// dump('Template Path: ' . $templatePath);
		return File::get($templatePath);
	}

	public static function configDiff(string $configuration): string
	{
		// Create Named Pipes for both files
		$pipe1 = tmpfile();
		$pipe2 = tmpfile();

		// Write content to the Named Pipes
		fwrite($pipe1, static::templateData());
		fwrite($pipe2, $configuration);

		// Get the paths for the Named Pipes
		$path1 = stream_get_meta_data($pipe1)['uri'];
		$path2 = stream_get_meta_data($pipe2)['uri'];

		// Run a diff between the $templateConfig and the generated $config and display it to the user
		$process = new Process([
			'diff', '--color=always', '--unified=1', $path1, $path2
		]);
		$process->run();
		$diffOutput = $process->getOutput();

		// Close the Named Pipes
		fclose($pipe1);
		fclose($pipe2);

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
