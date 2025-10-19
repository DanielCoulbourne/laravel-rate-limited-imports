<?php

use App\Api\RateTestConnector;
use App\Api\Requests\GetItemsRequest;
use Illuminate\Support\Facades\Cache;
use Saloon\RateLimitPlugin\Exceptions\RateLimitReachedException;

beforeEach(function () {
    // Clear rate limit cache before each test
    Cache::flush();
});

test('saloon client throws exception when client-side rate limit is reached', function () {
    $connector = new RateTestConnector();

    $successCount = 0;
    $exceptionThrown = false;
    $exception = null;

    // Make requests until Saloon's client-side rate limiter throws an exception
    for ($i = 1; $i <= 25; $i++) {
        try {
            $request = new GetItemsRequest(perPage: 1);
            $response = $connector->send($request);

            if ($response->successful()) {
                $successCount++;
            }
        } catch (RateLimitReachedException $e) {
            $exceptionThrown = true;
            $exception = $e;
            break;
        }
    }

    // Should have hit the client-side rate limit and thrown exception
    expect($exceptionThrown)->toBeTrue('Saloon should throw RateLimitReachedException');
    expect($successCount)->toBeGreaterThanOrEqual(19)->toBeLessThanOrEqual(20);

    // Verify exception details
    expect($exception)->toBeInstanceOf(RateLimitReachedException::class);
    expect($exception->getMessage())->toContain('Rate Limit Reached');
});

test('saloon client rate limit exception contains limit information', function () {
    $connector = new RateTestConnector();

    $exception = null;

    // Make requests until we hit the limit
    for ($i = 1; $i <= 25; $i++) {
        try {
            $response = $connector->send(new GetItemsRequest(perPage: 1));
        } catch (RateLimitReachedException $e) {
            $exception = $e;
            break;
        }
    }

    expect($exception)->not()->toBeNull();
    expect($exception)->toBeInstanceOf(RateLimitReachedException::class);

    // Get the limit from the exception
    $limit = $exception->getLimit();
    expect($limit)->not()->toBeNull();

    // The limit should have information about max attempts
    expect($limit->maxAttempts)->toBeGreaterThan(0);
});

test('saloon client can catch and handle rate limit exception gracefully', function () {
    $connector = new RateTestConnector();

    $successCount = 0;
    $rateLimitHit = false;
    $retriedSuccessfully = false;

    for ($i = 1; $i <= 25; $i++) {
        try {
            $response = $connector->send(new GetItemsRequest(perPage: 1));

            if ($response->successful()) {
                $successCount++;
            }
        } catch (RateLimitReachedException $e) {
            $rateLimitHit = true;

            // In production, you would wait before retrying
            // sleep($e->getLimit()->releaseInSeconds);

            // For testing, simulate waiting by clearing cache
            Cache::flush();

            // Try again
            try {
                $response = $connector->send(new GetItemsRequest(perPage: 1));
                if ($response->successful()) {
                    $retriedSuccessfully = true;
                }
            } catch (RateLimitReachedException $e) {
                // Still rate limited
            }

            break;
        }
    }

    expect($rateLimitHit)->toBeTrue();
    expect($successCount)->toBeGreaterThanOrEqual(19);
    expect($retriedSuccessfully)->toBeTrue('Should succeed after clearing cache');
});

test('server-side rate limit returns 429 without client-side limiting', function () {
    // Create a connector without rate limiting for this test
    $connector = new class extends RateTestConnector {
        protected function resolveLimits(): array
        {
            return []; // Disable client-side rate limiting
        }
    };

    $successCount = 0;
    $rateLimitResponse = null;

    // Make requests until server returns 429
    for ($i = 1; $i <= 25; $i++) {
        $response = $connector->send(new GetItemsRequest(perPage: 1));

        if ($response->status() === 429) {
            $rateLimitResponse = $response;
            break;
        } elseif ($response->successful()) {
            $successCount++;
        }
    }

    // Should have hit server-side rate limit
    expect($rateLimitResponse)->not()->toBeNull('Should get 429 from server');
    expect($rateLimitResponse->status())->toBe(429);
    expect($successCount)->toBeGreaterThanOrEqual(19);

    // Verify 429 response structure
    expect($rateLimitResponse->header('X-RateLimit-Limit'))->not()->toBeNull();
    expect($rateLimitResponse->header('X-RateLimit-Remaining'))->toBe('0');
    expect($rateLimitResponse->header('Retry-After'))->not()->toBeNull();

    $body = $rateLimitResponse->json();
    expect($body['message'])->toBe('Too Many Requests');
    expect($body['retry_after'])->toBeInt();
});

test('server 429 response includes proper retry-after header', function () {
    // Connector without client-side limiting
    $connector = new class extends RateTestConnector {
        protected function resolveLimits(): array
        {
            return [];
        }
    };

    $rateLimitResponse = null;

    for ($i = 1; $i <= 25; $i++) {
        $response = $connector->send(new GetItemsRequest(perPage: 1));
        if ($response->status() === 429) {
            $rateLimitResponse = $response;
            break;
        }
    }

    expect($rateLimitResponse)->not()->toBeNull();

    $retryAfter = (int) $rateLimitResponse->header('Retry-After');
    expect($retryAfter)->toBeGreaterThan(0);
    expect($retryAfter)->toBeLessThanOrEqual(10); // Our limit is 10 seconds

    $resetTime = (int) $rateLimitResponse->header('X-RateLimit-Reset');
    expect($resetTime)->toBeGreaterThan(time());
});

test('server 429 response is properly identified as failed', function () {
    $connector = new class extends RateTestConnector {
        protected function resolveLimits(): array
        {
            return [];
        }
    };

    $rateLimitResponse = null;

    for ($i = 1; $i <= 25; $i++) {
        $response = $connector->send(new GetItemsRequest(perPage: 1));
        if ($response->status() === 429) {
            $rateLimitResponse = $response;
            break;
        }
    }

    expect($rateLimitResponse)->not()->toBeNull();
    expect($rateLimitResponse->successful())->toBeFalse();
    expect($rateLimitResponse->clientError())->toBeTrue();
    expect($rateLimitResponse->failed())->toBeTrue();
});
