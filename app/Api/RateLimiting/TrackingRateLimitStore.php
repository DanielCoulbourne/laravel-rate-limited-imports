<?php

namespace App\Api\RateLimiting;

use App\Models\Import;
use Saloon\RateLimitPlugin\Contracts\RateLimitStore;
use Saloon\RateLimitPlugin\Stores\LaravelCacheStore;

/**
 * Rate Limit Store that tracks sleep events for Import metrics
 *
 * This wraps the LaravelCacheStore and intercepts sleep operations
 * to track how many times we sleep and for how long.
 */
class TrackingRateLimitStore implements RateLimitStore
{
    protected LaravelCacheStore $baseStore;
    protected ?int $importId = null;

    public function __construct(LaravelCacheStore $baseStore)
    {
        $this->baseStore = $baseStore;
    }

    /**
     * Set the import ID to track metrics for
     */
    public function setImportId(?int $importId): void
    {
        $this->importId = $importId;
    }

    /**
     * Track a sleep event
     */
    public function trackSleep(int $seconds): void
    {
        if ($this->importId) {
            $import = Import::find($this->importId);
            if ($import) {
                $import->incrementRateLimitSleeps($seconds);
            }
        }
    }

    /**
     * Delegate to base store
     */
    public function get(string $key): ?string
    {
        return $this->baseStore->get($key);
    }

    /**
     * Delegate to base store
     */
    public function set(string $key, string $value, int $ttl): bool
    {
        return $this->baseStore->set($key, $value, $ttl);
    }
}
