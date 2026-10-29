<?php

namespace App\Commands\SSL;

use App\Classes\Mkcert;
use App\Classes\Platform;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{info, note, warning};

class InstallRootCA extends Command
{
	protected $signature = 'ssl:root:install {--skip-windows : Only trust the CA inside Linux/WSL}';

	protected $description = 'Install mkcert and trust the Winux root CA (Linux + Windows)';

	public function handle(): int
	{
		if (!Mkcert::init()) {
			return self::FAILURE;
		}

		info('Root CA: ' . Mkcert::rootCert());

		$ok = Mkcert::installLinuxTrust();

		if (Platform::isWsl() && !$this->option('skip-windows')) {
			if (Mkcert::isTrustedByWindows()) {
				info('Windows already trusts the root CA.');
			} elseif (!Mkcert::installWindowsTrust()) {
				// Sites still work inside WSL; only Windows browsers will warn until this is done
				warning('Windows doesn\'t trust the root CA yet, so browsers on Windows will show certificate warnings.');
				note('Run `winux ssl:root:install` again once Windows is reachable (and accept its prompt), or import ' . Mkcert::rootCert() . ' into "Trusted Root Certification Authorities" for your Windows user.');
			}
			note('Firefox on Windows: set security.enterprise_roots.enabled to true in about:config if it still warns.');
		}

		return $ok ? self::SUCCESS : self::FAILURE;
	}
}
