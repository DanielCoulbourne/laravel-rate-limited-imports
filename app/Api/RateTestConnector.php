<?php

namespace App\Api;

use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\HasTimeout;
use Saloon\RateLimitPlugin\Contracts\RateLimitStore;
use Saloon\RateLimitPlugin\Limit;
use Saloon\RateLimitPlugin\Stores\MemoryStore;
use Saloon\RateLimitPlugin\Traits\HasRateLimits;

class RateTestConnector extends Connector
{
    use AcceptsJson;
    use HasTimeout;
    use HasRateLimits;

    /**
     * The Base URL of the API
     */
    public function resolveBaseUrl(): string
    {
        // In testing environment, use the app URL
        if (app()->environment('testing')) {
            return url('/api');
        }

        return config('services.rate_test.url', 'http://localhost:8000/api');
    }

    /**
     * Default headers for every request
     */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
        ];
    }

    /**
     * Default HTTP client options
     */
    protected function defaultConfig(): array
    {
        return [
            'timeout' => 30,
        ];
    }

    /**
     * Configure rate limits for the connector
     */
    protected function resolveLimits(): array
    {
        return [
            Limit::allow(20)->everySeconds(10),   // 20 requests per 10 seconds
            Limit::allow(400)->everyMinute(),      // 400 requests per minute
            Limit::allow(10000)->everyDay(),       // 10000 requests per day
        ];
    }

    /**
     * Configure the rate limit store
     */
    protected function resolveRateLimitStore(): RateLimitStore
    {
        return new MemoryStore();
    }
}
