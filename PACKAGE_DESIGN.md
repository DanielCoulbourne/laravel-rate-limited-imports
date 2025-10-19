# Package Design: Laravel Rate-Limited Imports

## Goal
Extract the import system into a reusable package that can be applied to any Laravel model without modifying the model's table structure.

## Current Architecture Issues
1. **Tight coupling**: ImportedItem model is specific to our use case
2. **Table modifications**: Adding failure tracking columns directly to `imported_items`
3. **Hard-coded logic**: ImportItemDetailsJob knows about specific model structure
4. **No abstraction**: Command is specific to Item imports

## Proposed Package Architecture

### Core Concepts

1. **Separate tracking tables**: Don't modify user's model tables
2. **Contracts/Interfaces**: Define what an importable model must implement
3. **Traits**: Add import capabilities to any model
4. **Generic jobs**: Work with any model that implements the contract
5. **Configuration-driven**: Users configure what to import, we handle how

### Database Schema

```
imports
├─ id
├─ importable_type (polymorphic - what model is being imported)
├─ started_at
├─ ended_at
├─ items_count
├─ items_imported_count
├─ rate_limit_hits_count
├─ rate_limit_sleeps_count
├─ total_sleep_seconds
├─ finalize_attempts
└─ last_finalize_attempt_at

import_item_statuses
├─ id
├─ import_id (foreign key to imports)
├─ importable_type (polymorphic)
├─ importable_id (polymorphic - the actual model ID)
├─ external_id (the ID from the external API)
├─ status (enum: pending, processing, completed, failed)
├─ last_failed_at
├─ failure_reason
├─ failure_count
├─ completed_at
└─ metadata (json - for flexible data storage)
```

### Interfaces

```php
interface Importable
{
    // Get the external ID for this model
    public function getExternalId(): string|int;
    
    // Populate model from API response data
    public function populateFromApiResponse(array $data): void;
    
    // Get the API request for fetching this item's details
    public function getApiDetailRequest(): Request;
}

interface ImportSource
{
    // Get the list request for discovering items
    public function getListRequest(int $page, int $perPage): Request;
    
    // Create a model instance from list response item
    public function createModelFromListItem(array $item): Model;
    
    // Get the connector for API requests
    public function getConnector(int $importId): Connector;
}
```

### Traits

```php
trait HasImportStatus
{
    public function importStatus(): MorphOne
    {
        return $this->morphOne(ImportItemStatus::class, 'importable');
    }
    
    public function markImportAsCompleted(): void
    {
        $this->importStatus->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }
    
    public function markImportAsFailed(string $reason): void
    {
        $this->importStatus->update([
            'status' => 'failed',
            'last_failed_at' => now(),
            'failure_reason' => $reason,
            'failure_count' => $this->importStatus->failure_count + 1,
        ]);
    }
}
```

### Generic Jobs

```php
class ImportItemDetailsJob implements ShouldQueue
{
    public function __construct(
        public int $importId,
        public int $importItemStatusId,
        public string $importableType,  // Model class name
    ) {}
    
    public function handle(): void
    {
        $status = ImportItemStatus::find($this->importItemStatusId);
        $model = $status->importable; // Polymorphic relationship
        
        // Model must implement Importable interface
        if (!$model instanceof Importable) {
            throw new Exception("Model must implement Importable");
        }
        
        $import = Import::find($this->importId);
        $connector = $import->source->getConnector($this->importId);
        
        $request = $model->getApiDetailRequest();
        $response = $connector->send($request);
        
        // Let the model populate itself
        $model->populateFromApiResponse($response->json());
        $model->save();
        
        // Update status
        $status->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        
        $import->incrementItemsImportedCount();
    }
    
    public function failed(\Throwable $exception): void
    {
        $status = ImportItemStatus::find($this->importItemStatusId);
        $status->importable->markImportAsFailed($exception->getMessage());
    }
}
```

### Import Command (Generic)

```php
class ImportCommand extends Command
{
    protected $signature = 'import:run 
        {source : The import source class}
        {--fresh : Clear existing data}';
    
    public function handle(): int
    {
        $sourceClass = $this->argument('source');
        $source = new $sourceClass();
        
        if (!$source instanceof ImportSource) {
            $this->error("Source must implement ImportSource");
            return self::FAILURE;
        }
        
        // Create import record
        $import = Import::create([
            'importable_type' => $source->getModelClass(),
            'started_at' => now(),
        ]);
        
        $import->source = $source; // Store for later use
        
        // PHASE 1: Discover items
        $page = 1;
        $itemsToQueue = [];
        
        do {
            $request = $source->getListRequest($page, 10);
            $response = $source->getConnector($import->id)->send($request);
            
            $data = $response->json();
            $items = $data['data'] ?? [];
            
            foreach ($items as $item) {
                // Create the model
                $model = $source->createModelFromListItem($item);
                $model->save();
                
                // Create import status tracking
                $status = ImportItemStatus::create([
                    'import_id' => $import->id,
                    'importable_type' => get_class($model),
                    'importable_id' => $model->id,
                    'external_id' => $model->getExternalId(),
                    'status' => 'pending',
                ]);
                
                $itemsToQueue[] = $status->id;
            }
            
            $import->increment('items_count', count($items));
            
            $hasNextPage = !empty($data['next_page_url']);
            if ($hasNextPage) $page++;
            
        } while ($hasNextPage);
        
        // PHASE 2: Queue detail jobs
        foreach ($itemsToQueue as $statusId) {
            ImportItemDetailsJob::dispatch(
                importId: $import->id,
                importItemStatusId: $statusId,
                importableType: $source->getModelClass()
            );
        }
        
        FinalizeImportJob::dispatch($import->id)->delay(now()->addSeconds(30));
        
        return self::SUCCESS;
    }
}
```

