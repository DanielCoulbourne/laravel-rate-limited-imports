# Laravel Rate-Limited API Import Demo

A comprehensive Laravel application demonstrating best practices for handling high-volume API imports with advanced rate limiting, concurrent queue workers, and intelligent failure tracking.

## Overview

This project showcases a complete solution for importing large datasets from rate-limited APIs while respecting both client-side and server-side constraints. It includes:

- **Test API Server** with multi-tier rate limiting (500/20s, 2000/100s)
- **Saloon API Client** with proactive rate limit handling
- **Queue-Based Import System** supporting concurrent workers
- **Filament Admin Panel** with real-time progress monitoring
- **Intelligent Failure Tracking** at the item level

## Key Features

### 🚀 High-Performance Import System
- Two-phase import: quick discovery + parallel detail fetching
- Concurrent queue worker support via Laravel Horizon
- Smart retry logic with exponential backoff (5 attempts: 30s, 60s, 120s, 240s)
- Item-level failure tracking with automatic completion detection

### 🎯 Advanced Rate Limiting
- **Client-side proactive throttling** using Saloon's rate limiter with shared cache state
- **Server-side multi-tier limits**: 500/20s short-term, 2000/100s medium-term
- **Fallback 429 handling** with coordinated sleep across workers
- Tracks rate limit hits vs. avoided hits for efficiency metrics

### 📊 Real-Time Monitoring
- Filament admin panel with 1-second polling
- Live progress bar showing items imported
- Detailed metrics: duration, active time, sleep time, efficiency
- Per-import failure tracking and reporting

### 🛡️ Intelligent Completion Logic
- Automatically detects when items have permanently failed (>5 min since last retry)
- Considers imports complete when: `imported + permanently_failed >= total`
- No infinite loops - imports finalize gracefully even with failed items

## Quick Start

### Installation

```bash
# Clone the repository
git clone https://github.com/yourusername/laravel-rate-limited-imports.git
cd laravel-rate-limited-imports

# Install dependencies
composer install
npm install && npm run build

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Run migrations and seed database
php artisan migrate --seed
```

### Running the Import System

**Terminal 1: Start the API server**
```bash
php artisan serve
```

**Terminal 2: Start Horizon (queue workers)**
```bash
php artisan horizon
```

**Terminal 3: Run an import**
```bash
# Import all items (uses --fresh to clear previous data)
php artisan import:items --fresh
```

**Terminal 4: Open Filament Admin Panel**
```bash
# Visit http://localhost:8000/admin
# Monitor import progress in real-time
```

## Architecture

### Import Flow

```
┌─────────────────────────────────────────────────────────────┐
│ import:items Command                                        │
│                                                             │
│ 1. Create Import record (started_at)                       │
│ 2. Paginate through /api/items (discover all items)        │
│ 3. Create ImportedItem records with just name             │
│ 4. Dispatch ImportItemDetailsJob for each item            │
│ 5. Dispatch FinalizeImportJob (delayed 30s)               │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ Horizon Queue Workers (concurrent)                          │
│                                                             │
│ ImportItemDetailsJob × 1000s                               │
│ • Fetches full details from /api/items/{id}               │
│ • Updates ImportedItem with description & price           │
│ • Increments items_imported_count                         │
│ • Retries on failure (5 attempts with backoff)            │
│ • Marks as failed after exhausting retries                │
│                                                             │
│ Rate Limiting:                                              │
│ • Saloon sleeps proactively before hitting limits          │
│ • Shared LaravelCacheStore across all workers             │
│ • Coordinated global sleep on unexpected 429s             │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ FinalizeImportJob (polls every 10s)                        │
│                                                             │
│ Completion Logic:                                           │
│ 1. If items_imported = items_count → Complete ✓            │
│ 2. If imported + failed (>5min) >= total → Complete ✓      │
│ 3. Otherwise → Re-queue and check again in 10s             │
│                                                             │
│ Sets ended_at timestamp when complete                      │
└─────────────────────────────────────────────────────────────┘
```

### Database Schema

**imports**
- `id`, `started_at`, `ended_at`
- `items_count`, `items_imported_count`
- `rate_limit_hits_count`, `rate_limit_sleeps_count`, `total_sleep_seconds`
- `finalize_attempts`, `last_finalize_attempt_at`

**imported_items**
- `id`, `import_id`, `name`, `description`, `price`
- `last_failed_at`, `failure_reason`, `failure_count`

## Rate Limiting Strategy

### Client-Side (Saloon with LaravelCacheStore)

```php
$connector = new RateTestConnector($importId);
$connector->send($request);
// Saloon automatically:
// 1. Checks cache for current request count
// 2. Sleeps if approaching limit
// 3. Shares state across ALL queue workers
```

### Server-Side (Multi-Tier)

```php
Route::middleware(['multi.throttle:500,20:2000,100'])
```

- **500/20s**: Short-term burst protection (25 requests/second)
- **2000/100s**: Medium-term throttling (20 requests/second sustained)

### Fallback Handling

When an unexpected 429 occurs:
1. Parse `Retry-After` header
2. Atomically set global sleep lock in cache
3. Only one worker sleeps and tracks the metric
4. Other workers wait without duplicate tracking
5. All workers retry after sleep expires

## Filament Admin Panel

Access at `http://localhost:8000/admin`

