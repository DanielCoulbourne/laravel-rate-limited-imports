<?php

namespace App\Actions;

use App\Models\ImportMeta\Import;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Finalize Import Action
 *
 * This action runs after all ImportItemDetails jobs should be complete.
 * It checks if the import is actually complete, and if not, re-queues itself
 * to check again later.
 *
 * COMPLETION LOGIC:
 * 1. Check if 100% complete (all items imported successfully)
 * 2. Check if complete including failed items (items that failed > 5 minutes ago
 *    are considered permanently failed and won't retry)
 * 3. If neither, re-queue to check again in 10 seconds
 *
 * Can be used as:
 * - Queued job: FinalizeImport::dispatch($importId)->delay(now()->addSeconds(30))
 * - Direct call: FinalizeImport::run($importId)
 * - Command: php artisan import:finalize {importId}
 */
class FinalizeImport
{
    use AsAction;

    /**
     * Command signature for CLI usage
     */
    public string $commandSignature = 'import:finalize {importId}';

    /**
     * Command description
     */
    public string $commandDescription = 'Finalize an import and mark it as complete';

    /**
     * Handle the action
     */
    public function handle(int $importId): void
    {
        $import = Import::find($importId);
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
        static::dispatch($importId)->delay(now()->addSeconds(10));
    }

    /**
     * Configure as a queueable job
     */
    public function asJob(int $importId): void
    {
        $this->handle($importId);
    }
}
