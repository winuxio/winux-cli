<?php

namespace App\Classes;

use App\Services\Nginx;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\{alert, info, error};

class OpenSSL
{
	public static function init(): bool
	{
		$certPath = Nginx::CertPath();
		$rootCApath = join(DIRECTORY_SEPARATOR, [config('winux.paths.base'), 'ssl', 'root']);

		// Ensure that both the cert and key paths exist
		if (!is_dir($certPath)) {
			if (!mkdir($certPath)) {
				error('Failed to create the cert directory.');
				return false;
			}
			info('Created the cert directory.');
		}

		// Create the 'root CA' directory in the .winux basedir
		if (!is_dir($rootCApath)) {
			if (!mkdir($rootCApath)) {
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
}
