<?php

namespace App\Console\Commands;

use App\Contracts\ImportSource;
use App\ImportSources\ItemImportSource;
use App\Jobs\FinalizeImportJob;
use App\Jobs\ImportItemDetailsJob;
use App\Models\Import;
use App\Models\ImportedItem;
use App\Models\ImportItemStatus;
use Illuminate\Console\Command;

/**
 * Import Items Command (Refactored)
 *
 * This command now uses the packageable import architecture.
 * It delegates to an ImportSource implementation which encapsulates
 * all the API-specific logic.
 *
 * The command itself is now mostly generic - it could work with any
 * ImportSource implementation, not just Items.
 */
class ImportItemsCommand extends Command
{
    protected $signature = 'import:items {--fresh : Delete all existing imports and items before starting}';

    protected $description = 'Import items from API using the packageable import system';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Create the import source (in a package, this would be configurable)
        $source = new ItemImportSource();

        // Handle --fresh flag
        if ($this->option('fresh')) {
            $this->warn('Clearing import tracking data...');

            // Clear imported items (separate table from api_items)
            ImportedItem::truncate();
            ImportItemStatus::truncate();
            Import::truncate();
            \Illuminate\Support\Facades\Cache::flush();

            $this->info('✓ Cleared all imports, import statuses, and imported items');
            $this->info('✓ API source data (api_items) preserved');
            $this->newLine();
        }

        $this->info('Starting item import using packageable architecture...');
        $this->info('This will paginate through all items and queue detail fetch jobs.');
        $this->newLine();

        // Create import record
        $import = Import::create([
            'importable_type' => $source->getModelClass(),
            'started_at' => now(),
        ]);

        // Store connector class in metadata for jobs to use
        $import->setMetadata('connector_class', get_class($source->getConnector($import->id)));

        $this->info("Import ID: {$import->id}");
        $this->info("Importing: {$source->getModelClass()}");
        $this->newLine();

        // PHASE 1: Discover items
        $this->info('PHASE 1: Discovering all items...');
        $this->newLine();

        $connector = $source->getConnector($import->id);
        $page = 1;
        $totalItems = 0;
        $statusesToQueue = [];

        do {
            $this->info("Fetching page {$page}...");

            $request = $source->getListRequest($page, 10);
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
                // Create the model using the source
                $model = $source->createModelFromListItem($item);

                // Create import status tracking (polymorphic)
                $status = ImportItemStatus::create([
                    'import_id' => $import->id,
                    'importable_type' => get_class($model),
                    'importable_id' => $model->id,
                    'external_id' => $item['id'],
                    'status' => 'pending',
                ]);

                $statusesToQueue[] = $status->id;
                $totalItems++;
            }

            // Update import items_count as we discover items
            $import->increment('items_count', count($items));

            $this->comment("  → Discovered {$totalItems} items");

            // Check if there's a next page using the source
            $hasNextPage = $source->hasNextPage($data);

            if ($hasNextPage) {
                $page++;
            } else {
                break;
            }

        } while (true);

        $this->newLine();
        $this->info("✓ Pagination complete!");
        $this->info("  Total items discovered: {$totalItems}");
        $this->newLine();

        // PHASE 2: Queue detail jobs
        $this->info('PHASE 2: Queueing detail fetch jobs...');
        $this->newLine();

        $jobsQueued = 0;
        foreach ($statusesToQueue as $statusId) {
            ImportItemDetailsJob::dispatch(
                importItemStatusId: $statusId,
                importId: $import->id
            );
            $jobsQueued++;

            if ($jobsQueued % 100 === 0) {
                $this->comment("  → Queued {$jobsQueued}/{$totalItems} jobs");
            }
        }

        $this->newLine();
        $this->info("✓ All {$totalItems} detail jobs queued!");

        // Queue the finalize job
        FinalizeImportJob::dispatch($import->id)->delay(now()->addSeconds(30));

        $this->newLine();
        $this->info("✓ Import started!");
        $this->comment("Import ID: {$import->id}");
        $this->comment("Model: {$source->getModelClass()}");
        $this->comment('Monitor progress at: http://rate-test.test/admin/imports/' . $import->id);
        $this->comment('Or check: Import::find(' . $import->id . ')');

        return Command::SUCCESS;
    }
}
