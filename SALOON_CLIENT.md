# Saloon API Client Implementation

This project includes a complete **Saloon** API client implementation demonstrating best practices for building SDK clients with rate limiting, pagination, and type-safe DTOs.

## Overview

The Saloon client provides:
- ✅ Type-safe DTOs (Data Transfer Objects)
- ✅ Built-in rate limit handling
- ✅ Automatic pagination support
- ✅ Clean, testable architecture
- ✅ Comprehensive test coverage

## Installation

Saloon is already installed in this project:

```bash
composer require saloonphp/saloon
composer require saloonphp/rate-limit-plugin
```

## Architecture

```
app/Api/
├── RateTestConnector.php          # Main API connector
├── Requests/
│   ├── GetItemsRequest.php        # Paginated items request
│   └── GetItemRequest.php         # Single item request
└── DataTransferObjects/
    ├── Item.php                   # Item DTO
    └── PaginatedItems.php         # Paginated response DTO
```

## Quick Start

### Basic Usage

```php
use App\Api\RateTestConnector;
use App\Api\Requests\GetItemsRequest;
use App\Api\Requests\GetItemRequest;

// Create connector
$connector = new RateTestConnector();

// Fetch paginated items
$request = new GetItemsRequest();
$response = $connector->send($request);

if ($response->successful()) {
    $paginatedItems = $request->createDtoFromResponse($response);
    
    echo "Total items: {$paginatedItems->total}\n";
    echo "Current page: {$paginatedItems->currentPage}\n";
    
    foreach ($paginatedItems->items as $item) {
        echo "- {$item->name}: \${$item->price}\n";
    }
}
```

### Fetch Single Item

```php
$request = new GetItemRequest(itemId: 1);
$response = $connector->send($request);

if ($response->successful()) {
    $item = $request->createDtoFromResponse($response);
    
    echo "Item: {$item->name}\n";
    echo "Price: \${$item->price}\n";
}
```

### Custom Pagination

```php
// Get 50 items per page
$request = new GetItemsRequest(perPage: 50);
$response = $connector->send($request);

// Get specific page
$request = new GetItemsRequest(page: 5, perPage: 25);
$response = $connector->send($request);
```

## Rate Limiting

The connector uses Saloon's built-in rate limit plugin to automatically enforce the API's rate limits:

- **20 requests per 10 seconds** (burst protection)
- **400 requests per minute** (medium-term)
- **10,000 requests per day** (long-term)

The rate limiter will automatically throttle requests to stay within these limits.

### Rate Limit Configuration

In `app/Api/RateTestConnector.php`:

```php
protected function resolveLimits(): array
{
    return [
        Limit::allow(20)->everySeconds(10),
        Limit::allow(400)->everyMinute(),
        Limit::allow(10000)->everyDay(),
    ];
}
```

### Checking Rate Limit Headers

```php
$response = $connector->send($request);

$limit = $response->header('X-RateLimit-Limit');
$remaining = $response->header('X-RateLimit-Remaining');
$reset = $response->header('X-RateLimit-Reset');

echo "Rate limit: {$remaining}/{$limit} remaining\n";
echo "Resets at: " . date('Y-m-d H:i:s', $reset) . "\n";
```

### Handling 429 Responses

```php
$response = $connector->send($request);

if ($response->status() === 429) {
    $retryAfter = $response->header('Retry-After');
    echo "Rate limited! Retry after {$retryAfter} seconds\n";
    
    $data = $response->json();
    echo "Message: {$data['message']}\n";
}
```

## Data Transfer Objects (DTOs)

### Item DTO

Represents a single item with type-safe properties:

```php
$item = $request->createDtoFromResponse($response);

// Readonly properties
echo $item->id;           // int
echo $item->name;         // string
echo $item->description;  // ?string
echo $item->price;        // ?string
echo $item->createdAt;    // Carbon
echo $item->updatedAt;    // Carbon

// Convert to array
$array = $item->toArray();
```

### PaginatedItems DTO

Represents a paginated collection of items:

```php
$paginatedItems = $request->createDtoFromResponse($response);

// Pagination info
echo $paginatedItems->currentPage;  // int
echo $paginatedItems->total;        // int
echo $paginatedItems->perPage;      // int
echo $paginatedItems->lastPage;     // int

// Collection of Item DTOs
$paginatedItems->items;  // Illuminate\Support\Collection<Item>

// Helper methods
$paginatedItems->hasMorePages();  // bool
$paginatedItems->isFirstPage();   // bool
$paginatedItems->isLastPage();    // bool

// URLs
echo $paginatedItems->nextPageUrl;
echo $paginatedItems->prevPageUrl;

// Convert to array
$array = $paginatedItems->toArray();
```

