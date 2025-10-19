<?php

namespace App\Jobs;

use App\Api\RateTestConnector;
use App\Api\Requests\GetItemRequest;
use App\Models\Import;
use App\Models\ImportedItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportItemDetailsJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 1;

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

        // Handle unexpected 429 responses by sleeping (same as preventative rate limiting)
        // This should rarely happen since Saloon's rate limiter sleeps proactively
        while ($response->status() === 429) {
            // Track that we hit a rate limit
            $import = Import::find($this->importId);
            if ($import) {
                $import->incrementRateLimitHits();
            }

            $retryAfter = (int) ($response->header('Retry-After') ?? 60);

            // Track the sleep time
            if ($import) {
                $import->incrementRateLimitSleeps($retryAfter);
            }

            // Sleep and retry
            sleep($retryAfter);
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
