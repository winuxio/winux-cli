<?php

namespace App\Http\Integrations\Winux;

use Illuminate\Support\Facades\App;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;

class WinuxAuthConnector extends Connector
{
    use AcceptsJson;

    /**
     * The Base URL of the API
     */
    public function resolveBaseUrl(): string
    {
        // TODO - This should be improved later
        if (App::environment('development')) {
            return 'https://winux-app.local.winux.io/api';
        }

        return 'https://winux.io/api';
    }

    /**
     * Default headers for every request
     */
    protected function defaultHeaders(): array
    {
        return [];
    }

    /**
     * Default HTTP client options
     */
    protected function defaultConfig(): array
    {
        return [];
    }
}
