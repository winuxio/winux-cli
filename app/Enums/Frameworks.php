<?php

namespace App\Enums;

use App\Services\Nginx;

use function GuzzleHttp\default_ca_bundle;

enum Frameworks
{
		// Placeholder, Will add more later
	case Laravel;
	case Nuxt;

	public function sslPath(string $domain): string
	{
		return match ($this) {
			Frameworks::Laravel => join(DIRECTORY_SEPARATOR, [Nginx::CertPath(), $domain]),
			Frameworks::Nuxt => join(DIRECTORY_SEPARATOR, [
				config('winux.paths.base'),
				'ssl',
				'nuxt'
			]),
			default => throw new \Exception('Invalid Framework'),
		};
	}
}
