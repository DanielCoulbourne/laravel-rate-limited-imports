<?php

namespace App\Api;

use App\Api\RateLimit\HasGlobalRateLimiting;
use App\Api\RateLimit\RateLimitConfig;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\HasTimeout;

class RateTestConnector extends Connector
{
    use AcceptsJson;
    use HasGlobalRateLimiting;
    use HasTimeout;

    protected ?int $trackingImportId = null;

    public function __construct(?int $trackingImportId = null)
    {
        $this->trackingImportId = $trackingImportId;
    }

    public function resolveBaseUrl(): string
    {
        $configUrl = config('services.rate_test.url');

        if ($configUrl) {
            return $configUrl;
        }

        return rtrim(config('app.url'), '/') . '/api';
    }

    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
        ];
    }

    protected function defaultConfig(): array
    {
        return [
            'timeout' => 30,
        ];
    }

    protected function rateLimitConfig(): RateLimitConfig
    {
        return new RateLimitConfig(
            limits: [
                [400, 20],   // 400 requests per 20 seconds (burst limit)
                [2100, 100], // 2100 requests per 100 seconds (will exceed API's 2000/100s)
            ],
            onSleep: function (int $seconds) {
                if ($this->trackingImportId) {
                    $import = \App\Models\ImportMeta\Import::find($this->trackingImportId);
                    if ($import) {
                        $import->incrementRateLimitSleeps($seconds);
                    }
                }
            },
        );
    }
}
