# Laravel Rate-Limited Import System

> **A packageable, production-ready solution for importing large datasets from rate-limited APIs**

This repository demonstrates a complete, extractable pattern for building high-performance import systems that respect API rate limits, handle failures gracefully, and provide real-time monitoring. Designed as a reference implementation for teams building similar systems.

## 🎯 Why This Architecture?

This project solves common challenges when importing data from external APIs:

- **Rate limit exhaustion** causing failed jobs and incomplete imports
- **Poor separation** between source data and imported data (feedback loops)
- **No visibility** into import progress and failures
- **Infinite loops** when jobs fail permanently
- **Tightly coupled** code that's hard to reuse across different models

## 🏗️ Key Architectural Decisions

### 1. **Packageable Design with Namespaced Models**

Models are organized into clear namespaces representing their purpose:

```
app/Models/
├── ImportMeta/              # Import system (packageable)
│   ├── Import.php           # Tracks import runs
│   ├── ImportedItem.php     # Your imported data
│   └── ImportedItemStatus.php  # Item-level progress tracking
├── SourceApi/               # API source data (test/demo)
│   └── ApiItem.php          # Data served by the test API
└── Item.php                 # Your application model (optional)
```

**Why this matters:**
- `ImportMeta/*` contains the reusable import framework
- `SourceApi/*` is specific to this demo's test API
- `Item.php` is where you'd add business logic in a real app

### 2. **Separation of Source and Imported Data**

**Critical architectural decision:** Source data and imported data live in separate tables.

```php
// Source: api_items table (what the API serves)
ApiItem::create(['name' => 'Laptop', 'price' => 999.99]);

// Imported: imported_items table (what you import)
ImportedItem::create([
    'external_id' => 123,
    'name' => 'Laptop',
    'price' => 999.99
]);
```

**Why separate tables?**
- Prevents feedback loops (imported data being re-imported during pagination)
- Source data remains pristine for testing
- Clear boundary between "what they have" and "what we imported"
- Supports re-imports without destroying source data

### 3. **Polymorphic Import Tracking (Non-Invasive)**

Import progress is tracked **without modifying your model's table**:

```php
// No columns needed on imported_items table!
// Tracking happens in import_item_statuses (polymorphic)

class ImportedItem extends Model implements Importable
{
    use HasImportStatus; // Polymorphic relationship
}
```

**Benefits:**
- Any model can be importable with zero migration changes
- Supports importing multiple model types simultaneously
- Historical tracking without cluttering your main tables

### 4. **Contract-Based Extensibility**

Two interfaces make the system completely generic:

```php
interface Importable {
    public function getExternalId(): string|int;
    public function populateFromApiResponse(array $data): void;
    public function getApiDetailRequest(): Request;
}

interface ImportSource {
    public function getModelClass(): string;
    public function getListRequest(int $page, int $perPage): Request;
    public function createModelFromListItem(array $item): Model;
    public function getConnector(int $importId): Connector;
    public function hasNextPage(array $responseData): bool;
}
```

**This means:**
- Import jobs work with **any** model implementing `Importable`
- Adding a new import type = implement two interfaces, zero framework changes
- Perfect for extracting to a package

### 5. **Time-Based Failure Detection (Not Attempt Limits)**

Instead of "give up after N attempts", we use:

```php
public function isCompleteIncludingFailed(): bool
{
    $permanentlyFailed = $this->getPermanentlyFailedItemsCount();
    // Items failed >5 minutes ago are considered permanent
    return ($this->items_imported_count + $permanentlyFailed) >= $this->items_count;
}
```

**Why time-based?**
- Adapts to retry backoff schedules automatically
- No hardcoded attempt limits to maintain
- Clear semantic: "if it hasn't succeeded in 5 minutes, it's stuck"

### 6. **Two-Phase Import Pattern**

```
Phase 1: Discovery (Sequential)
┌─────────────────────────────────┐
│ Paginate API                    │
│ Create stub records (name only)│
│ Queue detail jobs               │
└─────────────────────────────────┘
          ↓
Phase 2: Details (Parallel)
┌─────────────────────────────────┐
│ N workers fetch full details    │
│ Respect rate limits (shared)    │
│ Update records with data        │
└─────────────────────────────────┘
```

**Why two phases?**
- Discover all IDs first (prevents pagination issues)
- Parallelize the expensive detail fetches
- Easier to resume/retry failed items

## 🚀 Quick Start

### Installation

```bash
git clone https://github.com/yourusername/laravel-rate-limited-imports.git
cd laravel-rate-limited-imports

composer install
npm install && npm run build

cp .env.example .env
php artisan key:generate
php artisan migrate --seed
```

### Running an Import

**Terminal 1: API Server**
```bash
php artisan serve
```

**Terminal 2: Queue Workers**
```bash
php artisan horizon
```

**Terminal 3: Import Command**
```bash
php artisan import:items --fresh
```

