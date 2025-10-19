<?php

namespace App\Jobs;

use App\Contracts\Importable;
use App\Models\Import;
use App\Models\ImportItemStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Generic job for importing item details
 *
 * This job is completely generic and works with any model that implements
 * the Importable interface. It fetches details from the API and delegates
 * the population logic to the model itself.
 *
 * All import tracking happens via the ImportItemStatus model, so the
 * actual model's table doesn't need any special columns.
 */
class ImportItemDetailsJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     *
     * Allow up to 5 attempts to handle transient failures like network issues,
     * temporary API errors, etc. With exponential backoff, this gives plenty
     * of opportunity for temporary issues to resolve.
     */
    public $tries = 5;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * Uses exponential backoff: 30s, 60s, 120s, 240s
     */
    public $backoff = [30, 60, 120, 240];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $importItemStatusId,
        public int $importId
    ) {
    }

    /**
     * Handle a job failure.
     *
     * This is called when the job has exhausted all retry attempts.
     * We mark the item status as failed so the finalize job knows to skip it.
     */
    public function failed(\Throwable $exception): void
    {
        $status = ImportItemStatus::find($this->importItemStatusId);
        if ($status) {
            $status->markAsFailed($exception->getMessage());
        }
    }

    /**
     * Execute the job.
     *
     * Fetches the full details for an item from the API and updates
     * the model using its own populateFromApiResponse method.
     */
    public function handle(): void
    {
        $status = ImportItemStatus::find($this->importItemStatusId);
        if (!$status) {
            return;
        }

        $model = $status->importable;
        if (!$model) {
            throw new \Exception("Importable model not found for status {$this->importItemStatusId}");
        }

        // Verify model implements Importable
        if (!$model instanceof Importable) {
            throw new \Exception("Model must implement Importable interface");
        }

        $import = Import::find($this->importId);
        if (!$import) {
            throw new \Exception("Import not found: {$this->importId}");
        }

        // Mark as processing
        $status->markAsProcessing();

        // Get the API request from the model
        $request = $model->getApiDetailRequest();

        // Get connector from import source (stored in metadata for now)
        // In a real package, this would be resolved via a registry or config
        $connectorClass = $import->getMetadata('connector_class');
        $connector = new $connectorClass($this->importId);

        $response = $connector->send($request);

        // Handle unexpected 429 responses with global sleep coordination
        // This should rarely happen since our rate limiter sleeps proactively
        while ($response->status() === 429) {
            // Track that we hit a rate limit
            $import->incrementRateLimitHits();

            $retryAfter = (int) ($response->header('Retry-After') ?? 60);

            // Atomically try to set global sleep lock
            // Only ONE worker will successfully set it
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
}
