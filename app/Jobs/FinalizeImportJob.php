<?php

namespace App\Jobs;

use App\Models\ImportMeta\Import;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class FinalizeImportJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $importId
    ) {
    }

    /**
     * Execute the job.
     *
     * This job runs after all ImportItemDetailsJob jobs should be complete.
     * It checks if the import is actually complete, and if not, re-queues itself
     * to check again later.
     *
     * COMPLETION LOGIC:
     * 1. Check if 100% complete (all items imported successfully)
     * 2. Check if complete including failed items (items that failed > 5 minutes ago
     *    are considered permanently failed and won't retry)
     * 3. If neither, re-queue to check again in 10 seconds
     *
     * This approach is much more accurate than hard-coded attempt limits because
     * it tracks actual failure state at the item level.
     */
    public function handle(): void
    {
        $import = Import::find($this->importId);
        if (!$import) {
            return;
        }

        // Track this finalize attempt
        $import->incrementFinalizeAttempts();
        $import->refresh();

        // Check if import is 100% complete (all items imported)
        if ($import->isComplete()) {
            $import->markAsEnded();
            Log::info("Import {$import->id} completed successfully", [
                'items_count' => $import->items_count,
                'items_imported_count' => $import->items_imported_count,
            ]);
            return;
        }

        // Check if import is complete including permanently failed items
        // (items that failed > 5 minutes ago have exhausted all retries)
        if ($import->isCompleteIncludingFailed()) {
            $permanentlyFailed = $import->getPermanentlyFailedItemsCount();
            $import->markAsEnded();

            if ($permanentlyFailed > 0) {
                Log::warning("Import {$import->id} completed with {$permanentlyFailed} permanently failed items", [
                    'items_count' => $import->items_count,
                    'items_imported_count' => $import->items_imported_count,
                    'permanently_failed_count' => $permanentlyFailed,
                ]);
            } else {
                Log::info("Import {$import->id} completed", [
                    'items_count' => $import->items_count,
                    'items_imported_count' => $import->items_imported_count,
                ]);
            }
            return;
        }

        // Not complete yet, check again in 10 seconds
        static::dispatch($this->importId)->delay(now()->addSeconds(10));
    }
}
