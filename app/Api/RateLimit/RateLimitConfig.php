<?php

declare(strict_types=1);

namespace App\Api\RateLimit;

/**
 * Configuration for global rate limiting.
 *
 * Defines rate limits and callbacks for tracking events.
 */
class RateLimitConfig
{
    /**
     * @param array<array{int, int}> $limits Array of [requests, seconds] pairs
     *                                       Example: [[10, 10], [200, 60]]
     * @param callable(int):void|null $onSleep Called when THIS worker sets a sleep lock
     *                                          with the number of seconds to sleep
     * @param callable():void|null $onHit Called when a rate limit is hit (optional)
     */
    public function __construct(
        public array $limits,
        public $onSleep = null,
        public $onHit = null,
    ) {
        // Validate limits format
        foreach ($limits as $limit) {
            if (!is_array($limit) || count($limit) !== 2) {
                throw new \InvalidArgumentException(
                    'Each limit must be an array of [requests, seconds]'
                );
            }
        }
    }
}
