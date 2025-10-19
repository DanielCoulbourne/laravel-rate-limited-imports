<?php

namespace App\Actions;

use App\Contracts\ImportSource;
use App\ImportSources\ItemImportSource;
use App\Models\ImportMeta\Import;
use App\Models\ImportMeta\ImportedItem;
use App\Models\ImportMeta\ImportedItemStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;

class ImportItems
{
    use AsAction;

    public string $commandSignature = 'import:items {--fresh : Delete all existing imports and items before starting}';
    public string $commandDescription = 'Import items from API using the packageable import system';

    /**
     * Get the unique ID for the job.
     * This ensures only one import can run at a time.
     */
    public function uniqueId(): string
    {
        return 'import-items';
    }

    /**
     * Configure job properties
     */
    public function configureJob($job): void
    {
        $job->onQueue('imports');
    }

    public function handle(bool $fresh = false, ?int $importId = null): int
    {
        $source = new ItemImportSource();

        if ($fresh) {
            ImportedItem::truncate();
            ImportedItemStatus::truncate();
            Import::truncate();
            Cache::flush();
        }

        // Use provided import or create new one
        if ($importId) {
            $import = Import::findOrFail($importId);
            $import->markAsStarted();
            // Initialize items_count to 0 when starting a scheduled import
            $import->update(['items_count' => 0]);
        } else {
            $import = Import::create([
                'importable_type' => $source->getModelClass(),
                'status' => 'running',
                'started_at' => now(),
                'items_count' => 0,
            ]);
        }

        // Schedule next import immediately if there isn't one already
        // This ensures there's always a future import scheduled even if this one fails
        if (!Import::hasFutureScheduledImport()) {
            ScheduleImport::dispatch();
        }

        $import->setMetadata('connector_class', get_class($source->getConnector($import->id)));

        $connector = $source->getConnector($import->id);
        $page = 1;
        $totalItems = 0;
        $statusesToQueue = [];

        do {
            $request = $source->getListRequest($page, 10);
            $response = $connector->send($request);

            if ($response->status() === 429) {
                $retryAfter = (int) ($response->header('Retry-After') ?? 60);
                sleep($retryAfter);
                continue;
            }

            if ($response->failed()) {
                return Command::FAILURE;
            }

            $data = $response->json();
            $items = $data['data'] ?? [];

            foreach ($items as $item) {
                $model = $source->createModelFromListItem($item);

                $status = ImportedItemStatus::create([
                    'import_id' => $import->id,
                    'importable_type' => get_class($model),
                    'importable_id' => $model->id,
                    'external_id' => $item['id'],
                    'status' => 'pending',
                ]);

                $statusesToQueue[] = $status->id;
                $totalItems++;
            }

            $import->increment('items_count', count($items));

            $hasNextPage = $source->hasNextPage($data);
            if ($hasNextPage) {
                $page++;
            } else {
                break;
            }
        } while (true);

        foreach ($statusesToQueue as $statusId) {
            ImportItemDetails::dispatch($statusId, $import->id);
        }

        FinalizeImport::dispatch($import->id)->delay(now()->addSeconds(30));

        return Command::SUCCESS;
    }

    public function asCommand(Command $command): int
    {
        $fresh = $command->option('fresh');

        if ($fresh) {
            $command->warn('Clearing import tracking data...');
        }

        $command->info('Starting item import...');

        $result = $this->handle($fresh);

        if ($result === Command::SUCCESS) {
            $command->info('✓ Import started successfully!');
            $command->comment('Monitor progress at: http://rate-test.test/admin/imports');
        }

        return $result;
    }
}