### User Implementation Example

```php
// In user's app
class Item extends Model implements Importable
{
    use HasImportStatus;
    
    public function getExternalId(): string|int
    {
        return $this->external_id ?? $this->id;
    }
    
    public function populateFromApiResponse(array $data): void
    {
        $this->description = $data['description'];
        $this->price = $data['price'];
    }
    
    public function getApiDetailRequest(): Request
    {
        return new GetItemRequest($this->getExternalId());
    }
}

class ItemImportSource implements ImportSource
{
    public function getModelClass(): string
    {
        return Item::class;
    }
    
    public function getListRequest(int $page, int $perPage): Request
    {
        return new GetItemsRequest(page: $page, perPage: $perPage);
    }
    
    public function createModelFromListItem(array $item): Model
    {
        return Item::create([
            'name' => $item['name'],
            'external_id' => $item['id'],
        ]);
    }
    
    public function getConnector(int $importId): Connector
    {
        return new RateTestConnector($importId);
    }
}

// Run import
php artisan import:run "App\ImportSources\ItemImportSource" --fresh
```

## Benefits

1. **Zero model table changes**: All tracking in separate `import_item_statuses` table
2. **Reusable**: Works with any model
3. **Polymorphic**: Can import different model types
4. **Flexible**: Users control how models are populated
5. **Testable**: Each component has a clear contract
6. **Packageable**: Can be extracted to a Composer package

## Package Structure

```
src/
├── Contracts/
│   ├── Importable.php
│   └── ImportSource.php
├── Models/
│   ├── Import.php
│   └── ImportItemStatus.php
├── Jobs/
│   ├── ImportItemDetailsJob.php
│   └── FinalizeImportJob.php
├── Traits/
│   └── HasImportStatus.php
├── Commands/
│   └── ImportCommand.php
└── Filament/
    └── Resources/
        └── ImportResource.php
```

## Migration Path

1. Create new tables (`imports`, `import_item_statuses`) ✅
2. Create interfaces and traits ✅
3. Refactor jobs to be generic ✅
4. Create ImportSource for current Item import ✅
5. Update Item model to implement Importable ✅
6. Test with existing data ✅
7. Remove old `imported_items` table (optional - can coexist)

## Implementation Status

**✅ COMPLETED** - The refactoring is complete and tested!

### What Was Built

1. **Contracts** (`app/Contracts/`)
   - `Importable.php` - Interface for models that can be imported
   - `ImportSource.php` - Interface for import configurations

2. **Traits** (`app/Traits/`)
   - `HasImportStatus.php` - Adds import tracking to any model

3. **Models** (`app/Models/`)
   - `ImportItemStatus.php` - Polymorphic tracking of individual item imports
   - Updated `Import.php` - Added polymorphic support and metadata

4. **Jobs** (`app/Jobs/`)
   - Refactored `ImportItemDetailsJob.php` - Now completely generic
   - `FinalizeImportJob.php` - Works with new architecture (uses `itemStatuses()` relationship)

5. **Import Sources** (`app/ImportSources/`)
   - `ItemImportSource.php` - Implementation for Item model

6. **Database**
   - `import_item_statuses` table - Polymorphic tracking
   - Added `importable_type`, `metadata` to `imports`
   - Added `external_id` to `items`

### Test Results

```
✓ Migrations run successfully
✓ Import discovery phase creates Item models
✓ ImportItemStatus records created (polymorphic)
✓ Job processes successfully:
  - Fetches details from API
  - Populates model via populateFromApiResponse()
  - Updates ImportItemStatus to 'completed'
  - Increments items_imported_count
  
Example: Import #2
  - Discovered 4,110 items
  - Created 4,110 Item records with external_id
  - Created 4,110 ImportItemStatus records
  - Test job completed successfully
  - Item populated with description and price
```

### Benefits Achieved

1. **Zero table modifications** - Items table only needed `external_id` column (optional)
2. **Fully polymorphic** - Can import any model type
3. **Clean separation** - Import logic separated from business logic
4. **Packageable** - All code can be extracted to a package
5. **Testable** - Each component has clear contracts
6. **Flexible** - Users control model population logic

### Next Steps for Package Extraction

1. Move core code to `packages/laravel-rate-limited-imports/src/`
2. Create package service provider
3. Publish migrations, config
4. Add package tests
5. Create documentation
6. Publish to Packagist

### Usage Example (Final)

```php
// 1. Implement Importable on your model
class Product extends Model implements Importable
{
    use HasImportStatus;
    
    public function getExternalId(): string|int
    {
        return $this->external_id;
    }
    
    public function populateFromApiResponse(array $data): void
    {
        $this->description = $data['description'];
        $this->price = $data['price'];
    }
    
    public function getApiDetailRequest(): Request
    {
        return new GetProductRequest($this->getExternalId());
    }
}

// 2. Create an ImportSource
class ProductImportSource implements ImportSource
{
    public function getModelClass(): string
    {
        return Product::class;
    }
    
    public function getListRequest(int $page, int $perPage): Request
    {
        return new GetProductsRequest(page: $page, perPage: $perPage);
    }
    
    public function createModelFromListItem(array $item): Model
    {
        return Product::create([
            'name' => $item['name'],
            'external_id' => $item['id'],
        ]);
    }
    
    public function getConnector(int $importId): Connector
    {
        return new MyApiConnector($importId);
    }
    
    public function hasNextPage(array $responseData): bool
    {
        return !empty($responseData['next_page_url']);
    }
}

// 3. Run import
php artisan import:items
// (In package version, this would be: php artisan import:run ProductImportSource)
```

That's it! No table modifications needed beyond `external_id`.
