<?php

namespace App\Services;

use App\Classes\Platform;

use function Laravel\Prompts\warning;

/**
 * Optional; not part of the default stack. Root authenticates via the
 * auth_socket plugin, so `sudo mysql` works without a password.
 */
class MySql extends Service
{
	protected static ?string $serviceName = 'mysql';

	public function label(): string
	{
		return 'MySQL';
	}

	protected function packages(): array
	{
		return ['mysql-server'];
	}

	protected function preInstall(): bool
	{
		if (Platform::isDebian()) {
			warning('Debian does not ship mysql-server; install mariadb-server manually instead.');
			return false;
		}

		return true;
	}
}
