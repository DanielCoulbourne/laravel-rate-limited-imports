<?php

declare(strict_types=1);

namespace App\Api\RateLimit;

/**
 * Storage abstraction for rate limit coordination across workers.
 *
 * This interface allows swapping storage backends (Redis, Database, Memory)
 * for different environments (production, testing, development).
 */
interface RateLimitStore
{
    /**
     * Atomically increment a counter with TTL.
     *
     * @param string $key The counter key (e.g., "limit:10:10")
     * @param int $ttl Time to live in seconds
     * @return int The new count value
     */
    public function increment(string $key, int $ttl): int;

    /**
     * Get a value from the store.
     *
     * @param string $key The key to retrieve
     * @return int|null The value or null if not found
     */
    public function get(string $key): ?int;

    /**
     * Get the global sleep lock timestamp.
     *
     * @return int|null Unix timestamp when sleep ends, or null if not sleeping
     */
    public function getSleepUntil(): ?int;

    /**
     * Set the global sleep lock.
     *
     * All workers must check this before making requests.
     * Only the worker that sets this lock should track the sleep time.
     *
     * @param int $timestamp Unix timestamp when sleep should end
     * @param int $ttl Time to live in seconds (for automatic cleanup)
     * @return bool True if successfully set
     */
    public function setSleepUntil(int $timestamp, int $ttl): bool;

    /**
     * Atomically set sleep lock only if not already set.
     *
     * @param int $timestamp Unix timestamp when sleep should end
     * @param int $ttl Time to live in seconds
     * @return bool True if successfully set, false if already set by another worker
     */
    public function trySetSleepUntil(int $timestamp, int $ttl): bool;

    /**
     * Extend an existing sleep lock if the new timestamp is later.
     *
     * Returns the number of additional seconds added (0 if not extended).
     *
     * @param int $timestamp Unix timestamp when sleep should end
     * @param int $ttl Time to live in seconds
     * @return int Additional seconds added (0 if not extended)
     */
    public function extendSleepUntil(int $timestamp, int $ttl): int;
}
