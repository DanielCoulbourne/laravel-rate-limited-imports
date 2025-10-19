<?php

namespace App\Jobs;

use App\Api\RateTestConnector;
use App\Api\Requests\GetItemRequest;
use App\Models\Import;
use App\Models\ImportedItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

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
        public int $importedItemId,
        public int $apiItemId,
        public int $importId
    ) {
    }

    /**
     * Handle a job failure.
     *
     * This is called when the job has exhausted all retry attempts.
     * We mark the item as failed so the finalize job knows to skip it.
     */
    public function failed(\Throwable $exception): void
    {
        $importedItem = ImportedItem::find($this->importedItemId);
        if ($importedItem) {
            $importedItem->markAsFailed($exception->getMessage());
        }
    }

    /**
     * Execute the job.
     *
     * Fetches the full details for an item from the API and updates
     * the ImportedItem record with description and price.
     */
    public function handle(): void
    {
        $connector = new RateTestConnector($this->importId);
        $request = new GetItemRequest($this->apiItemId);

        $response = $connector->send($request);

        // Handle unexpected 429 responses with global sleep coordination
        // This should rarely happen since our rate limiter sleeps proactively
        while ($response->status() === 429) {
            // Track that we hit a rate limit
            $import = Import::find($this->importId);
            if ($import) {
                $import->incrementRateLimitHits();
            }

            $retryAfter = (int) ($response->header('Retry-After') ?? 60);

            // Atomically try to set global sleep lock
            // Only ONE worker will successfully set it
            $now = time();
            $sleepUntil = $now + $retryAfter;
            $lockKey = 'rate_limit:sleep_until';

            // Use add() for atomic SETNX operation
            if (Cache::add($lockKey, $sleepUntil, $retryAfter)) {
                // We successfully set the lock - track the sleep
                if ($import) {
                    $import->incrementRateLimitSleeps($retryAfter);
                }
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
            throw new \Exception("Failed to fetch item {$this->apiItemId}: {$response->status()}");
        }

        // Update the imported item with full details
        $itemData = $response->json();

        ImportedItem::where('id', $this->importedItemId)->update([
            'description' => $itemData['description'] ?? null,
            'price' => $itemData['price'] ?? null,
        ]);

        // Increment the import's items_imported_count
        $import = Import::find($this->importId);
        if ($import) {
            $import->incrementItemsImportedCount();
        }
    }
}
