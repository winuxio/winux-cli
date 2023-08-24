<?php

namespace App\Classes;

use App\Services\Nginx;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\{error, info, warning, alert, note};

class Site
{
	// protected static string $configPath = '/etc/nginx/sites-available';
	// protected static string $linkPath = '/etc/nginx/sites-enabled';

	public string $configFile = '';
	public string $linkFile = '';

	public function __construct(public string $domain, public ?string $path = null)
	{
		$this->configFile = Nginx::availablePath() . "/$domain";
		$this->linkFile = Nginx::enabledPath() . "/$domain";
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
				info('Symbolic link already exists and is configured correctly.');
				return true;
			} else {
				// Remove the existing symbolic link if it doesn't point to the correct file
				unlink($this->linkFile);
			}
		}

		// Create the symbolic link
		$process = Process::run(['ln', '-s', $this->configFile, $this->linkFile]);

		if ($process->failed()) {
			error('Failed to activate the site.');
			return false;
		}

		info('Site activated successfully!');
		return true;
	}

	public function disable(): bool
	{
		// Check if the symbolic link exists
		if (!file_exists($this->linkFile)) {
			info("Symbolic link {$this->linkFile} does not exist. Site is already disabled.");
			return true;
		}

		// Remove the symbolic link
		$process = Process::run(['sudo', 'rm', $this->linkFile]);

		if ($process->failed()) {
			error("Failed to disable the Nginx config for {$this->domain}.");
			return false;
		}

		info("Nginx config for {$this->domain} disabled successfully!");
		return true;
	}

	public function generate(): string
	{
		$configTemplate = static::templateData();
		$config = str_replace(
			['{{PROJECT_DOMAIN}}', '{{PROJECT_PATH}}', '{{SSL_CERT_PATH}}'],
			[$this->domain, $this->path, Nginx::CertPath()],
			$configTemplate
		);
		return $config;
	}

	public function save(string $config): bool
	{
		note('Saving configuration to ' . $this->configFile);
		// dump($config);
	
		// $process = Process::input($config)->run(['sudo', 'tee', $this->configFile]);
		if (!file_put_contents($this->configFile, $config)) {
			error('Failed to save the Nginx config.');
			return false;
		}
		return true;

		// return $process->successful();
	}

	public function delete(): bool
	{
		// Check if the config file exists
		if (!file_exists($this->configFile)) {
			info("Config file {$this->configFile} does not exist. Site is already deleted.");
			return true;
		}

		$process = Process::run(['sudo', 'rm', $this->configFile]);

		if ($process->failed()) {
			alert('Failed to delete Nginx config for ' . $this->domain);
			error($process->errorOutput());
			return false;
		}

		return $process->successful();
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
		$process = Process::run([
			'diff', '--color=always', '--unified=1', $path1, $path2
		]);

		$diffOutput = $process->output();

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
