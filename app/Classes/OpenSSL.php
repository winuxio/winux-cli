<?php

namespace App\Classes;

use App\Services\Nginx;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\{alert, info, error, warning};

class OpenSSL
{
	public static function init(): bool
	{
		$certPath = Nginx::CertPath();
		$rootCApath = join(DIRECTORY_SEPARATOR, [config('winux.paths.base'), 'ssl', 'root']);

		// Ensure that both the cert and key paths exist
		if (!is_dir($certPath)) {
			if (!mkdir($certPath, 0755, true)) {
				error('Failed to create the cert directory.');
				return false;
			}
			info('Created the cert directory.');
		}

		// Create the 'root CA' directory in the .winux basedir
		if (!is_dir($rootCApath)) {
			if (!mkdir($rootCApath, 0755, true)) {
				error('Failed to create the root authority directory.');
				return false;
			}
			info("Created the root authority directory.");
		}

		// Ensure we've initialized a root CA
		if (!self::generateRootAuthority()) {
			return false;
		}

		return true;
	}

	public static function generateRootAuthority(): bool
	{
		$rootCApath = join(DIRECTORY_SEPARATOR, [config('winux.paths.base'), 'ssl', 'root']);
		$rootKey = join(DIRECTORY_SEPARATOR, [$rootCApath, config('winux.ssl.root.key')]);
		$rootCert = join(DIRECTORY_SEPARATOR, [$rootCApath, config('winux.ssl.root.cert')]);

		// Check if the root authority key exists
		if (!is_file($rootKey)) {
			// Generate the root authority key
			$rootKeyGeneration = Process::run(['openssl', 'genrsa', '-out', $rootKey, '2048']);
			if ($rootKeyGeneration->failed()) {
				alert('Failed to generate the root authority key.');
				error($rootKeyGeneration->errorOutput());
				return false;
			}
			info('Generated the root authority key.');
		}

		// Check if the root authority cert exists
		if (!is_file($rootCert)) {
			// Generate the root authority cert
			$rootCertGeneration = Process::run(['openssl', 'req', '-x509', '-new', '-nodes', '-key', $rootKey, '-days', '3650', '-out', $rootCert, '-subj', '/CN=Winux Local Development CA']);
			if ($rootCertGeneration->failed()) {
				alert('Failed to generate the root authority cert.');
				error($rootCertGeneration->errorOutput());
				return false;
			}
			info('Generated the root authority cert.');
		}

		return true;
	}

	public static function installRoot()
	{
		// Run 'net.exe session', if it fails, we are not an admin
		$process = Process::run(['net.exe', 'session']);
		dump('Exit Code: ' . $process->exitCode());
		if ($process->exitCode() != 0) {
			alert('This terminal was not opened as an Administrator. Please open a new terminal as an Administrator and try again.');
			warning('To fix this: Right click on the terminal icon/shortcut and click "Run as Administrator".');
			warning('Then run the command: `winux ssl:root:install`');
			return false;
		}

		$rootPath = config('winux.paths.base');
		$rootCApath = join(DIRECTORY_SEPARATOR, [config('winux.paths.base'), 'ssl', 'root']);
		$rootCert = join(DIRECTORY_SEPARATOR, [$rootCApath, config('winux.ssl.root.cert')]);

		// Convert the rootcert to a windows path
		$rootCertWinPath = trim(Process::run(['wslpath', '-w', $rootCert])->output());

		// PowerShell script content
		$psScript = <<<POWERSHELL
					\$certPath = '$rootCertWinPath'
					\$cert = New-Object System.Security.Cryptography.X509Certificates.X509Certificate2 \$certPath
					\$store = New-Object System.Security.Cryptography.X509Certificates.X509Store 'Root', 'LocalMachine'
					\$store.Open('ReadWrite')
					\$store.Add(\$cert)
					\$store.Close()
					POWERSHELL;

		// Ensure the base directory exists
		if (!is_dir($rootPath)) {
			if (!mkdir($rootPath, 0755, true)) {
				error('Failed to create the winux directory.');
				return false;
			}
		}

		// Save the $psScript to a script in the rootPath
		$psScriptFile = join(DIRECTORY_SEPARATOR, [$rootPath, 'install-root.ps1']);
		file_put_contents($psScriptFile, $psScript);

		// Run the PowerShell script
		$process = Process::input($psScript)->run([
			'powershell.exe', 
			'-noprofile', 
			'-noninteractive',
			'-ExecutionPolicy', 'Bypass',
			'-file', $psScriptFile]);
		if ($process->failed()) {
			alert('Failed to install the root authority cert.');
			error($process->errorOutput());
			return false;
		}

		info('Installed the root authority cert.');
		warning('FULLY Close and reopen your browser for the changes to take effect.');
	}
}
