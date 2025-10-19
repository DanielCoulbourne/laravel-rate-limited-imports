<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Import extends Model
{
    protected $fillable = [
        'started_at',
        'ended_at',
        'items_count',
        'items_imported_count',
        'rate_limit_hits_count',
        'rate_limit_sleeps_count',
        'total_sleep_seconds',
        'finalize_attempts',
        'last_finalize_attempt_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'last_finalize_attempt_at' => 'datetime',
    ];

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
        return ImportedItem::where('import_id', $this->id)
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
}
