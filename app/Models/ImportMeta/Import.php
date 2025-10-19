<?php

namespace App\Models\ImportMeta;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Import extends Model
{
    protected $fillable = [
        'importable_type',
        'status',
        'scheduled_at',
        'started_at',
        'ended_at',
        'cancelled_at',
        'items_count',
        'items_imported_count',
        'rate_limit_hits_count',
        'rate_limit_sleeps_count',
        'total_sleep_seconds',
        'finalize_attempts',
        'last_finalize_attempt_at',
        'metadata',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'last_finalize_attempt_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Get all item statuses for this import
     */
    public function itemStatuses(): HasMany
    {
        return $this->hasMany(ImportedItemStatus::class);
    }

    /**
     * Increment the items count
     */
    public function incrementItemsCount(int $amount = 1): void
    {
        static::where('id', $this->id)->increment('items_count', $amount);
    }

    /**
     * Increment the items imported count
     */
    public function incrementItemsImportedCount(int $amount = 1): void
    {
        static::where('id', $this->id)->increment('items_imported_count', $amount);
    }

    /**
     * Mark the import as ended
     */
    public function markAsEnded(): void
    {
        $this->fresh()->update(['ended_at' => now()]);
    }

    /**
     * Check if import is complete
     */
    public function isComplete(): bool
    {
        return $this->items_count > 0 && $this->items_count === $this->items_imported_count;
    }

    /**
     * Get count of items that have permanently failed (last failed > 5 minutes ago)
     *
     * If an item failed more than 5 minutes ago, it means it's exhausted all retries
     * and won't be attempted again (with our exponential backoff of 30s, 60s, 120s, 240s).
     */
    public function getPermanentlyFailedItemsCount(): int
    {
        return $this->itemStatuses()
            ->where('status', 'failed')
            ->whereNotNull('last_failed_at')
            ->where('last_failed_at', '<=', now()->subMinutes(5))
            ->count();
    }

    /**
     * Check if import is complete including permanently failed items
     *
     * An import is considered complete when:
     * items_imported_count + permanently_failed_count >= items_count
     *
     * This means all items have either been successfully imported OR
     * have permanently failed (exhausted all retries > 5 minutes ago).
     */
    public function isCompleteIncludingFailed(): bool
    {
        if ($this->items_count === 0) {
            return false;
        }

        $permanentlyFailed = $this->getPermanentlyFailedItemsCount();
        $accountedFor = $this->items_imported_count + $permanentlyFailed;

        return $accountedFor >= $this->items_count;
    }

    /**
     * Increment finalize attempts counter
     */
    public function incrementFinalizeAttempts(): void
    {
        static::where('id', $this->id)->update([
            'finalize_attempts' => $this->finalize_attempts + 1,
            'last_finalize_attempt_at' => now(),
        ]);
    }

    /**
     * Get the progress percentage
     */
    public function getProgressPercentage(): float
    {
        if ($this->items_count === 0) {
            return 0;
        }

        return round(($this->items_imported_count / $this->items_count) * 100, 2);
    }

    /**
     * Increment rate limit hits count
     */
    public function incrementRateLimitHits(int $amount = 1): void
    {
        static::where('id', $this->id)->increment('rate_limit_hits_count', $amount);
    }

    /**
     * Increment rate limit sleeps count and total sleep seconds
     */
    public function incrementRateLimitSleeps(int $sleepSeconds): void
    {
        static::where('id', $this->id)->increment('rate_limit_sleeps_count');
        static::where('id', $this->id)->increment('total_sleep_seconds', $sleepSeconds);
    }

    /**
     * Get the total duration in seconds
     */
    public function getTotalDurationSeconds(): ?int
    {
        if (!$this->started_at) {
            return null;
        }

        $end = $this->ended_at ?? now();
        return $this->started_at->diffInSeconds($end);
    }

    /**
     * Get active importing time (total - sleep time)
     */
    public function getActiveImportingSeconds(): ?int
    {
        $total = $this->getTotalDurationSeconds();
        if ($total === null) {
            return null;
        }

        return max(0, $total - $this->total_sleep_seconds);
    }

    /**
     * Set metadata value
     */
    public function setMetadata(string $key, mixed $value): void
    {
        $metadata = $this->metadata ?? [];
        $metadata[$key] = $value;
        $this->update(['metadata' => $metadata]);
    }

    /**
     * Get metadata value
     */
    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Check if import is scheduled for the future
     */
    public function isScheduled(): bool
    {
        return $this->status === 'scheduled' && $this->scheduled_at && $this->scheduled_at->isFuture();
    }

    /**
     * Check if import is overdue (scheduled in the past but not started)
     */
    public function isOverdue(): bool
    {
        return $this->status === 'scheduled'
            && $this->scheduled_at
            && $this->scheduled_at->isPast()
            && !$this->started_at;
    }

    /**
     * Check if import is running
     */
    public function isRunning(): bool
    {
        return $this->status === 'running' && $this->started_at && !$this->ended_at;
    }

    /**
     * Check if import is cancelled
     */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled' && $this->cancelled_at !== null;
    }

    /**
     * Mark import as cancelled
     */
    public function markAsCancelled(): void
    {
        $this->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);
    }

    /**
     * Mark import as started
     */
    public function markAsStarted(): void
    {
        $this->update([
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    /**
     * Get the next scheduled import time based on configured schedule
     * Schedule: 0, 3, 6, 9, 12, 15, 18, 21 o'clock
     */
    public static function getNextScheduledTime(): \Carbon\Carbon
    {
        $schedule = [0, 3, 6, 9, 12, 15, 18, 21];
        $now = now();
        $currentHour = $now->hour;

        // Find the next scheduled hour
        foreach ($schedule as $hour) {
            if ($hour > $currentHour) {
                return $now->copy()->setHour($hour)->setMinute(0)->setSecond(0);
            }
        }

        // If no hour found today, schedule for first slot tomorrow
        return $now->copy()->addDay()->setHour($schedule[0])->setMinute(0)->setSecond(0);
    }

    /**
     * Get the latest scheduled import
     */
    public static function getLatestScheduled(): ?self
    {
        return static::where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->orderBy('scheduled_at', 'desc')
            ->first();
    }

    /**
     * Get all overdue imports
     */
    public static function getOverdueImports()
    {
        return static::where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->whereNull('started_at')
            ->orderBy('scheduled_at', 'desc')
            ->get();
    }

    /**
     * Check if there's a future scheduled import
     */
    public static function hasFutureScheduledImport(): bool
    {
        return static::where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>', now())
            ->exists();
    }
}
