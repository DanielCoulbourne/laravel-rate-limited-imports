# Rate Test API

A Laravel-based test API designed for building and testing SDKs that handle rate limiting and pagination. Perfect for developing clients that need to gracefully handle multi-tier rate limits and large paginated datasets.

## Features

- **Multi-Tier Rate Limiting**: Simultaneous enforcement of multiple rate limits
  - 20 requests per 10 seconds (burst protection)
  - 400 requests per minute (medium-term)
  - 10,000 requests per day (long-term)
- **Flexible Pagination**: Customizable `perPage` parameter (1-100 items per page)
- **Large Dataset**: 2000 randomized items for realistic testing
- **Rate Limit Headers**: Standard headers (`X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`)
- **Comprehensive Tests**: 21 Pest tests covering all API functionality
- **Per-IP Tracking**: Rate limits tracked independently per IP address

## Quick Start

### Installation

```bash
# Clone the repository
git clone <repository-url> rate-test
cd rate-test

# Install dependencies
composer install

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Run migrations and seed database
php artisan migrate --seed
```

### Start the Server

```bash
php artisan serve
```

The API will be available at `http://localhost:8000`

## API Endpoints

### GET /api/items

Returns a paginated list of items.

**Query Parameters:**
- `page` (optional): Page number (default: 1)
- `perPage` (optional): Items per page (default: 15, min: 1, max: 100)

**Example Requests:**
```bash
# Default pagination (15 items)
curl http://localhost:8000/api/items

# Custom page size
curl http://localhost:8000/api/items?perPage=50

# Specific page with custom size
curl http://localhost:8000/api/items?page=5&perPage=25
```

**Example Response:**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "name": "Professional Laptop",
      "description": "Electronics - Professional Laptop - Item #1",
      "price": "1299.99",
      "created_at": "2025-10-19T05:00:00.000000Z",
      "updated_at": "2025-10-19T05:00:00.000000Z"
    }
  ],
  "first_page_url": "http://localhost:8000/api/items?page=1",
  "from": 1,
  "last_page": 134,
  "last_page_url": "http://localhost:8000/api/items?page=134",
  "next_page_url": "http://localhost:8000/api/items?page=2",
  "path": "http://localhost:8000/api/items",
  "per_page": 15,
  "prev_page_url": null,
  "to": 15,
  "total": 2000
}
```

### GET /api/items/{id}

Returns a single item by ID.

**Example Request:**
```bash
curl http://localhost:8000/api/items/1
```

**Example Response:**
```json
{
  "id": 1,
  "name": "Professional Laptop",
  "description": "Electronics - Professional Laptop - Item #1",
  "price": "1299.99",
  "created_at": "2025-10-19T05:00:00.000000Z",
  "updated_at": "2025-10-19T05:00:00.000000Z"
}
```

## Rate Limiting

All API endpoints enforce three simultaneous rate limits. When any limit is exceeded, the API returns a `429 Too Many Requests` response.

### Rate Limit Headers

Every successful response includes rate limit information:

```
X-RateLimit-Limit: 20
X-RateLimit-Remaining: 15
X-RateLimit-Reset: 1729315200
```

### Rate Limited Response

When a limit is exceeded:

**Status:** `429 Too Many Requests`

**Headers:**
```
X-RateLimit-Limit: 20
X-RateLimit-Remaining: 0
Retry-After: 8
X-RateLimit-Reset: 1729315208
```

**Body:**
```json
{
  "message": "Too Many Requests",
  "retry_after": 8
}
```

### Testing Rate Limits

```bash
# Make 25 rapid requests to trigger rate limiting
for i in {1..25}; do
  echo "Request $i:"
  curl -i http://localhost:8000/api/items 2>/dev/null | grep -E "(HTTP|X-RateLimit|Retry-After)"
  echo ""
done
```

After 20 requests, you'll start receiving `429` responses.

## Testing

The project includes comprehensive Pest tests covering all functionality.

```bash
# Run all tests
php artisan test

# Run specific test suites
php artisan test --filter=ItemApiTest
php artisan test --filter=RateLimitTest

