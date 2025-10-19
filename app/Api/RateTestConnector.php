<?php

namespace App\Api;

use Illuminate\Support\Facades\Cache;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;
use Saloon\RateLimitPlugin\Contracts\RateLimitStore;
use Saloon\RateLimitPlugin\Limit;
use Saloon\RateLimitPlugin\Stores\LaravelCacheStore;
use Saloon\RateLimitPlugin\Traits\HasRateLimits;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\HasTimeout;

class RateTestConnector extends Connector
{
    use AcceptsJson;
    use HasRateLimits;
    use HasTimeout;

    protected ?int $trackingImportId = null;

    public function __construct(?int $trackingImportId = null)
    {
        // Disable automatic 429 detection
        $this->detectTooManyAttempts = false;

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

    protected function resolveLimits(): array
    {
        return [
            Limit::allow(10)->everySeconds(10)->sleep(),
            Limit::allow(200)->everyMinute()->sleep(),
        ];
    }

    protected function resolveRateLimitStore(): RateLimitStore
    {
        return new LaravelCacheStore(Cache::store());
    }

    protected function handleExceededLimit(Limit $limit, PendingRequest $pendingRequest): void
    {
        if (! $limit->getShouldSleep()) {
            $this->throwLimitException($limit);
        }

        $sleepSeconds = (int) ceil($limit->getRemainingSeconds());

        // Set a global sleep lock in Redis that ALL workers must respect
        $lockKey = 'rate_limit:sleep_until';
        $sleepUntil = now()->addSeconds($sleepSeconds)->timestamp;

        // Only set if this sleep is longer than any existing sleep
        $existingSleepUntil = Cache::get($lockKey, 0);
        if ($sleepUntil > $existingSleepUntil) {
            Cache::put($lockKey, $sleepUntil, $sleepSeconds);

            // Only track this sleep once (the worker that sets the lock)
            $this->trackSleep($sleepSeconds);
        }

        // Set the delay on the request
        $existingDelay = $pendingRequest->delay()->get() ?? 0;
        $remainingMilliseconds = $sleepSeconds * 1000;

        $pendingRequest->delay()->set($existingDelay + $remainingMilliseconds);
    }

    public function bootHasRateLimits(PendingRequest $pendingRequest): void
    {
        // Before processing any request, check if we're globally sleeping
        $lockKey = 'rate_limit:sleep_until';
        $sleepUntil = Cache::get($lockKey, 0);

        if ($sleepUntil > now()->timestamp) {
            $sleepSeconds = $sleepUntil - now()->timestamp;
            // Set delay without tracking (already tracked by the worker that set the lock)
            $pendingRequest->delay()->set($sleepSeconds * 1000);
        }

        // Call the original trait boot method
        parent::bootHasRateLimits($pendingRequest);
    }

    protected function trackSleep(int $seconds): void
    {
        if ($this->trackingImportId) {
            $import = \App\Models\Import::find($this->trackingImportId);
            if ($import) {
                $import->incrementRateLimitSleeps($seconds);
            }
        }
    }
}
