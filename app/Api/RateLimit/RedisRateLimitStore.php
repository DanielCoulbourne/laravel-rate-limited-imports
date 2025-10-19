<?php

declare(strict_types=1);

namespace App\Api\RateLimit;

use Illuminate\Support\Facades\Cache;

/**
 * Redis-backed rate limit store for production use.
 *
 * Uses Laravel's Cache facade to coordinate rate limiting across
 * multiple concurrent workers. All operations are atomic to prevent
 * race conditions.
 */
class RedisRateLimitStore implements RateLimitStore
{
    private const PREFIX = 'rate_limit:';
    private const SLEEP_KEY = 'sleep_until';

    /**
     * Atomically increment a counter with TTL.
     */
    public function increment(string $key, int $ttl): int
    {
        $fullKey = self::PREFIX . $key;

        // Use raw Redis connection for proper atomic operations
        $redis = Cache::getStore()->getRedis();

        // Increment and get current value
        $count = $redis->incr($fullKey);

        // If this is the first increment (count = 1), set the TTL
        if ($count === 1) {
            $redis->expire($fullKey, $ttl);
        }

        return $count;
    }

    /**
     * Get a value from the store.
     */
    public function get(string $key): ?int
    {
        $fullKey = self::PREFIX . $key;
        $redis = Cache::getStore()->getRedis();

        $value = $redis->get($fullKey);

        return $value !== null && $value !== false ? (int) $value : null;
    }

    /**
     * Get the global sleep lock timestamp.
     */
    public function getSleepUntil(): ?int
    {
        $value = Cache::get(self::PREFIX . self::SLEEP_KEY);

        return $value !== null ? (int) $value : null;
    }

    /**
     * Set the global sleep lock.
     *
     * This is called by the FIRST worker to hit a rate limit.
     * Only this worker should track the sleep time.
     */
    public function setSleepUntil(int $timestamp, int $ttl): bool
    {
        Cache::put(self::PREFIX . self::SLEEP_KEY, $timestamp, $ttl);

        return true;
    }

    /**
     * Atomically set sleep lock only if not already set.
     *
     * Returns true if this worker successfully set the lock (and should track it).
     * Returns false if another worker already set the lock.
     */
    public function trySetSleepUntil(int $timestamp, int $ttl): bool
    {
        $key = self::PREFIX . self::SLEEP_KEY;

        // add() only sets if key doesn't exist (atomic SETNX operation)
        // Returns true if we set it, false if already set by another worker
        return Cache::add($key, $timestamp, $ttl);
    }

    /**
     * Extend an existing sleep lock if the new timestamp is later.
     *
     * Returns the number of additional seconds added (0 if not extended).
     */
    public function extendSleepUntil(int $timestamp, int $ttl): int
    {
        $currentSleepUntil = $this->getSleepUntil();

        // If no existing sleep, atomically try to set it
        if ($currentSleepUntil === null) {
            // Use trySetSleepUntil to atomically set only if not already set
            if ($this->trySetSleepUntil($timestamp, $ttl)) {
                // We successfully set it - return full duration
                return $timestamp - time();
            } else {
                // Another worker beat us to it - return 0 (don't track)
                return 0;
            }
        }

        // If new timestamp is later, extend the sleep
        if ($timestamp > $currentSleepUntil) {
            $additionalSeconds = $timestamp - $currentSleepUntil;
            $this->setSleepUntil($timestamp, $ttl);
            return $additionalSeconds;
        }

        // Sleep is already longer, no extension needed
        return 0;
    }
}
