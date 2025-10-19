<?php

declare(strict_types=1);

namespace App\Api\RateLimit;

use Saloon\Http\PendingRequest;

/**
 * Global Rate Limiting for Multi-Worker Environments
 *
 * This trait provides coordinated rate limiting across multiple concurrent workers
 * using a shared storage backend (Redis). It solves the problem of duplicate sleep
 * tracking when multiple workers hit the same rate limit simultaneously.
 *
 * HOW IT HOOKS INTO SALOON:
 * -------------------------
 * Saloon's boot system automatically calls `boot{TraitName}(PendingRequest $pendingRequest)`
 * for each trait used by a Connector. This is similar to Laravel's model boot methods.
 *
 * When a request is being prepared:
 * 1. Saloon detects this trait on the Connector
 * 2. Calls `bootHasGlobalRateLimiting($pendingRequest)` (implemented below)
 * 3. We register middleware on the PendingRequest using:
 *    - `$pendingRequest->middleware()->onRequest()` - runs BEFORE the HTTP request
 *
 * EXTENSION APPROACH:
 * -------------------
 * We follow Saloon's official plugin pattern (same as HasRateLimits trait):
 * - Boot method to hook into request lifecycle
 * - Middleware for pre-request logic
 * - No need to override core Saloon methods
 * - Clean separation of concerns
 *
 * WHY NOT USE SALOON'S HasRateLimits:
 * -----------------------------------
 * Saloon's built-in rate limiting uses `$pendingRequest->delay()->set()` which:
 * - Tracks delay per-request, not globally
 * - Doesn't coordinate across workers
 * - Results in duplicate sleep tracking
 *
 * Our approach uses Redis to ensure:
 * - Only ONE worker tracks each sleep period
 * - All workers respect the same sleep state
 * - Metrics are accurate (sleep time ≤ elapsed time)
 *
 * COMPATIBILITY:
 * --------------
 * - Tested with Saloon v3.x
 * - Uses standard Saloon extension points (middleware, boot)
 * - No internal hacks or monkey patches
 * - Safe for package extraction
 *
 * @see https://docs.saloon.dev/ for Saloon documentation
 * @see vendor/saloonphp/rate-limit-plugin/src/Traits/HasRateLimits.php for comparison
 */
trait HasGlobalRateLimiting
{
    protected ?RateLimitStore $rateLimitStore = null;

    /**
     * Boot the global rate limiting functionality.
     *
     * This method is automatically called by Saloon's boot system
     * before each request is sent.
     */
    public function bootHasGlobalRateLimiting(PendingRequest $pendingRequest): void
    {
        $this->rateLimitStore = $this->resolveRateLimitStore();
        $config = $this->rateLimitConfig();

        // Register middleware to run BEFORE the HTTP request
        $pendingRequest->middleware()->onRequest(function (PendingRequest $request) use ($config) {
            $this->checkAndEnforceRateLimits($config);
            return $request;
        });
    }

    /**
     * Check global sleep state and enforce rate limits.
     *
     * This method:
     * 1. Checks if another worker already set a global sleep lock
     * 2. Checks if this request would exceed any limit
     * 3. Increments request counters
     */
    protected function checkAndEnforceRateLimits(RateLimitConfig $config): void
    {
        // STEP 1: Check if globally sleeping (another worker set this)
        $this->respectGlobalSleep();

        // STEP 2: Check if we would exceed any limit (might need to sleep ourselves)
        $this->checkLimitsAndSleepIfNeeded($config);

        // STEP 3: Increment counters (we're about to make a request)
        $this->incrementRequestCounters($config);
    }

    /**
     * Check if another worker has set a global sleep lock and wait if needed.
     *
     * This ensures all workers respect the same sleep state.
     * We do NOT track this sleep (the worker who set the lock already tracked it).
     */
    protected function respectGlobalSleep(): void
    {
        $sleepUntil = $this->rateLimitStore->getSleepUntil();

        if ($sleepUntil === null) {
            return; // Not sleeping
        }

        $now = time();

        if ($sleepUntil <= $now) {
            return; // Sleep period has ended
        }

        // Another worker set this sleep - we wait but don't track
        $sleepSeconds = $sleepUntil - $now;
        sleep($sleepSeconds);
    }

    /**
     * Check if any limit would be exceeded and set global sleep if needed.
     *
     * Checks ALL limits and sets ONE global sleep for the most restrictive limit.
     * Only ONE worker will successfully set the lock and track the sleep.
     */
    protected function checkLimitsAndSleepIfNeeded(RateLimitConfig $config): void
    {
        // Check all limits and find the one that requires the longest sleep
        $longestSleepSeconds = 0;

        foreach ($config->limits as [$requests, $seconds]) {
            $key = "limit:{$requests}:{$seconds}";
            $count = $this->rateLimitStore->get($key) ?? 0;

            if ($count >= $requests) {
                // This limit is exceeded - would need to sleep
                $longestSleepSeconds = max($longestSleepSeconds, $seconds);
            }
        }

        // If any limit was exceeded, set ONE global sleep
        if ($longestSleepSeconds > 0) {
            $now = time();
            $sleepUntil = $now + $longestSleepSeconds;

            // Atomically try to set the sleep lock
            // Only ONE worker will succeed and should track
            if ($this->rateLimitStore->trySetSleepUntil($sleepUntil, $longestSleepSeconds)) {
                // We successfully set the lock - track and sleep
                $this->trackSleep($longestSleepSeconds, $config);
                sleep($longestSleepSeconds);
            } else {
                // Another worker already set a sleep lock - just wait without tracking
                $existingSleepUntil = $this->rateLimitStore->getSleepUntil();
                if ($existingSleepUntil !== null && $existingSleepUntil > $now) {
                    $remainingSeconds = $existingSleepUntil - $now;
                    sleep($remainingSeconds);
                }
            }

            // After sleeping, check all limits again (recursive, but should pass now)
            $this->checkLimitsAndSleepIfNeeded($config);
        }
    }

    /**
     * Increment request counters for all limits.
     *
     * This is called right before making the HTTP request.
     */
    protected function incrementRequestCounters(RateLimitConfig $config): void
    {
        foreach ($config->limits as [$requests, $seconds]) {
            $key = "limit:{$requests}:{$seconds}";
            $this->rateLimitStore->increment($key, $seconds);
        }
    }

    /**
     * Track a sleep period via the configured callback.
     */
    protected function trackSleep(int $seconds, RateLimitConfig $config): void
    {
        if ($config->onSleep !== null) {
            ($config->onSleep)($seconds);
        }
    }

    /**
     * Resolve the rate limit store instance.
     *
     * Override this method to use a different store (e.g., for testing).
     */
    protected function resolveRateLimitStore(): RateLimitStore
    {
        return new RedisRateLimitStore();
    }

    /**
     * Define the rate limit configuration.
     *
     * Must be implemented by the connector using this trait.
     */
    abstract protected function rateLimitConfig(): RateLimitConfig;
}