# Run with detailed output
php artisan test --verbose
```

**Test Coverage:**
- Pagination (default and custom `perPage`)
- Parameter validation (min/max limits)
- Rate limit enforcement
- Rate limit headers
- Per-IP tracking
- Multi-tier rate limiting
- 429 responses

**Results:**
```
Tests:    21 passed (195 assertions)
Duration: 0.30s
```

## Database

The application uses SQLite for simplicity and comes pre-seeded with 2000 items.

### Re-seeding the Database

```bash
# Reset database and reseed
php artisan migrate:fresh --seed
```

The seeder generates realistic test data with:
- Random product names (combinations of adjectives + product types)
- Categories: Electronics, Accessories, Office, Home, Gaming, etc.
- Prices ranging from $5 to $2000
- Batch inserts for performance (100 items per batch)

## Saloon API Client

This project includes a **complete Saloon API client implementation** demonstrating best practices for building SDKs. See [SALOON_CLIENT.md](SALOON_CLIENT.md) for details.

**Features:**
- ✅ Type-safe DTOs (Data Transfer Objects)
- ✅ Built-in rate limit handling with Saloon's rate limit plugin
- ✅ Automatic pagination support
- ✅ Clean, testable architecture
- ✅ 11 comprehensive tests (all passing)

**Quick Example:**

```php
use App\Api\RateTestConnector;
use App\Api\Requests\GetItemsRequest;

$connector = new RateTestConnector();
$request = new GetItemsRequest(perPage: 50);
$response = $connector->send($request);

$paginatedItems = $request->createDtoFromResponse($response);

foreach ($paginatedItems->items as $item) {
    echo "{$item->name}: \${$item->price}\n";
}
```

Run the example:
```bash
php artisan saloon:example
```

## Use Cases

This API is perfect for:

- **SDK Development**: Test rate limiting handling, retry logic, and backoff strategies
- **Pagination Testing**: Test cursor-based or page-based pagination implementations
- **Load Testing**: Test how your client handles large datasets
- **Error Handling**: Test 429 response handling and retry mechanisms
- **Integration Testing**: Use as a mock API in your test suites
- **Learning Saloon**: Complete working example of a production-ready Saloon client

## Project Structure

```
app/
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       └── ItemController.php      # API endpoints
│   └── Middleware/
│       └── MultiRateLimit.php          # Multi-tier rate limiting
├── Models/
│   └── Item.php                        # Item model
database/
├── migrations/
│   └── 2025_10_19_050040_create_items_table.php
└── seeders/
    ├── DatabaseSeeder.php
    └── ItemSeeder.php                  # 2000 item generator
routes/
└── api.php                             # API route definitions
tests/
└── Feature/
    ├── ItemApiTest.php                 # API endpoint tests
    └── RateLimitTest.php               # Rate limiting tests
```

## Implementation Details

### Custom Multi-Tier Rate Limiter

The rate limiting is implemented using a custom middleware (`App\Http\Middleware\MultiRateLimit`) that:

1. Accepts multiple rate limit definitions in a single middleware call
2. Tracks each limit independently using Laravel's cache-based rate limiter
3. Returns a 429 response if **any** limit is exceeded
4. Includes headers showing the most restrictive remaining limit
5. Tracks requests per IP address and endpoint

**Configuration** (`routes/api.php`):
```php
Route::middleware(['multi.throttle:20,10:400,60:10000,86400'])
    ->group(function () {
        Route::get('/items', [ItemController::class, 'index']);
        Route::get('/items/{item}', [ItemController::class, 'show']);
    });
```

Format: `maxAttempts,decaySeconds:maxAttempts,decaySeconds:...`
- `20,10` = 20 requests per 10 seconds
- `400,60` = 400 requests per 60 seconds (1 minute)
- `10000,86400` = 10,000 requests per 86,400 seconds (1 day)

## Requirements

- PHP 8.2+
- Composer
- SQLite (default) or MySQL/PostgreSQL

## Configuration

The application uses SQLite by default. To use a different database:

1. Update `.env`:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=rate_test
DB_USERNAME=root
DB_PASSWORD=
```

2. Run migrations:
```bash
php artisan migrate --seed
```

## Additional Documentation

See `API_USAGE.md` for more detailed API documentation and examples.

## License

This project is open-source software licensed under the MIT license.
