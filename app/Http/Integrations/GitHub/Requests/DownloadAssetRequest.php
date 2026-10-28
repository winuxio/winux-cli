<?php

namespace App\Http\Integrations\GitHub\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Downloads a release asset through the API (which redirects to the file itself).
 */
class DownloadAssetRequest extends Request
{
	protected Method $method = Method::GET;

	public function __construct(protected string $repository, protected int $assetId)
	{
	}

	public function resolveEndpoint(): string
	{
		return "/repos/{$this->repository}/releases/assets/{$this->assetId}";
	}

	protected function defaultHeaders(): array
	{
		return ['Accept' => 'application/octet-stream'];
	}
}
