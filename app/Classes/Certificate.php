<?php

namespace App\Classes;

use App\Services\Nginx;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\{alert, error, info};

class Certificate
{
	public static function create(string $domain): bool
	{
		$certPath = Nginx::CertPath() . DIRECTORY_SEPARATOR . $domain;
		// $keyPath = Nginx::KeyPath();

		// Ensure that the cert path exists
		if (!is_dir($certPath)) {
			if (!mkdir($certPath)) {
				error('Failed to create the cert directory for the domain: ' . $domain);
				return false;
			}
			info('Created the cert directory for: ' . $domain);
		}

		// We need to generate a conf file for this domain
		$confTemplate = File::get(storage_path('templates/ssl/localhost.conf'));
		$confData = str_replace('{{PROJECT_DOMAIN}}', $domain, $confTemplate);
		$domainConfFile = join(DIRECTORY_SEPARATOR, [$certPath, $domain . '.conf']);
		if (!file_put_contents($domainConfFile, $confData)) {
			error('Failed to create the conf file for the domain: ' . $domain);
			return false;
		}

		info('Created the conf file for the domain: ' . $domain);

		$certFile = join(DIRECTORY_SEPARATOR, [$certPath, $domain . '.crt']);
		$csrFile = join(DIRECTORY_SEPARATOR, [$certPath, $domain . '.csr']);
		$keyFile = join(DIRECTORY_SEPARATOR, [$certPath, $domain . '.key']);

		// Create a key for the domain if it doesn't exist
		if (!is_file($keyFile)) {
			$keyGeneration = Process::run(['openssl', 'genrsa', '-out', $keyFile, '2048']);
			if ($keyGeneration->failed()) {
				alert('Failed to generate the key for the domain.');
				error($keyGeneration->errorOutput());
				return false;
			}
			info('Generated the key for the domain.');
		}

		// Create a CSR for the domain then sign the CSR with the Root CA if it doesn't exist
		// Use the localhost.conf file for the CSR
		if (!is_file($certFile)) {
			info('Generating the CSR using the conf file: ' . $domainConfFile);
			$csrGeneration = Process::run([
				// 'openssl', 'req', '-new', '-key', $keyFile, '-out', $certFile, '-config', $domainConfFile, '-extensions', 'v3_req'
				'openssl', 'req', '-new', '-key', $keyFile, '-out', $csrFile, '-config', $domainConfFile
			]);
			if ($csrGeneration->failed()) {
				alert('Failed to generate the CSR for the domain.');
				error($csrGeneration->errorOutput());
				return false;
			}
			info('Generated the CSR for the domain.');

			$rootCrtPath = Nginx::RootCAPath() . DIRECTORY_SEPARATOR . config('winux.ssl.root.cert');
			$rootKeyPath = Nginx::RootCAPath() . DIRECTORY_SEPARATOR . config('winux.ssl.root.key');
			// info('Signing the CSR for the domain using the Root CA: ' . $rootCrtPath . ' and ' . $rootKeyPath);

			// Sign the CSR with the Root CA
			$signing = Process::run([
				'openssl', 'x509', '-req', 
				'-days', '3650', 
				'-in', $csrFile, 
				'-out', $certFile, 
				'-CA', $rootCrtPath,
				'-CAkey', $rootKeyPath,
				'-CAcreateserial',
				'-extensions', 'v3_req', 
				'-extfile', $domainConfFile
			]);
			if ($signing->failed()) {
				alert('Failed to sign the CSR for the domain.');
				error($signing->errorOutput());
				return false;
			}
			info('Signed the CSR for the domain.');
		}

		return true;
	}
}