**Terminal 4: Monitor Progress**
```bash
# Visit http://localhost:8000/admin
# Or use tinker:
php artisan tinker
>>> Import::latest()->first()
```

## 📊 How It Works

### Flow Diagram

```
┌──────────────────────────────────────────────────────────────┐
│ ImportItemsCommand                                           │
│                                                              │
│ 1. Create Import record                                     │
│ 2. Page through API (discover items)                        │
│ 3. Create ImportedItem + ImportedItemStatus per item       │
│ 4. Queue ImportItemDetailsJob for each                     │
│ 5. Queue FinalizeImportJob (delayed)                       │
└──────────────────────────────────────────────────────────────┘
                              ↓
┌──────────────────────────────────────────────────────────────┐
│ ImportItemDetailsJob (runs in parallel, 10 workers)         │
│                                                              │
│ For each item:                                               │
│   1. Find ImportedItemStatus by ID                          │
│   2. Load related ImportedItem (polymorphic)                │
│   3. Call item.getApiDetailRequest()                        │
│   4. Send request via Saloon (rate-limited)                 │
│   5. Call item.populateFromApiResponse(data)                │
│   6. Save item + mark status as completed                   │
│   7. Increment import.items_imported_count                  │
│                                                              │
│ On failure:                                                  │
│   - Retry with exponential backoff (5 attempts)             │
│   - Mark status as failed after exhaustion                  │
└──────────────────────────────────────────────────────────────┘
                              ↓
┌──────────────────────────────────────────────────────────────┐
│ FinalizeImportJob (polls every 10s)                         │
│                                                              │
│ Check completion:                                            │
│   ✓ 100% imported → Set ended_at, done                      │
│   ✓ imported + failed(>5min) >= total → Set ended_at       │
│   ✗ Still processing → Re-queue, check in 10s              │
└──────────────────────────────────────────────────────────────┘
```

### Database Schema

**imports** - Tracks each import run
```sql
id, importable_type, started_at, ended_at
items_count, items_imported_count
rate_limit_hits_count, rate_limit_sleeps_count, total_sleep_seconds
metadata (JSON)
```

**import_item_statuses** - Polymorphic tracking (any model)
```sql
id, import_id
importable_type, importable_id  -- Polymorphic
external_id, status
last_failed_at, failure_reason, failure_count
completed_at, metadata (JSON)
```

**imported_items** - The actual imported data
```sql
id, external_id, name, description, price
```

**api_items** - Source data for the test API
```sql
id, name, description, price
```

## 🔧 Implementing for Your Own API

### 1. Create Your Model

```php
namespace App\Models;

use App\Contracts\Importable;
use App\Traits\HasImportStatus;
use Illuminate\Database\Eloquent\Model;
use Saloon\Http\Request;

class Product extends Model implements Importable
{
    use HasImportStatus;
    
    protected $fillable = ['external_id', 'name', 'price', 'sku'];
    
    public function getExternalId(): string|int
    {
        return $this->external_id;
    }
    
    public function populateFromApiResponse(array $data): void
    {
        $this->price = $data['price'] ?? null;
        $this->sku = $data['sku'] ?? null;
    }
    
    public function getApiDetailRequest(): Request
    {
        return new GetProductRequest($this->getExternalId());
    }
}
```

### 2. Create Import Source

```php
namespace App\ImportSources;

use App\Contracts\ImportSource;
use App\Models\Product;

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
            'external_id' => $item['id'],
            'name' => $item['name'],
        ]);
    }
    
    public function getConnector(int $importId): Connector
    {
        return new YourApiConnector($importId);
    }
    
    public function hasNextPage(array $responseData): bool
    {
        return !empty($responseData['next_page_url']);
    }
}
```

### 3. Run the Import

```php
// In your command or controller
$source = new ProductImportSource();
$import = Import::create([
    'importable_type' => $source->getModelClass(),
    'started_at' => now(),
]);

// ... pagination and job queueing logic
// (copy from ImportItemsCommand)
```

**That's it!** The jobs, tracking, failure handling, and finalization all work automatically.

## 📈 Rate Limiting Strategy

### Three-Layer Defense

1. **Client-side proactive throttling** (Saloon + LaravelCacheStore)
   - Shared state across all workers
   - Sleeps **before** hitting limits
   - Tracks sleep time for metrics

2. **Multi-tier server limits** (500/20s, 2000/100s)
   - Short-term burst protection
   - Medium-term sustained rate
   - Laravel middleware: `multi.throttle:500,20:2000,100`

3. **Fallback 429 handling** (coordinated sleep)
   - Atomic lock via cache
   - Only one worker tracks the sleep
   - All workers respect the sleep period

### Configuration

```php
// app/Api/RateTestConnector.php
protected function rateLimitConfig(): RateLimitConfig
{
    return new RateLimitConfig(
        limits: [
            [400, 20],   // Stay under 500/20s burst
            [2100, 100], // Intentionally exceed 2000/100s for demo
        ],
        onSleep: function (int $seconds) {
            // Track sleep metrics on Import record
        },
    );
}
```

