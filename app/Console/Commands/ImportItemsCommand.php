<?php

namespace App\Console\Commands;

use App\Api\RateTestConnector;
use App\Api\Requests\GetItemsRequest;
use App\Jobs\FinalizeImportJob;
use App\Jobs\ImportItemDetailsJob;
use App\Models\Import;
use App\Models\ImportedItem;
use Illuminate\Console\Command;

/**
 * Import Items Command
 *
 * PURPOSE:
 * This command demonstrates handling high-volume API imports with rate limiting
 * and concurrent queue workers. It's designed to handle scenarios where you need
 * to download thousands of items from a rate-limited API as quickly as possible
 * while respecting both client-side and server-side rate limits.
 *
 * THE PROBLEM WE'RE SOLVING:
 * When importing large datasets from APIs, you often hit these challenges:
 * 1. Rate limits prevent bulk downloads
 * 2. Multiple concurrent queue workers can overwhelm rate limits
 * 3. Workers may wake up simultaneously and hit limits again
 * 4. APIs may have unpublished or changing rate limits
 *
 * OUR SOLUTION:
 * - Use Saloon's client-side rate limiting with ->sleep() to proactively throttle
 * - Use LaravelCacheStore to share rate limit state across ALL queue workers
 * - Handle unexpected 429 responses gracefully with job release/retry
 * - Two-phase import: quick list fetch, then queue detail jobs for parallelization
 * - Track import progress with Import model
 *
 * HOW IT WORKS:
 * 1. Create an Import record with started_at timestamp
 * 2. Parent command paginates through item list endpoint
 * 3. For each item, creates an ImportedItem with just the name (fast)
 * 4. Immediately dispatches a job to fetch full details (parallelized as we go)
 * 5. Each detail job increments items_imported_count when complete
 * 6. After pagination, dispatch finalize job to set ended_at
 * 7. Multiple queue workers process detail jobs concurrently
 * 8. Rate limiting prevents workers from overwhelming the API
 *
 * RATE LIMITING STRATEGY:
 * - Client-side: Saloon tracks requests and sleeps BEFORE hitting limits
 * - Shared state: LaravelCacheStore ensures all workers see the same counters
 * - Fallback: Jobs catch 429 responses and release back to queue with retry-after
 *
 * RUNNING WITH CONCURRENT WORKERS:
 * Terminal 1: php artisan import:items
 * Terminal 2: php artisan horizon (manages multiple workers automatically)
 *
 * The workers will process detail jobs in parallel while respecting
 * the shared rate limit state.
 */
class ImportItemsCommand extends Command
{
    protected $signature = 'import:items {--fresh : Delete all existing imports and items before starting}';

    protected $description = 'Import items from API with rate limit handling and concurrent job processing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Handle --fresh flag
        if ($this->option('fresh')) {
            $this->warn('Clearing all existing data...');
            \App\Models\ImportedItem::truncate();
            \App\Models\Import::truncate();
            \Illuminate\Support\Facades\Cache::flush();
            $this->info('✓ Cleared all imports, items, and cache');
            $this->newLine();
        }

        $this->info('Starting item import...');
        $this->info('This will paginate through all items and queue detail fetch jobs.');
        $this->newLine();

        // Create import record
        $import = Import::create([
            'started_at' => now(),
        ]);

        $this->info("Import ID: {$import->id}");
        $this->newLine();

        $connector = new RateTestConnector($import->id);
        $page = 1;
        $totalItems = 0;

        do {
            $this->info("Fetching page {$page}...");

            $request = new GetItemsRequest(page: $page, perPage: 10);
            $response = $connector->send($request);

            // Handle unexpected 429 responses
            if ($response->status() === 429) {
                $retryAfter = (int) ($response->header('Retry-After') ?? 60);
                $this->warn("Hit rate limit on page {$page}. Sleeping for {$retryAfter} seconds...");
                sleep($retryAfter);
                continue; // Retry same page
            }

            if ($response->failed()) {
                $this->error("Failed to fetch page {$page}: {$response->status()}");
                return Command::FAILURE;
            }

            $data = $response->json();
            $items = $data['data'] ?? [];

            foreach ($items as $item) {
                // Create ImportedItem with just the name (fast)
                $importedItem = ImportedItem::create([
                    'name' => $item['name'],
                ]);

                // Dispatch detail job IMMEDIATELY (don't wait for pagination to complete)
                ImportItemDetailsJob::dispatch(
                    importedItemId: $importedItem->id,
                    apiItemId: $item['id'],
                    importId: $import->id
                );

                $totalItems++;
            }

            // Update import items_count as we discover items
            Import::where('id', $import->id)->increment('items_count', count($items));

            $this->comment("  → Created {$totalItems} items, queued {$totalItems} jobs");

            // Check if there's a next page
            $hasNextPage = !empty($data['next_page_url']) ||
                          ($data['current_page'] ?? 0) < ($data['last_page'] ?? 0);

            if ($hasNextPage) {
                $page++;
            } else {
                break;
            }

        } while (true);

        $this->newLine();
        $this->info("✓ Pagination complete!");
        $this->info("  Total items discovered: {$totalItems}");
        $this->info("  All {$totalItems} detail jobs queued!");

        // Queue the finalize job with a delay to give jobs time to process
        // The finalize job will check if import is complete and re-queue itself if not
        FinalizeImportJob::dispatch($import->id)->delay(now()->addSeconds(30));

        $this->newLine();
        $this->info("✓ Import started!");
        $this->comment("Import ID: {$import->id}");
        $this->comment('Monitor progress at: http://rate-test.test/horizon');
        $this->comment('Or check: Import::find(' . $import->id . ')');

        return Command::SUCCESS;
    }
}
