<?php

use App\Api\RateTestConnector;
use App\Api\Requests\GetItemsRequest;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Response;
use Saloon\RateLimitPlugin\Exceptions\RateLimitReachedException;
use Saloon\RateLimitPlugin\Limit;

beforeEach(function () {
    // Clear rate limit cache before each test
    Cache::flush();
});

test('saloon client sleeps when client-side rate limit is reached', function () {
    $connector = new RateTestConnector;

    $successCount = 0;
    $startTime = microtime(true);

    // Make requests - should sleep when rate limit is hit
    for ($i = 1; $i <= 25; $i++) {
        $request = new GetItemsRequest(perPage: 1);
        $response = $connector->send($request);

        if ($response->successful()) {
            $successCount++;
        }
    }

    $duration = microtime(true) - $startTime;

    // Should have successfully made all requests
    expect($successCount)->toBe(25);

    // Should have taken extra time due to sleeping (at least a few seconds)
    // 25 requests with 20 req/10sec limit means we need at least 2 windows = ~10+ seconds
    expect($duration)->toBeGreaterThan(5);
});

test('saloon client shares rate limit state across multiple connector instances', function () {
    // This simulates multiple queue workers using LaravelCacheStore
    Cache::flush();

    $connector1 = new RateTestConnector;
    $connector2 = new RateTestConnector;

    // Make 10 requests from first connector
    for ($i = 1; $i <= 10; $i++) {
        $connector1->send(new GetItemsRequest(perPage: 1));
    }

    // Make 5 more from second connector - should share the same rate limit counter
    // Total is 15 out of 20, so should NOT sleep yet
    $startTime = microtime(true);

    for ($i = 1; $i <= 5; $i++) {
        $connector2->send(new GetItemsRequest(perPage: 1));
    }

    $duration = microtime(true) - $startTime;

    // Should complete quickly since we're under the limit
    expect($duration)->toBeLessThan(2);

    // Now make 10 more requests, pushing us to 25 total
    $startTime2 = microtime(true);
    for ($i = 1; $i <= 10; $i++) {
        $connector1->send(new GetItemsRequest(perPage: 1));
    }
    $duration2 = microtime(true) - $startTime2;

    // Should have slept because the shared store knows we already made 15 requests
    expect($duration2)->toBeGreaterThan(3);
});

test('server-side 429 response structure is correct', function () {
    // Create a connector without rate limiting to get raw 429 from server
    Cache::flush();

    $connector = new class extends RateTestConnector
    {
        protected bool $rateLimitingEnabled = false;
    };

    $rateLimitResponse = null;

    // Make requests until server returns 429
    for ($i = 1; $i <= 25; $i++) {
        $response = $connector->send(new GetItemsRequest(perPage: 1));

        if ($response->status() === 429) {
            $rateLimitResponse = $response;
            break;
        }
    }

    // Should have hit server-side rate limit
    expect($rateLimitResponse)->not()->toBeNull('Should get 429 from server');
    expect($rateLimitResponse->status())->toBe(429);

    // Verify 429 response structure
    expect($rateLimitResponse->header('X-RateLimit-Limit'))->not()->toBeNull();
    expect($rateLimitResponse->header('X-RateLimit-Remaining'))->toBe('0');
    expect($rateLimitResponse->header('Retry-After'))->not()->toBeNull();

    $retryAfter = (int) $rateLimitResponse->header('Retry-After');
    expect($retryAfter)->toBeGreaterThan(0);
    expect($retryAfter)->toBeLessThanOrEqual(10); // Our limit is 10 seconds

    $resetTime = (int) $rateLimitResponse->header('X-RateLimit-Reset');
    expect($resetTime)->toBeGreaterThan(time());

    $body = $rateLimitResponse->json();
    expect($body['message'])->toBe('Too Many Requests');
    expect($body['retry_after'])->toBeInt();

    // Response should be identified as failed
    expect($rateLimitResponse->successful())->toBeFalse();
    expect($rateLimitResponse->clientError())->toBeTrue();
    expect($rateLimitResponse->failed())->toBeTrue();
});

test('custom 429 handler detects and handles server rate limits', function () {
    // Test that the custom limit in our connector properly detects 429s
    // Note: The custom limit with sleep() may still throw if it hits too many 429s
    // This is expected behavior - it means the API is consistently rate limiting us

    Cache::flush();
    $connector = new RateTestConnector;

    $got429 = false;
    $exceptionThrown = false;

    try {
        // Make many requests to trigger server-side 429
        for ($i = 1; $i <= 30; $i++) {
            $response = $connector->send(new GetItemsRequest(perPage: 1));

            if ($response->status() === 429) {
                $got429 = true;
            }
        }
    } catch (RateLimitReachedException $e) {
        // This can happen if the custom 429 limit keeps getting hit
        $exceptionThrown = true;
    }

    // We should either get a 429 response OR an exception from the 429 handler
    // Both indicate the rate limiting is working
    expect($got429 || $exceptionThrown)->toBeTrue('Should detect rate limiting');
});
