# Laravel Rate-Limited Import System

> **A packageable, production-ready solution for importing large datasets from rate-limited APIs with intelligent scheduling**

This repository demonstrates a complete, extractable pattern for building high-performance import systems that respect API rate limits, handle failures gracefully, provide real-time monitoring, and manage scheduled imports automatically. Designed as a reference implementation for teams building similar systems.

## 🎯 Why This Architecture?

This project solves common challenges when importing data from external APIs:

- **Rate limit exhaustion** causing failed jobs and incomplete imports
- **Poor separation** between source data and imported data (feedback loops)
- **No visibility** into import progress and failures
- **Infinite loops** when jobs fail permanently
- **Tightly coupled** code that's hard to reuse across different models
- **Manual import scheduling** requiring constant intervention
- **Concurrent imports** causing conflicts and rate limit abuse

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

### 5. **Laravel Actions for Maximum Flexibility**

All imports use [Laravel Actions](https://laravelactions.com/) which can be:

```php
// Queued job
ImportItems::dispatch(false, $importId);

// Artisan command
php artisan import:items --fresh

// Direct method call
ImportItems::run(fresh: true);
```

**Benefits:**
- Single codebase serves multiple use cases
- Easy to test without queue infrastructure
- Commands automatically registered from actions
- Queue uniqueness prevents concurrent imports

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

### Running the System

**Terminal 1: API Server**
```bash
php artisan serve
```

**Terminal 2: Queue Workers**
```bash
php artisan horizon
```

**Terminal 3: Laravel Scheduler** (for scheduled imports)
```bash
php artisan schedule:work
```

**Terminal 4: Monitor Progress**
```bash
# Visit http://localhost:8000/admin
# Or use tinker:
php artisan tinker
>>> Import::latest()->first()
```

## 📦 Import Bulk Queueing

The import system uses a **two-phase queuing strategy** to maximize parallelism while respecting rate limits.

### Phase 1: Discovery (Sequential)

```
ImportItems Action
├─ Paginate through API list endpoint (sequential)
├─ Create ImportedItem records (stub with name only)
├─ Create ImportedItemStatus for tracking
└─ Queue ImportItemDetails jobs (one per item)
```

**Why sequential discovery?**
- Prevents pagination issues (all items discovered before processing)
- Captures total item count upfront for progress tracking
- Minimal API calls during discovery (only list endpoint)

### Phase 2: Details (Parallel)

```
ImportItemDetails Jobs (parallel, 10 workers)
├─ Fetch full details from API
├─ Respect shared rate limits
├─ Update item with full data
└─ Mark status as completed
```

**Why parallel processing?**
- Maximizes throughput (10 workers can process 10 items simultaneously)
- Detail endpoint is called in parallel, not discovery
- Each worker independently respects shared rate limits

### Rate Limit Handling

**Three-layer defense system:**

#### 1. Client-Side Proactive Throttling (Saloon + LaravelCacheStore)

```php
// app/Api/RateTestConnector.php
protected function rateLimitConfig(): RateLimitConfig
{
    return new RateLimitConfig(
        limits: [
            [400, 20],   // Stay under 500/20s burst
            [2100, 100], // Intentionally exceed 2000/100s for testing
        ],
        onSleep: function (int $seconds) {
            // Track sleep time for metrics
        },
    );
}
```

**How it works:**
- Saloon tracks request count in **shared cache** (LaravelCacheStore)
- All queue workers read from same cache state
- Before each request: check if approaching limit
- If approaching: sleep proactively to avoid 429
- **Result**: Rarely hit actual 429 responses

**Benefits:**
- Shared state across all workers prevents race conditions
- Proactive sleeping is more efficient than reactive retries
- Tracks "avoided" rate limits vs "hit" rate limits for metrics

#### 2. Server-Side Multi-Tier Limits (Laravel Middleware)

```php
Route::middleware(['multi.throttle:500,20:2000,100'])
```

- **500/20s**: Short-term burst protection (25 requests/second)
- **2000/100s**: Medium-term sustained rate (20 requests/second)

**Why multi-tier?**
- Prevents burst abuse while allowing sustained throughput
- Short window catches spikes, long window enforces average
- Mirrors real-world API rate limit structures

#### 3. Fallback 429 Handling (Coordinated Sleep)

```php
// ImportItemDetails action
while ($response->status() === 429) {
    $retryAfter = (int) ($response->header('Retry-After') ?? 60);
    
    // Atomic lock - only ONE worker tracks the sleep
    if (Cache::add('rate_limit:sleep_until', $now + $retryAfter, $retryAfter)) {
        $import->incrementRateLimitSleeps($retryAfter);
        sleep($retryAfter);
    } else {
        // Other workers just wait without duplicate tracking
        sleep($existingSleepUntil - $now);
    }
    
    $response = $connector->send($request);
}
```

**How it works:**
- If 429 received (rare with proactive throttling)
- Use `Cache::add()` for atomic lock (SETNX)
- Only the worker that acquires lock tracks the sleep
- Other workers wait without duplicate metric tracking
- All workers respect the sleep period

**Benefits:**
- Accurate sleep metrics (no double-counting)
- Coordinated response across all workers
- Automatic retry after sleep

### Queue Uniqueness

```php
// ImportItems action
public function uniqueId(): string
{
    return 'import-items';
}
```

**Result:** Only one import can run at a time, preventing:
- Concurrent API abuse
- Rate limit exhaustion
- Resource contention
- Duplicate imports

## 📊 Import Monitoring

Real-time visibility into import progress through polymorphic tracking and Filament admin panel.

### Tracking Architecture

**Import Model** (`imports` table)
- Tracks the import run itself
- Metrics: items_count, items_imported_count, duration
- Rate limit metrics: hits, sleeps, total_sleep_seconds
- Status: scheduled, running, completed, cancelled

**ImportedItemStatus Model** (`import_item_statuses` table - polymorphic)
- Tracks individual item progress
- Polymorphic: works with any model via `importable_type` + `importable_id`
- Status: pending, processing, completed, failed
- Failure tracking: last_failed_at, failure_reason, failure_count

**Benefits:**
- Zero modifications to your model's table
- Historical tracking (never loses import data)
- Supports multiple model types simultaneously

### Progress Reporting

**Item-level tracking:**

```php
// ImportItemDetails action
public function handle(int $importItemStatusId, int $importId): void
{
    $status = ImportedItemStatus::find($importItemStatusId);
    $model = $status->importable;
    
    $status->markAsProcessing();
    
    // Fetch and populate
    $model->populateFromApiResponse($response->json());
    $model->save();
    
    $status->markAsCompleted();
    $import->incrementItemsImportedCount(); // Atomic increment
}
```

**Import-level aggregation:**

```php
// Import model
public function getProgressPercentage(): float
{
    return round(($this->items_imported_count / $this->items_count) * 100, 2);
}

public function isCompleteIncludingFailed(): bool
{
    $permanentlyFailed = $this->getPermanentlyFailedItemsCount();
    return ($this->items_imported_count + $permanentlyFailed) >= $this->items_count;
}
```

**Time-based failure detection:**
- Items failed >5 minutes ago = permanently failed
- Adapts to exponential backoff (30s, 60s, 120s, 240s)
- Import completes when: `imported + permanently_failed >= total`

### Filament Admin Panel

**List View** (`/admin/imports`)

Real-time table with 1-second polling:

```php
Tables\Columns\TextColumn::make('status')
    ->badge()
    ->icon(fn (Import $record) => match(true) {
        $record->isScheduled() => 'heroicon-o-calendar',
        $record->isRunning() => 'heroicon-o-clock',
        $record->isComplete() => 'heroicon-o-check-circle',
        $record->isOverdue() => 'heroicon-o-exclamation-triangle',
        $record->isCancelled() => 'heroicon-o-x-circle',
    })
```

**Columns displayed:**
- **Status Badge**: Scheduled (📅), Running (🕐), Completed (✅), Overdue (⚠️), Cancelled (❌)
- **Scheduled For**: When the import is scheduled to run
- **Items**: Total items discovered
- **Progress Bar**: Live percentage with item counts
- **Duration**: Active time vs sleep time breakdown
- **Rate Limits**: Hits/Sleeps ratio showing efficiency

**Detail View** (`/admin/imports/{id}`)

Large progress bar at top:
```
┌─────────────────────────────────────────────────────────┐
│ ████████████████████░░░░░░░░░░░░░░░░  68% (272/400)   │
└─────────────────────────────────────────────────────────┘
```

Live-updating metrics:
- Items imported / total
- Duration (stops growing after completion)
- Active importing time (excludes sleep)
- Rate limit efficiency: `(sleeps / (hits + sleeps)) × 100%`
  - >90% = Excellent (avoiding most 429s)
  - 50-90% = Fair (some 429s)
  - <50% = Poor (frequently hitting 429s)

**Polling implementation:**

```blade
<div wire:poll.1s="refreshRecord">
    {{-- Live-updating content --}}
</div>
```

```php
// ViewImport page
public function refreshRecord(): void
{
    $this->record = $this->record->fresh();
}
```

**Benefits:**
- No manual refresh needed
- See progress in real-time
- Duration stops growing after completion
- Clear visibility into rate limit effectiveness

## 📅 Import Scheduling

Automated scheduling system maintains a continuous import pipeline.

### Scheduling Configuration

**Default Schedule:** Imports run every 3 hours

```
0:00  →  3:00  →  6:00  →  9:00  →  12:00  →  15:00  →  18:00  →  21:00  →  0:00
```

Configured in:
```php
// Import model
public static function getNextScheduledTime(): \Carbon\Carbon
{
    $schedule = [0, 3, 6, 9, 12, 15, 18, 21];
    // Find next slot after current hour
}
```

**Customize for your needs:**
- Hourly: `[0, 1, 2, 3, ..., 23]`
- Every 6 hours: `[0, 6, 12, 18]`
- Business hours only: `[9, 12, 15, 18]`

### Scheduling Workflow

**1. Laravel Scheduler** (runs every minute)

```php
// routes/console.php
Schedule::call(function () {
    StartScheduledImport::dispatch();
})->everyMinute();
```

**2. StartScheduledImport Action** (orchestrates logic)

```php
public function handle(): int
{
    $overdueImports = Import::getOverdueImports();
    
    if ($overdueImports->isEmpty()) {
        // Ensure future import exists
        if (!Import::hasFutureScheduledImport()) {
            ScheduleImport::run();
        }
        return;
    }
    
    // Multiple overdue? Cancel all but latest
    if ($overdueImports->count() > 1) {
        foreach ($overdueImports->skip(1) as $import) {
            $import->markAsCancelled();
        }
    }
    
    // Start the latest overdue import
    $latestOverdue = $overdueImports->first();
    ImportItems::dispatch(false, $latestOverdue->id);
}
```

**3. ImportItems Action** (ensures continuity)

```php
public function handle(bool $fresh = false, ?int $importId = null): int
{
    // ... run import ...
    
    // After queueing jobs, schedule next import if none exists
    if (!Import::hasFutureScheduledImport()) {
        ScheduleImport::dispatch();
    }
}
```

### Scheduling Rules

**Rule 1: Always maintain a future scheduled import**
- After every import starts, check if future import exists
- If not, create one for the next time slot
- **Result**: Pipeline never runs dry

**Rule 2: Start latest overdue import**
- Scheduler checks every minute for overdue imports
- If found, dispatch immediately
- **Result**: Imports start within 1 minute of scheduled time

**Rule 3: Cancel old overdue imports**
- If multiple imports are overdue (e.g., system was down)
- Cancel all except the most recent
- Start only the latest
- **Result**: No redundant work, always process freshest data

**Rule 4: Only one import runs at a time**
- `ImportItems` action has unique queue ID
- If import already running, new one waits in queue
- **Result**: Prevents concurrent API abuse

### Import Statuses

```php
// Import model
public function isScheduled(): bool  // scheduled_at is future
public function isOverdue(): bool    // scheduled_at is past, not started
public function isRunning(): bool    // started but not ended
public function isComplete(): bool   // ended successfully
public function isCancelled(): bool  // marked as cancelled
```

**Status Flow:**

```
     CREATE                    SCHEDULER              COMPLETE
scheduled  ──────>  overdue  ────────>  running  ──────>  completed
    │                  │
    │                  └──────> cancelled (if not latest)
    │
    └──────> (user action) ──────> running (reschedule)
```

### Manual Import Control (Filament)

**"Run Import" Button** in Filament

Modal with two options:

**Option 1: NEW import**
```
┌─────────────────────────────────────────┐
│ Schedule a NEW import for right now     │
│ (outside normal schedule)               │
└─────────────────────────────────────────┘
```
- Creates brand new import
- Runs immediately
- Does NOT affect scheduled imports
- **Use when**: Need extra import between scheduled runs

**Option 2: RESCHEDULE next import**
```
┌─────────────────────────────────────────┐
│ RESCHEDULE the next import for right    │
│ now (keep future schedule intact)       │
└─────────────────────────────────────────┘
```
- Finds next future scheduled import
- Updates `scheduled_at` to `now()`
- Starts immediately
- Future imports remain scheduled
- **Use when**: Want to pull scheduled import earlier

**Implementation:**

```php
Actions\Action::make('run_import')
    ->form([
        Radio::make('import_type')
            ->options([
                'new' => 'NEW import (outside schedule)',
                'reschedule' => 'RESCHEDULE next import',
            ])
    ])
    ->action(function (array $data) {
        if ($data['import_type'] === 'new') {
            ImportItems::dispatch(false);
        } else {
            $next = Import::where('status', 'scheduled')
                ->where('scheduled_at', '>', now())
                ->orderBy('scheduled_at', 'asc')
                ->first();
                
            $next->update(['scheduled_at' => now()]);
            ImportItems::dispatch(false, $next->id);
        }
    })
```

### Scheduling Commands

```bash
# Create next scheduled import
php artisan import:schedule

# Check and start overdue imports (scheduler runs this)
php artisan import:start-scheduled

# Run import immediately (bypasses schedule)
php artisan import:items --fresh
```

### Scheduling Benefits

**Automation**
- Set it and forget it
- Imports run automatically
- No manual intervention needed

**Reliability**
- Catches up after downtime
- Cancels stale imports
- Always maintains pipeline

**Flexibility**
- Easy to adjust schedule
- Manual override available
- Reschedule without disruption

**Visibility**
- See scheduled imports in Filament
- Know when next import runs
- Track cancelled vs completed

## 📁 Project Structure

```
app/
├── Actions/                      # Laravel Actions (jobs + commands)
│   ├── ImportItems.php          # Main import orchestrator
│   ├── ImportItemDetails.php   # Fetch individual item details
│   ├── FinalizeImport.php       # Mark import as complete
│   ├── ScheduleImport.php       # Create next scheduled import
│   └── StartScheduledImport.php # Handle scheduler logic
├── Models/
│   ├── ImportMeta/              # Packageable import framework
│   │   ├── Import.php
│   │   ├── ImportedItem.php
│   │   └── ImportedItemStatus.php
│   ├── SourceApi/               # Test API source data
│   │   └── ApiItem.php
│   ├── User.php
│   └── Item.php                 # Your app's business model
├── Contracts/                   # Framework interfaces
│   ├── Importable.php
│   └── ImportSource.php
├── Traits/
│   └── HasImportStatus.php     # Polymorphic tracking
├── ImportSources/               # Import configurations
│   └── ItemImportSource.php
├── Api/
│   ├── RateTestConnector.php   # Saloon connector
│   ├── RateLimiting/
│   │   └── TrackingRateLimitStore.php
│   └── Requests/
│       ├── GetItemsRequest.php
│       └── GetItemRequest.php
└── Filament/
    └── Resources/
        └── ImportResource.php
```

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
- Scheduled import workflow

## 🔑 Key Takeaways for Your Team

**Import Design**
1. **Two-phase imports**: Discovery sequential, details parallel
2. **Polymorphic tracking**: Keep models clean, track externally
3. **Contract-based**: Make jobs model-agnostic
4. **Separate source from imported**: Avoid feedback loops

**Rate Limiting**
5. **Shared cache state**: Critical for multi-worker coordination
6. **Proactive throttling**: Sleep before hitting limits
7. **Multi-tier protection**: Short bursts, long sustained
8. **Coordinated 429 handling**: Atomic locks prevent double-tracking

**Monitoring**
9. **Time-based failure detection**: Adapt to retry schedules
10. **Real-time progress**: Atomic increments + polling
11. **Efficiency metrics**: Track avoided vs hit rate limits

**Scheduling**
12. **Always maintain pipeline**: Future import always exists
13. **Cancel stale imports**: Only run latest when overdue
14. **Queue uniqueness**: Prevent concurrent conflicts
15. **Manual override**: Allow ad-hoc imports when needed

## 🚢 Extracting to a Package

The `ImportMeta` namespace, contracts, traits, and actions can be extracted with minimal changes:

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
│   ├── Actions/
│   │   ├── ImportItems.php
│   │   ├── ImportItemDetails.php
│   │   ├── FinalizeImport.php
│   │   ├── ScheduleImport.php
│   │   └── StartScheduledImport.php
│   └── ImporterServiceProvider.php
└── database/migrations/
```

Users would only need to:
1. Implement `Importable` on their models
2. Create an `ImportSource` for their API
3. Run the provided migrations
4. Configure scheduler in `routes/console.php`
5. Add Filament resource (optional)

## 📊 Performance Tuning

**Concurrent Workers:**
```php
// config/horizon.php
'maxProcesses' => 10, // Increase for more parallelism
```

**Retry Strategy:**
```php
// Actions/ImportItemDetails.php
public int $tries = 5;
public array $backoff = [30, 60, 120, 240]; // Customize delays
```

**Cache Driver:**
```env
# .env - Use Redis for better rate limit coordination
CACHE_DRIVER=redis
```

**Schedule Frequency:**
```php
// Import model
public static function getNextScheduledTime(): \Carbon\Carbon
{
    $schedule = [0, 6, 12, 18]; // Every 6 hours instead of 3
}
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

**Scheduled imports not running?**
- Verify scheduler is running: `php artisan schedule:work`
- Check for overdue imports: `Import::getOverdueImports()`
- Manually trigger: `php artisan import:start-scheduled`

**Multiple imports running concurrently?**
- Verify queue uniqueness: Check `ImportItems::uniqueId()`
- Clear failed unique jobs: `php artisan queue:flush`
- Restart Horizon: `php artisan horizon:terminate`

## 📚 Additional Resources

- [Saloon Documentation](https://docs.saloon.dev)
- [Laravel Actions](https://laravelactions.com)
- [Laravel Horizon](https://laravel.com/docs/horizon)
- [Filament Admin Panel](https://filamentphp.com)
- [GLOBAL_RATE_LIMIT_SPEC.md](./GLOBAL_RATE_LIMIT_SPEC.md) - Deep dive on rate limiting

## 📄 License

MIT License - feel free to use this pattern in your projects.

## 🙏 Credits

- **Laravel 12** - Framework
- **Saloon** - API client with first-class rate limiting
- **Laravel Actions** - Jobs + Commands unified
- **Filament** - Beautiful admin panel
- **Horizon** - Queue monitoring
- **Pest PHP** - Testing framework

---

**Questions?** Open an issue or discussion on GitHub!
