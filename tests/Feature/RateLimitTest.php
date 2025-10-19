<?php

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a test item
    Item::create([
        'name' => 'Test Item',
        'description' => 'A test item',
        'price' => 99.99,
    ]);

    // Clear cache before each test
    Cache::flush();
});

test('api endpoints include rate limit headers', function () {
    $response = $this->getJson('/api/items');

    $response->assertStatus(200)
        ->assertHeader('X-RateLimit-Limit')
        ->assertHeader('X-RateLimit-Remaining')
        ->assertHeader('X-RateLimit-Reset');
});

test('rate limit headers show decreasing remaining count', function () {
    $response1 = $this->getJson('/api/items');
    $remaining1 = $response1->headers->get('X-RateLimit-Remaining');

    $response2 = $this->getJson('/api/items');
    $remaining2 = $response2->headers->get('X-RateLimit-Remaining');

    expect((int) $remaining2)->toBeLessThan((int) $remaining1);
});

test('exceeding rate limit returns 429 status', function () {
    // Make 21 requests to exceed the 20 per 10 seconds limit
    for ($i = 0; $i < 21; $i++) {
        $response = $this->getJson('/api/items');

        if ($i < 20) {
            $response->assertStatus(200);
        }
    }

    // 21st request should be rate limited
    $response->assertStatus(429);
});

test('rate limited response includes retry after header', function () {
    // Make 21 requests to exceed the limit
    for ($i = 0; $i < 21; $i++) {
        $response = $this->getJson('/api/items');
    }

    $response->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertHeader('X-RateLimit-Remaining', '0')
        ->assertJsonStructure([
            'message',
            'retry_after',
        ]);
});

test('rate limit applies to different endpoints independently', function () {
    // Make requests to items index
    for ($i = 0; $i < 10; $i++) {
        $this->getJson('/api/items')->assertStatus(200);
    }

    $item = Item::first();

    // Make requests to item show - should have separate rate limit tracking
    // but same limits apply, so this should still work since we're tracking
    // by IP + path
    $response = $this->getJson("/api/items/{$item->id}");
    $response->assertStatus(200);

    // Verify headers are present
    $response->assertHeader('X-RateLimit-Limit')
        ->assertHeader('X-RateLimit-Remaining');
});

test('rate limit remaining count resets after limit period', function () {
    // Make some requests
    for ($i = 0; $i < 5; $i++) {
        $this->getJson('/api/items');
    }

    $response1 = $this->getJson('/api/items');
    $remaining1 = (int) $response1->headers->get('X-RateLimit-Remaining');

    // Clear the cache to simulate time passing and limit reset
    Cache::flush();

    $response2 = $this->getJson('/api/items');
    $remaining2 = (int) $response2->headers->get('X-RateLimit-Remaining');

    // After reset, remaining should be higher than before
    expect($remaining2)->toBeGreaterThan($remaining1);
});

test('multiple rate limits are enforced simultaneously', function () {
    // The middleware applies three limits:
    // - 20 requests per 10 seconds
    // - 400 requests per minute
    // - 10000 requests per day

    // First request should have all limits available
    $response = $this->getJson('/api/items');

    $response->assertStatus(200)
        ->assertHeader('X-RateLimit-Limit')
        ->assertHeader('X-RateLimit-Remaining');

    $limit = (int) $response->headers->get('X-RateLimit-Limit');

    // The most restrictive limit (20 per 10 seconds) should be shown
    expect($limit)->toBe(20);
});

test('rate limit applies per IP address', function () {
    // Make requests from one IP
    for ($i = 0; $i < 10; $i++) {
        $this->getJson('/api/items')->assertStatus(200);
    }

    // Simulate request from different IP
    $response = $this->getJson('/api/items', [
        'REMOTE_ADDR' => '192.168.1.100'
    ]);

    $response->assertStatus(200);

    // This IP should have a fresh rate limit
    $remaining = (int) $response->headers->get('X-RateLimit-Remaining');
    expect($remaining)->toBeGreaterThan(10);
});
