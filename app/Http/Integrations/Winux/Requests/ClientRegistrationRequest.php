<?php

namespace App\Http\Integrations\Winux\Requests;

use Illuminate\Support\Str;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class ClientRegistrationRequest extends Request implements HasBody
{
    use HasJsonBody;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::POST;

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return '/client/register';
    }

    public function defaultBody(): array
    {
        return [
            'identifier' => (string)Str::ulid(),
            'hostname' => gethostname(),
        ];
    }
}
