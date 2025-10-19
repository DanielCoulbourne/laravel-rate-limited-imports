# Global Rate Limit Sleep Specification

## Project Context & Goals

### What We're Building
A production-ready Laravel command that imports **thousands of items** from a rate-limited API using **concurrent queue workers**. This is a common real-world scenario:
- Importing products from Shopify, WooCommerce, or other e-commerce APIs
- Syncing data from SaaS platforms (Stripe, GitHub, etc.)
- Bulk data migrations with API constraints

### Key Requirements
1. **Speed**: Use multiple concurrent workers (2+) to maximize throughput
2. **Reliability**: Never fail due to rate limits - handle them gracefully
3. **Accuracy**: Track exactly how long we spent waiting vs. actively working
4. **Visibility**: Show real-time progress in Filament dashboard
5. **Reusability**: Extract into a package we can use on our other projects

### The Challenge
When multiple workers process jobs concurrently, they all share the same API rate limits:
- API allows 10 requests per 10 seconds
- Worker 1 makes 5 requests
- Worker 2 makes 5 requests  
- Worker 3 tries to make a request → **429 Too Many Requests**

**We need all workers to coordinate and "sleep together" when limits are hit.**

### Why This Matters
- **For developers**: Accurate metrics help optimize import speed
- **For users**: Dashboard shows realistic progress and ETA
- **For infrastructure**: Prevents hammering APIs and getting banned
- **For the ecosystem**: Laravel lacks a good multi-worker rate limit solution

### Package Extraction Goals
This will become a standalone package, so:
- **Isolate rate limit logic** - All in a single trait or dedicated classes
- **Don't rely on Saloon's internals** - Build our own coordination layer
- **Make it configurable** - Support different storage backends (Redis, DB, etc.)
- **Document clearly** - Others should understand and extend it

## Current Problem Statement

