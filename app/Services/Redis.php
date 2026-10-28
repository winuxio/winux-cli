<?php

namespace App\Services;

class Redis extends Service
{
	protected static ?string $serviceName = 'redis-server';

	public function label(): string
	{
		return 'Redis';
	}
}
