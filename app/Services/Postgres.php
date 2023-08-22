<?php

namespace App\Services;

use App\Enums\Versions\PostgresVersion;

class Postgres extends Service
{
	protected static string $serviceName = 'postgresql';

	

	public function __construct(protected PostgresVersion $version)
	{
		$this->commandSuffix = $version->value;
	}


}