Currently, when multiple workers hit rate limits, they each sleep independently:
- Worker 1 sleeps 10s
- Worker 2 sleeps 10s  
- Worker 3 sleeps 10s
- **Total tracked: 30s** (but they're all sleeping simultaneously)

This results in:
- `total_sleep_seconds` being much higher than actual elapsed time
- `active_time` calculation being completely wrong (often showing 0 or negative)

## Desired Behavior

**The API should have a global "sleep state" that ALL workers respect.**

### Rule: Binary Sleep State
- Either the API is "sleeping" or it is "not sleeping"
- When sleeping, NO requests from ANY worker can go through
- Sleep time should be tracked ONCE, not per-worker

### Example 1: Simple Case
```
Time 0s:  Worker 1 makes request #11 (limit is 10/10s)
          → Triggers sleep until time 10s
          → Track: 10 seconds of sleep
          → Redis: Set "rate_limit:sleep_until" = timestamp(10s)

Time 1s:  Worker 2 tries to make a request
          → Checks Redis: sleep_until = timestamp(10s)
          → Waits 9 more seconds
          → No additional sleep tracked

Time 5s:  Worker 3 tries to make a request
          → Checks Redis: sleep_until = timestamp(10s)  
          → Waits 5 more seconds
          → No additional sleep tracked

Time 10s: All workers can now make requests
```

**Result:** Only 10 seconds tracked, all workers slept in sync

### Example 2: Overlapping Sleeps
```
Time 0s:  Worker 1 triggers 10s sleep (until time 10s)
          → Track: 10 seconds
          → Redis: sleep_until = timestamp(10s)

Time 5s:  Worker 2 (would trigger 10s sleep until time 15s)
          → Checks existing sleep_until = timestamp(10s)
          → New sleep would end at timestamp(15s) 
          → Update Redis: sleep_until = timestamp(15s)
          → Track: additional 5 seconds (15 - 10)
          
Time 15s: All workers can make requests
```

**Result:** 15 seconds total tracked (10 + 5 extension)

## Expected Metrics

For a 20,000 item import with:
- Client limits: 10 req/10s, 200 req/min
- 2 concurrent workers

**Reasonable expectations:**
- Total sleep time ≈ actual time spent waiting for rate limits
- Active time = elapsed_time - total_sleep_seconds (should be positive)
- Sleep count ≈ number of distinct sleep periods (not per-worker)

**Example good result:**
- Elapsed: 5 minutes (300s)
- Sleep time: 2 minutes (120s)
- Active time: 3 minutes (180s)
- Sleep count: ~12-15 distinct sleeps

**Example bad result (current behavior):**
- Elapsed: 1 minute (60s)
- Sleep time: 20 minutes (1200s) ❌ IMPOSSIBLE
- Active time: -19 minutes ❌ NONSENSE

## Implementation Approach

### Architecture: Custom Rate Limit Layer

**We will NOT rely on Saloon's built-in rate limiting.** Instead, we'll build our own coordination layer that:
1. Works with any HTTP client (Saloon, Guzzle, etc.)
2. Coordinates across multiple workers via Redis
3. Can be extracted into a standalone package

### Core Components

#### 1. **`GlobalRateLimiter` Trait** (Package-ready)
A trait that connectors can use to add global rate limiting:

```php
trait HasGlobalRateLimiting
{
    protected function checkGlobalRateLimits(): void;
    protected function recordRequest(): void;
    protected function shouldSleep(): bool;
    protected function globalSleep(int $seconds): void;
    protected function trackSleep(int $seconds): void;
}
```

#### 2. **`RateLimitStore` Interface** (Package-ready)
Abstraction for storage backend:

```php
interface RateLimitStore
{
    public function increment(string $key, int $ttl): int;
    public function getSleepUntil(): ?int;
    public function setSleepUntil(int $timestamp, int $ttl): bool;
    public function shouldRequestSleep(): bool;
}
```

Implementations:
- `RedisRateLimitStore` - For production (shared state)
- `MemoryRateLimitStore` - For testing (single process)

#### 3. **`RateLimitConfig` Class** (Package-ready)
Configuration object:

```php
class RateLimitConfig
{
    public function __construct(
        public array $limits,           // [[10, 10], [200, 60]]
        public ?callable $onSleep = null,
        public ?callable $onHit = null,
    ) {}
}
```

### Request Flow

```
[Worker 1 sends request]
    ↓
[Check global sleep lock in Redis]
    ↓ (if sleeping)
[Sleep until lock expires] → Track: NO (already tracked)
    ↓ (if not sleeping)
[Check request counters in Redis]
    ↓ (if limit exceeded)
[Set global sleep lock] → Track: YES (first to set it)
[Sleep for X seconds]
    ↓ (if under limit)
[Increment request counter]
[Send request]
    ↓ (if 429 response)
[Set global sleep lock based on Retry-After] → Track: YES
[Sleep and retry]
```

### File Structure (Package-ready)

```
app/Api/
├── RateLimit/
│   ├── HasGlobalRateLimiting.php      # Main trait
│   ├── RateLimitStore.php             # Interface
│   ├── RedisRateLimitStore.php        # Redis implementation
│   ├── MemoryRateLimitStore.php       # Testing implementation
│   └── RateLimitConfig.php            # Configuration object
└── RateTestConnector.php              # Uses the trait
```

### Implementation in Connector

```php
class RateTestConnector extends Connector
{
    use HasGlobalRateLimiting;  // Our custom trait
    // NOT using HasRateLimits from Saloon
    
    protected function rateLimitConfig(): RateLimitConfig
    {
        return new RateLimitConfig(
            limits: [[10, 10], [200, 60]],
            onSleep: fn($seconds) => $this->trackSleep($seconds),
            onHit: fn() => $this->trackHit(),
        );
    }
}
```

### Benefits of This Approach

1. **Package-ready**: All logic isolated in `RateLimit/` directory
2. **Framework agnostic**: Works with any HTTP client
3. **Testable**: Swap Redis for Memory store in tests
4. **Extensible**: Easy to add database, Memcached, etc.
5. **Clear separation**: Doesn't fight with Saloon's internals

### Files to Create

1. **`app/Api/RateLimit/HasGlobalRateLimiting.php`** - Main trait with all logic
2. **`app/Api/RateLimit/RateLimitStore.php`** - Interface
3. **`app/Api/RateLimit/RedisRateLimitStore.php`** - Redis implementation
4. **`app/Api/RateLimit/RateLimitConfig.php`** - Config object

### Files to Modify

1. **`app/Api/RateTestConnector.php`**
   - Remove `use HasRateLimits` from Saloon
   - Add `use HasGlobalRateLimiting`
   - Remove `resolveLimits()` and related methods
   - Implement `rateLimitConfig()`

2. **`app/Jobs/ImportItemDetailsJob.php`**
   - Keep the while loop for 429s
   - Use trait's methods to handle sleep/tracking

## Testing Strategy

### Phase 0: Research Saloon's Extension Points
**BEFORE writing any code:**

1. **Read Saloon docs**
   ```bash
   # Online docs
   open https://docs.saloon.dev/
   ```

2. **Examine Saloon's Connector class**
   ```bash
   cat vendor/saloonphp/saloon/src/Http/Connector.php | grep "function boot\|function send\|function middleware"
   ```

3. **Study existing rate limit plugin**
   ```bash
   # See how HasRateLimits trait works
   cat vendor/saloonphp/rate-limit-plugin/src/Traits/HasRateLimits.php
   
   # Find the boot method
   grep -n "boot" vendor/saloonphp/rate-limit-plugin/src/Traits/HasRateLimits.php
   ```

4. **Look at how other plugins hook in**
   ```bash
   ls vendor/saloonphp/saloon/src/Traits/Plugins/
   cat vendor/saloonphp/saloon/src/Traits/Plugins/AcceptsJson.php
   ```

5. **Document your findings in a comment** at top of `HasGlobalRateLimiting.php`

### Phase 1: Build Custom Rate Limit Classes
1. Create `RateLimitStore` interface
2. Create `RedisRateLimitStore` implementation
3. Create `RateLimitConfig` class
4. Create `HasGlobalRateLimiting` trait with core logic (AFTER researching Saloon)
5. Clear caches: `php artisan cache:clear && php artisan config:clear`

### Phase 2: Small Test (100 items)
```bash
php artisan import:items --fresh
# Wait 30 seconds
sqlite3 database/database.sqlite "SELECT 
  items_count, 
  items_imported_count,
  rate_limit_sleeps_count,
  total_sleep_seconds,
  strftime('%s', 'now') - strftime('%s', started_at) as elapsed
FROM imports ORDER BY id DESC LIMIT 1;"
```

**Success criteria:**
- `total_sleep_seconds` < `elapsed` ✅
- Active time = elapsed - sleep > 0 ✅
- Numbers make sense

**If failed:**
- Check logs for errors
- Verify global lock is being set in Redis
- Check if multiple workers are tracking same sleep

### Phase 3: Verify Redis Lock
```bash
# While import is running
redis-cli GET rate_limit:sleep_until
# Should show a timestamp in the future when sleeping

redis-cli MONITOR | grep sleep_until
# Should show SET operations when limits hit
```

### Phase 4: Full Test (20k items)
```bash
nohup php artisan import:items --fresh > /tmp/import.log 2>&1 &
# Monitor in Filament dashboard at http://rate-test.test/admin
# Watch: Sleep/Active Time column should show reasonable numbers
```

**Success criteria:**
- Import completes all 20,000 items
- Sleep time < elapsed time
- Active time is positive
- Filament dashboard shows accurate metrics

## Recursive Testing Loop

### When First Starting:
0. **Research Saloon** - Read docs, examine source code, understand extension points
1. **Document findings** - Write comment explaining your approach and why
2. **Make code change**
3. Continue to normal loop...

### Normal Loop:
1. **Make code change**
2. **Clear caches:** `php artisan cache:clear && php artisan config:clear`
3. **Kill old imports:** `pkill -f "import:items"`
4. **Run small test:** 30 seconds with limited items
5. **Check metrics:** Sleep vs elapsed time
6. **If broken:**
   - Check error logs (`tail -50 /tmp/import.log`)
   - Verify Redis operations (`redis-cli MONITOR | grep rate_limit`)
   - Check if hooking into Saloon correctly (add debug logs)
   - Identify issue
   - **Re-research Saloon if needed** (maybe you misunderstood the lifecycle)
   - GO TO STEP 1
7. **If working:**
   - Run full test
   - Monitor in Filament
   - Declare victory ✅

## Current Issues to Fix

1. ❌ Using Saloon's `HasRateLimits` trait - causes per-worker sleep tracking
2. ❌ Sleep tracking happens per-worker instead of globally  
3. ❌ No shared coordination between workers
4. ❌ Code mixed with Saloon's internals - not package-ready

## Implementation Steps

### Step 1: Create the Store Interface
Create `app/Api/RateLimit/RateLimitStore.php`:
- `increment(string $key, int $ttl): int` - Increment request counter
- `get(string $key): ?int` - Get value
- `getSleepUntil(): ?int` - Get global sleep timestamp
- `setSleepUntil(int $timestamp, int $ttl): bool` - Set global sleep lock

### Step 2: Create Redis Implementation  
Create `app/Api/RateLimit/RedisRateLimitStore.php`:
- Use Laravel's Cache facade
- Prefix all keys with `rate_limit:`
- Use atomic operations (INCR, GET, SET)

### Step 3: Create Config Class
Create `app/Api/RateLimit/RateLimitConfig.php`:
- Store limits as array of [requests, seconds]
- Accept optional callbacks for tracking

### Step 4: Create the Main Trait
Create `app/Api/RateLimit/HasGlobalRateLimiting.php`:

**IMPORTANT - Research Saloon First:**
Before writing any code, you MUST research how to properly hook into Saloon:

1. **Check Saloon documentation** at https://docs.saloon.dev/
   - Look for: middleware, lifecycle hooks, request/response interceptors
   - Look for: how plugins/traits are intended to work
   - Look for: existing rate limiting documentation

2. **Source-dive the Saloon codebase** in `vendor/saloonphp/saloon/src/`
   - Read `Http/Connector.php` - what methods can we override?
   - Read `Http/PendingRequest.php` - what hooks are available?
   - Look at `Traits/Plugins/` - how do other plugins hook in?
   - Check if there's a `boot()` method or similar lifecycle hook

3. **Study Saloon's existing rate limit plugin** in `vendor/saloonphp/rate-limit-plugin/src/`
   - How does `HasRateLimits` trait work?
   - What methods does it override?
   - How does it intercept requests?
   - Can we extend/wrap it instead of replacing it?

**Guiding principles:**
- ✅ Use Saloon's intended extension points (middleware, boot methods, etc.)
- ✅ Follow patterns from official plugins
- ✅ Override as little as possible
- ❌ Don't hack around Saloon's internals
- ❌ Don't duplicate what Saloon already does well

**After research, document your findings:**
Create a comment block at the top of the trait explaining:
- What Saloon extension point you're using
- Why you chose this approach
- What alternatives you considered
- Any Saloon version compatibility notes

**How it hooks into Saloon:**
Based on initial research (VERIFY THIS):
- Saloon calls `boot(PendingRequest $request)` on connectors before sending
- We add middleware using `$request->middleware()->onRequest()` for BEFORE logic
- We add middleware using `$request->middleware()->onResponse()` for AFTER logic

**Core logic flow:**

```php
trait HasGlobalRateLimiting
{
    protected RateLimitStore $rateLimitStore;
    
    // Called by Saloon before every request
    public function boot(PendingRequest $pendingRequest): void
    {
        $this->rateLimitStore = $this->resolveRateLimitStore();
        
        // BEFORE request: Check global sleep + limits
        $pendingRequest->middleware()->onRequest(function (PendingRequest $request) {
            // 1. Check if globally sleeping
            $sleepUntil = $this->rateLimitStore->getSleepUntil();
            if ($sleepUntil && $sleepUntil > time()) {
                $sleepSeconds = $sleepUntil - time();
                sleep($sleepSeconds); // Block this worker
                // Don't track - already tracked by worker who set the lock
            }
            
            // 2. Check if any limit would be exceeded
            foreach ($this->rateLimitConfig()->limits as [$requests, $seconds]) {
                $key = "limit:{$requests}:{$seconds}";
                $count = $this->rateLimitStore->get($key) ?? 0;
                
                if ($count >= $requests) {
                    // Would exceed limit - set global sleep
                    $sleepUntil = time() + $seconds;
                    $this->rateLimitStore->setSleepUntil($sleepUntil, $seconds);
                    
                    // Track this sleep (we're the first to set it)
                    if ($this->rateLimitConfig()->onSleep) {
                        ($this->rateLimitConfig()->onSleep)($seconds);
                    }
                    
                    sleep($seconds); // Block this worker
                }
            }
            
            // 3. Increment counters (will make request now)
            foreach ($this->rateLimitConfig()->limits as [$requests, $seconds]) {
                $key = "limit:{$requests}:{$seconds}";
                $this->rateLimitStore->increment($key, $seconds);
            }
            
            return $request;
        });
        
        // AFTER response: No action needed for now
        // (Could add response-based rate limiting here)
    }
    
    protected function resolveRateLimitStore(): RateLimitStore
    {
        return new RedisRateLimitStore();
    }
    
    abstract protected function rateLimitConfig(): RateLimitConfig;
}
```

**Key points:**
- Use PHP's `sleep()` to block the worker - simple and effective
- Check global lock FIRST (respects other workers' sleeps)
- Check limit counters SECOND (might need to sleep ourselves)
- Increment counters LAST (right before request sends)
- Only track when WE set the sleep lock (prevents duplicates)

### Step 5: Update Connector
Modify `app/Api/RateTestConnector.php`:

**Before:**
```php
class RateTestConnector extends Connector
{
    use HasRateLimits; // Saloon's trait
    
    protected function resolveLimits(): array
    {
        return [
            Limit::allow(10)->everySeconds(10)->sleep(),
            Limit::allow(200)->everyMinute()->sleep(),
        ];
    }
    
    protected function resolveRateLimitStore(): RateLimitStore
    {
        return new LaravelCacheStore(Cache::store());
    }
}
```

**After:**
```php
class RateTestConnector extends Connector
{
    use HasGlobalRateLimiting; // Our custom trait
    
    protected ?int $trackingImportId = null;
    
    public function __construct(?int $trackingImportId = null)
    {
        $this->trackingImportId = $trackingImportId;
    }
    
    protected function rateLimitConfig(): RateLimitConfig
    {
        return new RateLimitConfig(
            limits: [
                [10, 10],   // 10 requests per 10 seconds
                [200, 60],  // 200 requests per 60 seconds
            ],
            onSleep: function (int $seconds) {
                if ($this->trackingImportId) {
                    $import = \App\Models\Import::find($this->trackingImportId);
                    if ($import) {
                        $import->incrementRateLimitSleeps($seconds);
                    }
                }
            },
        );
    }
}
```

### Step 6: Handle 429s in Job

The job should still handle unexpected 429s (server-side limits we didn't predict):

```php
// In ImportItemDetailsJob::handle()
$connector = new RateTestConnector($this->importId);
$request = new GetItemRequest($this->apiItemId);
$response = $connector->send($request);

// Handle 429s with same global sleep mechanism
while ($response->status() === 429) {
    $import = Import::find($this->importId);
    if ($import) {
        $import->incrementRateLimitHits();
    }
    
    $retryAfter = (int) ($response->header('Retry-After') ?? 60);
    
    // Use the same global sleep lock
    $sleepUntil = time() + $retryAfter;
    Cache::put('rate_limit:sleep_until', $sleepUntil, $retryAfter);
    
    // Track this sleep
    if ($import) {
        $import->incrementRateLimitSleeps($retryAfter);
    }
    
    sleep($retryAfter);
    $response = $connector->send($request);
}
```

**Important:** The 429 handling uses the same Redis key (`rate_limit:sleep_until`) so all workers respect it!

### Step 7: Test & Iterate
- Small test (30s, ~100 items)
- Verify: sleep < elapsed
- Verify: active time > 0
- Fix issues, repeat

## Success State

When working correctly:
- ✅ Only ONE worker tracks each distinct sleep period
- ✅ All workers respect the global sleep state
- ✅ `total_sleep_seconds` ≤ `elapsed_time` always
- ✅ Active time = elapsed - sleep is always positive
- ✅ Filament dashboard shows: "2m30s / 3m15s" (sleep/active)
