<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Import Item Status Model
 *
 * Tracks the import progress of individual items without modifying
 * the imported model's table. Uses polymorphic relationships to work
 * with any model type.
 *
 * This model is part of the packageable import system and should not
 * need modification by package users.
 */
class ImportItemStatus extends Model
{
    protected $fillable = [
        'import_id',
        'importable_type',
        'importable_id',
        'external_id',
        'status',
        'last_failed_at',
        'failure_reason',
        'failure_count',
        'completed_at',
        'metadata',
    ];

    protected $casts = [
        'last_failed_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Get the import this status belongs to
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    /**
     * Get the importable model (polymorphic)
     *
     * This can be any model that implements the Importable interface
     */
    public function importable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Check if this item has permanently failed
     * (failed more than 5 minutes ago, meaning retries are exhausted)
     */
    public function hasPermanentlyFailed(): bool
    {
        return $this->status === 'failed'
            && $this->last_failed_at !== null
            && $this->last_failed_at->lt(now()->subMinutes(5));
    }

    /**
     * Mark as processing
     */
    public function markAsProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    /**
     * Mark as completed
     */
    public function markAsCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark as failed
     */
    public function markAsFailed(string $reason): void
    {
        $this->update([
            'status' => 'failed',
            'last_failed_at' => now(),
            'failure_reason' => $reason,
            'failure_count' => $this->failure_count + 1,
        ]);
    }

    /**
     * Store metadata
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
}
