<?php

namespace App\Traits;

use App\Models\ImportMeta\ImportedItemStatus;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Trait for models that can be tracked during import
 *
 * This trait provides the polymorphic relationship to ImportItemStatus
 * and helper methods for updating import progress. Models using this
 * trait don't need any table modifications - all tracking happens in
 * the import_item_statuses table.
 *
 * Usage:
 * ```php
 * class Item extends Model implements Importable
 * {
 *     use HasImportStatus;
 *
 *     // ... implement Importable methods
 * }
 * ```
 */
trait HasImportStatus
{
    /**
     * Get the import status for this model
     *
     * This is a polymorphic relationship allowing any model to have
     * an associated import status without modifying its table.
     */
    public function importStatus(): MorphOne
    {
        return $this->morphOne(ImportedItemStatus::class, 'importable');
    }

    /**
     * Mark this item's import as completed
     *
     * Updates the import status to 'completed' and records completion time.
     */
    public function markImportAsCompleted(): void
    {
        $this->importStatus?->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark this item's import as failed
     *
     * Records the failure reason and increments the failure counter.
     * This is automatically called when ImportItemDetailsJob fails.
     *
     * @param string $reason The exception message or failure reason
     */
    public function markImportAsFailed(string $reason): void
    {
        if (!$this->importStatus) {
            return;
        }

        $this->importStatus->update([
            'status' => 'failed',
            'last_failed_at' => now(),
            'failure_reason' => $reason,
            'failure_count' => $this->importStatus->failure_count + 1,
        ]);
    }

    /**
     * Check if this item's import is completed
     */
    public function isImportCompleted(): bool
    {
        return $this->importStatus?->status === 'completed';
    }

    /**
     * Check if this item's import failed
     */
    public function isImportFailed(): bool
    {
        return $this->importStatus?->status === 'failed';
    }

    /**
     * Check if this item's import is pending
     */
    public function isImportPending(): bool
    {
        return $this->importStatus?->status === 'pending';
    }
}
