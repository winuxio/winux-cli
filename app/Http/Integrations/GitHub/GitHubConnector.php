<?php

namespace App\Http\Integrations\GitHub;

use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;

class GitHubConnector extends Connector
{
	use AcceptsJson;
	use AlwaysThrowOnErrors;

	/**
	 * @param  bool  $authenticate  Send GH_TOKEN / GITHUB_TOKEN when one is set
	 */
	public function __construct(public readonly bool $authenticate = true)
	{
	}

	public function resolveBaseUrl(): string
	{
		return 'https://api.github.com';
	}

	protected function defaultHeaders(): array
	{
		$headers = [
			'User-Agent' => 'winux-cli',
			'X-GitHub-Api-Version' => '2022-11-28',
		];

		// Optional, only to lift the anonymous rate limit (60 requests/hour)
		if ($this->authenticate && ($token = static::token())) {
			$headers['Authorization'] = "Bearer $token";
		}

		return $headers;
	}

	public static function token(): ?string
	{
		return getenv('GH_TOKEN') ?: getenv('GITHUB_TOKEN') ?: null;
	}

	protected function defaultConfig(): array
	{
		return [
			'connect_timeout' => 5,
			'timeout' => 120,
		];
	}
}
