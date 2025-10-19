<?php

namespace App\Actions;

use App\Contracts\Importable;
use App\Models\ImportMeta\Import;
use App\Models\ImportMeta\ImportedItemStatus;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Import Item Details Action
 *
 * This action is completely generic and works with any model that implements
 * the Importable interface. It fetches details from the API and delegates
 * the population logic to the model itself.
 *
 * Can be used as:
 * - Queued job: ImportItemDetails::dispatch($statusId, $importId)
 * - Direct call: ImportItemDetails::run($statusId, $importId)
 * - Command: php artisan import:item-details {statusId} {importId}
 */
class ImportItemDetails
{
    use AsAction;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 5;

    /**
     * The number of seconds to wait before retrying the job.
     * Uses exponential backoff: 30s, 60s, 120s, 240s
     */
    public array $backoff = [30, 60, 120, 240];

    /**
     * Command signature for CLI usage
     */
    public string $commandSignature = 'import:item-details {importItemStatusId} {importId}';

    /**
     * Command description
     */
    public string $commandDescription = 'Import details for a specific item';

    /**
     * Handle the action
     */
    public function handle(int $importItemStatusId, int $importId): void
    {
        $status = ImportedItemStatus::find($importItemStatusId);
        if (!$status) {
            return;
        }

        $model = $status->importable;
        if (!$model) {
            throw new \Exception("Importable model not found for status {$importItemStatusId}");
        }

        // Verify model implements Importable
        if (!$model instanceof Importable) {
            throw new \Exception("Model must implement Importable interface");
        }

        $import = Import::find($importId);
        if (!$import) {
            throw new \Exception("Import not found: {$importId}");
        }

        // Mark as processing
        $status->markAsProcessing();

        // Get the API request from the model
        $request = $model->getApiDetailRequest();

        // Get connector from import source (stored in metadata)
        $connectorClass = $import->getMetadata('connector_class');
        $connector = new $connectorClass($importId);

        $response = $connector->send($request);

        // Handle unexpected 429 responses with global sleep coordination
        while ($response->status() === 429) {
            // Track that we hit a rate limit
            $import->incrementRateLimitHits();

            $retryAfter = (int) ($response->header('Retry-After') ?? 60);

            // Atomically try to set global sleep lock
            $now = time();
            $sleepUntil = $now + $retryAfter;
            $lockKey = 'rate_limit:sleep_until';

            // Use add() for atomic SETNX operation
            if (Cache::add($lockKey, $sleepUntil, $retryAfter)) {
                // We successfully set the lock - track the sleep
                $import->incrementRateLimitSleeps($retryAfter);
                sleep($retryAfter);
            } else {
                // Another worker already set a sleep - just wait without tracking
                $existingSleepUntil = Cache::get($lockKey);
                if ($existingSleepUntil && $existingSleepUntil > $now) {
                    sleep($existingSleepUntil - $now);
                } else {
                    // Lock expired or invalid, sleep the retry-after duration
                    sleep($retryAfter);
                }
            }

            // Retry the request
            $response = $connector->send($request);
        }

        // If request failed for another reason, fail the job
        if ($response->failed()) {
            throw new \Exception("Failed to fetch item {$status->external_id}: {$response->status()}");
        }

        // Let the model populate itself from the API response
        $model->populateFromApiResponse($response->json());
        $model->save();

        // Mark status as completed
        $status->markAsCompleted();

        // Increment the import's items_imported_count
        $import->incrementItemsImportedCount();
    }

    /**
     * Handle a job failure.
     */
    public function failed(int $importItemStatusId, int $importId, \Throwable $exception): void
    {
        $status = ImportedItemStatus::find($importItemStatusId);
        if ($status) {
            $status->markAsFailed($exception->getMessage());
        }
    }

    /**
     * Configure as a queueable job
     */
    public function asJob(int $importItemStatusId, int $importId): void
    {
        $this->handle($importItemStatusId, $importId);
    }

    /**
     * Get job middleware
     */
    public function getJobMiddleware(): array
    {
        return [];
    }

    /**
     * Configure job properties
     */
    public function configureJob($job): void
    {
        $job->tries = $this->tries;
        $job->backoff = $this->backoff;
    }
}
