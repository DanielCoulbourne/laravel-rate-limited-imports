<?php

namespace App\Api;

use App\Models\ImportMeta\Import;
use Saloon\RateLimitPlugin\Limit;

class TrackableLimit extends Limit
{
    protected ?int $importId = null;

    public static function trackable(int $maxRequests, ?int $importId = null): static
    {
        $instance = static::allow($maxRequests);
        $instance->importId = $importId;
        return $instance;
    }

    public function sleep(): static
    {
        $this->sleepWhenLimitReached = true;

        // Override the sleep behavior to track it
        $originalSleep = $this->sleepCallback ?? function ($seconds) {
            sleep($seconds);
        };

        $this->sleepCallback = function ($seconds) use ($originalSleep) {
            // Track the sleep
            if ($this->importId) {
                $import = Import::find($this->importId);
                if ($import) {
                    $import->incrementRateLimitSleeps($seconds);
                }
            }

            // Actually sleep
            $originalSleep($seconds);
        };

        return $this;
    }
}