## 🎨 Admin Panel (Filament)

Visit `/admin` for real-time monitoring:

- **Import List**: All imports with progress bars and status
- **Import Detail**: Live updating dashboard
  - Large progress bar (items imported / total)
  - Duration (stops growing after completion)
  - Active time vs. sleep time
  - Rate limit efficiency metrics
  - Failed items count

Updates every 1 second via Livewire polling.

## 🧪 Testing

```bash
# Run all tests
php artisan test

# Specific test suites
php artisan test --filter=RateLimitTest
php artisan test --filter=ItemApiTest
php artisan test --filter=SaloonRateLimitHandlingTest
```

**Coverage:**
- API endpoints and pagination
- Multi-tier rate limiting
- Saloon client with shared cache
- Job failure and retry logic
- Import completion detection

## 📁 Project Structure

```
app/
├── Models/
│   ├── ImportMeta/           # Packageable import framework
│   │   ├── Import.php
│   │   ├── ImportedItem.php
│   │   └── ImportedItemStatus.php
│   ├── SourceApi/            # Test API source data
│   │   └── ApiItem.php
│   ├── User.php
│   └── Item.php              # Your app's business model
├── Contracts/                # Framework interfaces
│   ├── Importable.php
│   └── ImportSource.php
├── Traits/
│   └── HasImportStatus.php   # Polymorphic tracking
├── ImportSources/            # Import configurations
│   └── ItemImportSource.php
├── Jobs/
│   ├── ImportItemDetailsJob.php  # Generic detail fetcher
│   └── FinalizeImportJob.php     # Completion detector
├── Console/Commands/
│   └── ImportItemsCommand.php
├── Api/
│   ├── RateTestConnector.php     # Saloon connector
│   ├── RateLimiting/
│   │   └── TrackingRateLimitStore.php
│   └── Requests/
│       ├── GetItemsRequest.php
│       └── GetItemRequest.php
└── Filament/
    └── Resources/
        └── ImportResource.php
```

## 🔑 Key Takeaways for Your Team

1. **Separate source from imported data** to avoid feedback loops
2. **Use polymorphic tracking** to keep models clean
3. **Implement contracts** to make jobs model-agnostic
4. **Detect failures by time**, not attempt count
5. **Two-phase imports** for better parallelization
6. **Shared rate limit state** across workers is crucial
7. **Track metrics** (sleep time, efficiency) for optimization

## 🚢 Extracting to a Package

The `ImportMeta` namespace, contracts, traits, and jobs can be extracted with minimal changes:

```
my-org/laravel-api-importer/
├── src/
│   ├── Models/
│   │   ├── Import.php
│   │   └── ImportedItemStatus.php
│   ├── Contracts/
│   │   ├── Importable.php
│   │   └── ImportSource.php
│   ├── Traits/
│   │   └── HasImportStatus.php
│   ├── Jobs/
│   │   ├── ImportItemDetailsJob.php
│   │   └── FinalizeImportJob.php
│   └── ImporterServiceProvider.php
└── database/migrations/
```

Users would only need to:
1. Implement `Importable` on their models
2. Create an `ImportSource` for their API
3. Run the provided migration
4. Call the import command

## 📊 Performance Tuning

**Concurrent Workers:**
```php
// config/horizon.php
'maxProcesses' => 10, // Increase for more parallelism
```

**Retry Strategy:**
```php
// app/Jobs/ImportItemDetailsJob.php
public $tries = 5;
public $backoff = [30, 60, 120, 240]; // Customize delays
```

**Cache Driver:**
```env
# .env
CACHE_DRIVER=redis  # Use Redis for better rate limit coordination
```

## 🐛 Troubleshooting

**Import never completes?**
- Check Horizon is running: `php artisan horizon:status`
- View failed jobs: `php artisan queue:failed`
- Check completion logic: `Import::find($id)->isCompleteIncludingFailed()`

**Too many 429 errors?**
- Reduce `maxProcesses` in horizon config
- Lower rate limits in connector
- Check `rate_limit_efficiency` metric (should be >90%)

**Jobs failing repeatedly?**
- Check logs: `tail -f storage/logs/laravel.log`
- Verify API credentials
- Test API manually: `curl http://localhost:8000/api/items`

## 📚 Additional Resources

- [Saloon Documentation](https://docs.saloon.dev)
- [Laravel Horizon](https://laravel.com/docs/horizon)
- [Filament Admin Panel](https://filamentphp.com)
- [GLOBAL_RATE_LIMIT_SPEC.md](./GLOBAL_RATE_LIMIT_SPEC.md) - Deep dive on rate limiting

## 📄 License

MIT License - feel free to use this pattern in your projects.

## 🙏 Credits

- **Laravel 12** - Framework
- **Saloon** - API client with first-class rate limiting
- **Filament** - Beautiful admin panel
- **Horizon** - Queue monitoring
- **Pest PHP** - Testing framework

---

**Questions?** Open an issue or discussion on GitHub!
