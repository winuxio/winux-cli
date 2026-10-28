<?php

namespace App\Http\Integrations\GitHub\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class ListReleasesRequest extends Request
{
	protected Method $method = Method::GET;

	public function __construct(protected string $repository)
	{
	}

	public function resolveEndpoint(): string
	{
		return "/repos/{$this->repository}/releases";
	}

	protected function defaultQuery(): array
	{
		return ['per_page' => 50];
	}
}
