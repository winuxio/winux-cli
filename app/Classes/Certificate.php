<?php

namespace App\Classes;

use App\Services\Nginx;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\{error, info};

/**
 * A site's certificate lives at ~/.winux/ssl/certs/{primary}/{primary}.{crt,key}
 * and covers the primary domain plus any extra domains.
 */
class Certificate
{
	public static function path(string $domain): string
	{
		return Nginx::CertPath() . DIRECTORY_SEPARATOR . $domain;
	}

	public static function certFile(string $domain): string
	{
		return static::path($domain) . DIRECTORY_SEPARATOR . "$domain.crt";
	}

	public static function keyFile(string $domain): string
	{
		return static::path($domain) . DIRECTORY_SEPARATOR . "$domain.key";
	}

	public static function destroy(string $domain): bool
	{
		$certPath = self::path($domain);
		if (is_dir($certPath)) {
			if (!File::deleteDirectory($certPath)) {
				error('Failed to delete the cert directory for the domain: ' . $domain);
				return false;
			}
			info('Deleted the cert directory for the domain: ' . $domain);
		}

		return true;
	}

	/**
	 * (Re)issues the certificate for a site. The first domain is the primary one.
	 */
	public static function create(string $primary, array $extraDomains = []): bool
	{
		if (!Mkcert::init()) {
			return false;
		}

		File::ensureDirectoryExists(self::path($primary), 0755);

		$domains = array_values(array_unique([$primary, ...$extraDomains]));
		if (!Mkcert::issue($domains, self::certFile($primary), self::keyFile($primary))) {
			return false;
		}

		info('Issued a certificate for: ' . implode(', ', $domains));
		return true;
	}

	/**
	 * The DNS names the current certificate covers.
	 */
	public static function domains(string $primary): array
	{
		$certFile = self::certFile($primary);
		if (!is_file($certFile)) {
			return [];
		}

		$cert = openssl_x509_parse(File::get($certFile));
		$san = $cert['extensions']['subjectAltName'] ?? '';
		preg_match_all('/DNS:([^,\s]+)/', $san, $matches);
		return $matches[1];
	}
}
