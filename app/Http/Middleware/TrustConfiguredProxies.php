<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

class TrustConfiguredProxies extends TrustProxies
{
    /**
     * Get the trusted proxies from Laravel's cache-safe configuration.
     *
     * @return array<int, string>|string|null
     */
    protected function proxies(): array|string|null
    {
        return config('security.trusted_proxies', []);
    }
}
