# Rate Test API

A Laravel test API with multi-tier rate limiting for testing SDK implementations.

## Features

- **Paginated Items Index**: GET `/api/items` - Returns a paginated list of items
- **Item Detail**: GET `/api/items/{id}` - Returns a single item by ID
- **Multi-Tier Rate Limiting**: All endpoints are protected by three simultaneous rate limits:
  - 20 requests per 10 seconds
  - 400 requests per minute  
  - 10,000 requests per day

## Installation & Setup

The application is already set up. To start using it:

1. Start the development server:
```bash
php artisan serve
```

2. The API will be available at `http://localhost:8000/api`

## API Endpoints

### Get Items (Paginated)
```bash
GET /api/items
GET /api/items?perPage=25
GET /api/items?page=2&perPage=50
```

**Query Parameters:**
- `page` (optional): Page number (default: 1)
- `perPage` (optional): Items per page (default: 15, min: 1, max: 100)

**Response:**
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
  "per_page": 15,
  "to": 15,
  "total": 2000
}
```

### Get Single Item
```bash
GET /api/items/{id}
```

**Response:**
```json
{
  "id": 1,
  "name": "Laptop",
  "description": "High-performance laptop for developers",
  "price": "1299.99",
  "created_at": "2025-10-19T05:00:00.000000Z",
  "updated_at": "2025-10-19T05:00:00.000000Z"
}
```

## Rate Limiting

All API endpoints enforce the following rate limits **simultaneously**:

1. **Short-term burst protection**: 20 requests per 10 seconds
2. **Medium-term protection**: 400 requests per minute
3. **Long-term protection**: 10,000 requests per day

### Rate Limit Headers

Each response includes rate limit information in the headers:

- `X-RateLimit-Limit`: Maximum requests allowed
- `X-RateLimit-Remaining`: Requests remaining
- `X-RateLimit-Reset`: Unix timestamp when the limit resets

### Rate Limit Exceeded Response

When a rate limit is exceeded, you'll receive a `429 Too Many Requests` response:

```json
{
  "message": "Too Many Requests",
  "retry_after": 8
}
```

**Headers:**
- `Retry-After`: Seconds to wait before retrying
- `X-RateLimit-Limit`: The limit that was exceeded
- `X-RateLimit-Remaining`: 0
- `X-RateLimit-Reset`: Unix timestamp when you can retry

## Testing Rate Limits

Comprehensive Pest tests are included to verify the rate limiting behavior:

```bash
# Run all tests
php artisan test

# Run only API tests
php artisan test --filter=ItemApiTest

# Run only rate limit tests
php artisan test --filter=RateLimitTest
```

The tests verify:
- Paginated item responses
- Rate limit header presence and accuracy
- Multi-tier rate limiting enforcement
- 429 responses when limits are exceeded
- Per-IP rate limit tracking

## Example Usage with curl

```bash
# Get items with default pagination (15 per page)
curl -i http://localhost:8000/api/items

# Get items with custom pagination
curl -i http://localhost:8000/api/items?perPage=50

# Get a specific page
curl -i http://localhost:8000/api/items?page=5&perPage=25

# Get a specific item
curl -i http://localhost:8000/api/items/1

# Test rate limiting (run this quickly multiple times)
for i in {1..25}; do
  echo "Request $i:"
  curl -i http://localhost:8000/api/items 2>/dev/null | grep -E "(HTTP|X-RateLimit|Retry-After)"
  echo ""
done
```

## Database

The application uses SQLite (configured in `.env`) and comes pre-seeded with 2000 sample items with randomized product names, descriptions, and prices.

To re-seed the database:

```bash
php artisan migrate:fresh --seed --seeder=ItemSeeder
```

The seeder generates items with:
- Random combinations of adjectives, product types, and categories
- Prices ranging from $5 to $2000
- Batch inserts for performance (100 items per batch)

## Implementation Details

The multi-tier rate limiting is implemented using a custom middleware (`App\Http\Middleware\MultiRateLimit`) that:

1. Tracks each rate limit independently using Laravel's cache-based rate limiter
2. Checks all limits on every request
3. Returns a 429 response if ANY limit is exceeded
4. Adds headers showing the most restrictive remaining limit

This approach allows you to test complex rate limiting scenarios that real-world APIs commonly use.