## Advanced Examples

### Iterate Through All Pages

```php
$connector = new RateTestConnector();
$page = 1;

do {
    $request = new GetItemsRequest(page: $page, perPage: 50);
    $response = $connector->send($request);
    
    if ($response->successful()) {
        $paginatedItems = $request->createDtoFromResponse($response);
        
        foreach ($paginatedItems->items as $item) {
            // Process each item
            processItem($item);
        }
        
        $page++;
    } else {
        break;
    }
} while ($paginatedItems->hasMorePages());
```

### Collect All Items

```php
use Illuminate\Support\Collection;

function fetchAllItems(RateTestConnector $connector): Collection
{
    $allItems = collect();
    $page = 1;
    
    do {
        $request = new GetItemsRequest(page: $page, perPage: 100);
        $response = $connector->send($request);
        
        if ($response->successful()) {
            $paginatedItems = $request->createDtoFromResponse($response);
            $allItems = $allItems->merge($paginatedItems->items);
            $page++;
        } else {
            break;
        }
    } while ($paginatedItems->hasMorePages());
    
    return $allItems;
}
```

### Error Handling

```php
try {
    $request = new GetItemRequest(itemId: 9999);
    $response = $connector->send($request);
    
    if ($response->status() === 404) {
        echo "Item not found\n";
    } elseif ($response->status() === 429) {
        echo "Rate limited\n";
    } elseif (!$response->successful()) {
        echo "Request failed: " . $response->status() . "\n";
    } else {
        $item = $request->createDtoFromResponse($response);
        // Process item
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
```

## Testing

The client includes comprehensive tests in `tests/Feature/SaloonClientTest.php`.

Run the tests:

```bash
# Run all Saloon client tests
php artisan test --filter=SaloonClientTest

# Run with verbose output
php artisan test --filter=SaloonClientTest --verbose
```

**Test Coverage:**
- ✓ Fetching paginated items
- ✓ Creating DTOs from responses
- ✓ Custom pagination parameters
- ✓ Fetching specific pages
- ✓ Fetching single items
- ✓ DTO helper methods
- ✓ 404 error handling
- ✓ Rate limit headers
- ✓ DTO array conversion

**Results:**
```
Tests:    11 passed (50 assertions)
Duration: 0.32s
```

## Example Command

Run the complete example Artisan command:

```bash
php artisan saloon:example
```

This demonstrates:
1. Default pagination
2. Custom pagination
3. Fetching single items
4. Iterating through pages
5. Rate limit handling
6. Working with DTOs

## Configuration

### Environment-Based URLs

The connector automatically uses the correct URL based on environment:

```php
// In testing
return url('/api');  // Uses test application URL

// In production
return config('services.rate_test.url', 'http://localhost:8000/api');
```

### Custom Configuration

Add to `config/services.php`:

```php
'rate_test' => [
    'url' => env('RATE_TEST_API_URL', 'http://localhost:8000/api'),
],
```

Then set in `.env`:

```env
RATE_TEST_API_URL=https://api.example.com/api
```

## Best Practices

### 1. Always Use DTOs

```php
// ✓ Good - Type-safe
$item = $request->createDtoFromResponse($response);
echo $item->name;  // IDE autocomplete works!

// ✗ Avoid - Untyped arrays
$data = $response->json();
echo $data['name'];  // No type safety
```

### 2. Check Response Status

```php
// ✓ Good
if ($response->successful()) {
    $item = $request->createDtoFromResponse($response);
    // Process item
}

// ✗ Avoid - Assuming success
$item = $request->createDtoFromResponse($response);  // Might fail!
```

### 3. Handle Rate Limits Gracefully

```php
// ✓ Good
if ($response->status() === 429) {
    $retryAfter = $response->header('Retry-After');
    sleep($retryAfter);
    // Retry request
}
```

### 4. Use Pagination Helpers

```php
// ✓ Good
while ($paginatedItems->hasMorePages()) {
    // Fetch next page
}

// ✗ Avoid - Manual calculation
while ($page <= $lastPage) {
    // Easy to make off-by-one errors
}
```

## Contributing

When extending the client:

1. **Add new requests** to `app/Api/Requests/`
2. **Create DTOs** for new response types in `app/Api/DataTransferObjects/`
3. **Write tests** in `tests/Feature/SaloonClientTest.php`
4. **Update documentation** in this file

## Resources

- [Saloon Documentation](https://docs.saloon.dev/)
- [Rate Limit Plugin](https://docs.saloon.dev/the-basics/rate-limiting)
- [Testing with Saloon](https://docs.saloon.dev/testing/faking-responses)

## License

This Saloon client implementation is part of the Rate Test API project and is licensed under the MIT license.