### Features
- **Import List**: View all imports with progress bars, status badges
- **Import Detail View**: Real-time stats updating every second
  - Large progress bar at top
  - Items imported count
  - Duration (stops growing after completion)
  - Active vs. sleep time breakdown
  - Rate limit efficiency metrics
  - Failed items tracking

### Metrics Explained

**Rate Limit Efficiency**: `(sleeps / (hits + sleeps)) × 100%`
- **>90%** = Excellent (mostly avoiding 429s)
- **50-90%** = Fair (some 429s hit)
- **<50%** = Poor (frequently hitting 429s)

## API Endpoints

### GET /api/items
Paginated list of items

**Query Parameters:**
- `page` (default: 1)
- `perPage` (default: 10, max: 100)

**Example:**
```bash
curl http://localhost:8000/api/items?page=1&perPage=50
```

### GET /api/items/{id}
Single item details

**Example:**
```bash
curl http://localhost:8000/api/items/123
```

**Rate Limit Headers:**
```
X-RateLimit-Limit: 500
X-RateLimit-Remaining: 495
X-RateLimit-Reset: 1729315200
```

**429 Response:**
```
Status: 429 Too Many Requests
X-RateLimit-Limit: 500
X-RateLimit-Remaining: 0
Retry-After: 15
```
```json
{
  "message": "Too Many Requests",
  "retry_after": 15
}
```

## Commands

### Import Items
```bash
# Import with fresh database
php artisan import:items --fresh

# Monitor progress
php artisan tinker
>>> App\Models\Import::latest()->first()
```

### Database Management
```bash
# Reset and reseed test data
php artisan migrate:fresh --seed

# Check database connection
php artisan db:show
```

## Testing

```bash
# Run all tests
php artisan test

# Run specific test suites
php artisan test --filter=RateLimitTest
php artisan test --filter=ItemApiTest
php artisan test --filter=SaloonRateLimitHandlingTest

# Verbose output
php artisan test --verbose
```

**Test Coverage:**
- API endpoints and pagination
- Multi-tier rate limiting
- Saloon client integration
- Rate limit handling across workers
- Job retry logic
- Import completion detection

## Project Structure

```
app/
├── Api/
│   ├── RateTestConnector.php          # Saloon connector with rate limiting
│   ├── Requests/
│   │   ├── GetItemsRequest.php        # Paginated list endpoint
│   │   └── GetItemRequest.php         # Single item endpoint
│   ├── RateLimiting/
│   │   └── GlobalRateLimitPlugin.php  # Shared rate limit state
│   └── TrackableLimit.php             # Rate limit with tracking
├── Console/Commands/
│   └── ImportItemsCommand.php         # Main import orchestrator
├── Jobs/
│   ├── ImportItemDetailsJob.php       # Fetch individual item details
│   └── FinalizeImportJob.php          # Complete import when done
├── Models/
│   ├── Import.php                     # Import tracking and metrics
│   └── ImportedItem.php               # Imported item with failure tracking
├── Filament/
│   └── Resources/
│       ├── ImportResource.php         # Import admin interface
│       └── ImportResource/Pages/
│           └── ViewImport.php         # Real-time detail view
└── Http/
    ├── Controllers/Api/
    │   └── ItemController.php         # API endpoints
    └── Middleware/
        └── MultiRateLimit.php         # Multi-tier rate limiter
```

## Configuration

### Queue Configuration (config/horizon.php)

```php
'defaults' => [
    'supervisor-1' => [
        'maxProcesses' => 10,  // 10 concurrent workers
        'balanceMaxShift' => 1,
        'balanceCooldown' => 3,
    ],
],
```

### Rate Limit Configuration (app/Api/RateTestConnector.php)

```php
new TrackableLimit(
    importId: $importId,
    key: 'rate-test-global',
    maxAttempts: 20,
    decaySeconds: 10
)
```

## Performance Tips

1. **Adjust Worker Count**: Edit `config/horizon.php` → `maxProcesses`
2. **Tune Retry Delays**: Edit `ImportItemDetailsJob::$backoff`
3. **Adjust Finalize Polling**: Edit `FinalizeImportJob::dispatch()->delay()`
4. **Cache Driver**: Use Redis for better performance (`.env` → `CACHE_DRIVER=redis`)

## Common Issues

### Import Never Completes
- Check Horizon is running: `php artisan horizon:status`
- Check failed jobs: `php artisan queue:failed`
- View import stats: `Import::find($id)`

### Rate Limits Hit Frequently  
- Reduce concurrent workers in `config/horizon.php`
- Increase rate limit in `RateTestConnector.php`
- Check `rate_limit_efficiency` metric in Filament

### Jobs Failing
- Check logs: `tail -f storage/logs/laravel.log`
- Retry failed jobs: `php artisan queue:retry all`
- Check database connection

## Requirements

- PHP 8.2+
- Composer
- Node.js & NPM
- SQLite (default) or MySQL/PostgreSQL
- Redis (optional, for better cache performance)

## License

This project is open-source software licensed under the MIT license.

## Credits

Built with:
- [Laravel 12](https://laravel.com)
- [Saloon](https://docs.saloon.dev) - API client framework
- [Filament](https://filamentphp.com) - Admin panel
- [Laravel Horizon](https://laravel.com/docs/horizon) - Queue monitoring
- [Pest PHP](https://pestphp.com) - Testing framework
