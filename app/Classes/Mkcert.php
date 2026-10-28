<?php

namespace App\Classes;

use App\Services\Nginx;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\{error, info, note, warning};

/**
 * Wraps mkcert with winux's CA directory (~/.winux/ssl/root) as CAROOT.
 */
class Mkcert
{
	public static function caRoot(): string
	{
		return Nginx::RootCAPath();
	}

	public static function rootCert(): string
	{
		return static::caRoot() . DIRECTORY_SEPARATOR . config('winux.ssl.root.cert');
	}

	public static function rootKey(): string
	{
		return static::caRoot() . DIRECTORY_SEPARATOR . config('winux.ssl.root.key');
	}

	public static function isInstalled(): bool
	{
		return Platform::commandExists('mkcert');
	}

	public static function hasRootCA(): bool
	{
		return is_file(static::rootCert()) && is_file(static::rootKey());
	}

	/**
	 * Installs mkcert, adopts a pre-mkcert winux root CA if present, and creates one otherwise.
	 */
	public static function init(): bool
	{
		if (!static::isInstalled()) {
			info('Installing mkcert...');
			if (!Apt::install(['mkcert', 'libnss3-tools'])) {
				return false;
			}
		}

		File::ensureDirectoryExists(static::caRoot(), 0755);

		static::adoptLegacyRoot();

		if (!static::hasRootCA()) {
			// Running mkcert with CAROOT set creates rootCA.pem + rootCA-key.pem on first use
			$process = static::process()->run(['mkcert', '-CAROOT']);
			if ($process->failed()) {
				error('Failed to initialize the mkcert root CA.');
				error($process->errorOutput());
				return false;
			}
		}

		return true;
	}

	/**
	 * Earlier winux versions generated winux_root.{crt,key} with openssl. mkcert can sign with
	 * that same CA, so browsers that already trust it keep trusting new certificates.
	 */
	public static function adoptLegacyRoot(): void
	{
		$legacyCert = static::caRoot() . DIRECTORY_SEPARATOR . config('winux.ssl.root.legacy_cert');
		$legacyKey = static::caRoot() . DIRECTORY_SEPARATOR . config('winux.ssl.root.legacy_key');

		if (static::hasRootCA() || !is_file($legacyCert) || !is_file($legacyKey)) {
			return;
		}

		File::copy($legacyCert, static::rootCert());
		File::copy($legacyKey, static::rootKey());
		chmod(static::rootKey(), 0600);
		note('Adopted the existing Winux root CA for mkcert.');
	}

	/**
	 * Issues a certificate covering every given domain to the given cert/key paths.
	 */
	public static function issue(array $domains, string $certFile, string $keyFile): bool
	{
		$process = static::process()->run([
			'mkcert',
			'-cert-file', $certFile,
			'-key-file', $keyFile,
			...$domains,
		]);

		if ($process->failed()) {
			error('mkcert failed to issue a certificate for: ' . implode(', ', $domains));
			error($process->errorOutput());
			return false;
		}

		return true;
	}

	/**
	 * Trusts the root CA in this Linux system's store (and NSS for Linux browsers).
	 */
	public static function installLinuxTrust(): bool
	{
		$process = static::process()->tty(static::hasTty())->run(['mkcert', '-install']);
		if ($process->failed()) {
			error('Failed to install the root CA into the Linux trust store.');
			error($process->errorOutput());
			return false;
		}

		info('Root CA trusted inside ' . (Platform::isWsl() ? 'WSL' : 'Linux') . '.');
		return true;
	}

	/**
	 * Trusts the root CA in the Windows user's certificate store (Chrome/Edge/Firefox on Windows).
	 * Windows shows a confirmation dialog; no administrator rights are required.
	 */
	public static function installWindowsTrust(): bool
	{
		$windowsPath = trim(Process::run(['wslpath', '-w', static::rootCert()])->output());
		if ($windowsPath === '') {
			error('Unable to resolve the Windows path of the root CA.');
			return false;
		}

		$script = 'Import-Certificate -FilePath ' . static::psQuote($windowsPath) . ' -CertStoreLocation Cert:\\CurrentUser\\Root | Out-Null';
		note('Windows will ask you to confirm installing the "mkcert" / "Winux" certificate. Click Yes.');

		$process = Process::timeout(300)->run(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', $script]);
		if ($process->failed()) {
			error('Failed to install the root CA into the Windows certificate store.');
			error($process->errorOutput());
			return false;
		}

		info('Root CA trusted by Windows.');
		warning('Fully close and reopen your browser for the change to take effect.');
		return true;
	}

	public static function isTrustedByWindows(): bool
	{
		if (!static::hasRootCA()) {
			return false;
		}

		$fingerprint = static::fingerprint();
		$script = "if (Get-ChildItem Cert:\\CurrentUser\\Root, Cert:\\LocalMachine\\Root | Where-Object Thumbprint -eq '$fingerprint') { exit 0 } else { exit 1 }";

		return Process::run(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', $script])->successful();
	}

	public static function fingerprint(): string
	{
		return strtoupper((string) openssl_x509_fingerprint(File::get(static::rootCert()), 'sha1'));
	}

	protected static function process()
	{
		return Process::env(['CAROOT' => static::caRoot()])->timeout(120);
	}

	protected static function hasTty(): bool
	{
		return function_exists('posix_isatty') && posix_isatty(STDIN);
	}

	protected static function psQuote(string $value): string
	{
		return "'" . str_replace("'", "''", $value) . "'";
	}
}
