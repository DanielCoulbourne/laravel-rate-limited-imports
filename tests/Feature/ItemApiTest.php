<?php

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create 50 test items for pagination testing
    for ($i = 1; $i <= 50; $i++) {
        Item::create([
            'name' => "Test Item {$i}",
            'description' => "Description for item {$i}",
            'price' => round(rand(10, 1000) + rand(0, 99) / 100, 2),
        ]);
    }
});

test('items index returns paginated items', function () {
    $response = $this->getJson('/api/items');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'current_page',
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'description',
                    'price',
                    'created_at',
                    'updated_at',
                ]
            ],
            'first_page_url',
            'from',
            'last_page',
            'per_page',
            'to',
            'total',
        ])
        ->assertJsonPath('per_page', 15)
        ->assertJsonPath('total', 50);
});

test('items index returns correct number of items on first page', function () {
    $response = $this->getJson('/api/items');

    $response->assertStatus(200)
        ->assertJsonCount(15, 'data');
});

test('items index supports pagination', function () {
    $response = $this->getJson('/api/items?page=2');

    $response->assertStatus(200)
        ->assertJsonPath('current_page', 2)
        ->assertJsonCount(15, 'data');
});

test('items index supports custom perPage parameter', function () {
    $response = $this->getJson('/api/items?perPage=25');

    $response->assertStatus(200)
        ->assertJsonPath('per_page', 25)
        ->assertJsonCount(25, 'data')
        ->assertJsonPath('total', 50);
});

test('items index limits perPage to maximum of 100', function () {
    $response = $this->getJson('/api/items?perPage=200');

    $response->assertStatus(200)
        ->assertJsonPath('per_page', 100);
});

test('items index enforces minimum perPage of 1', function () {
    $response = $this->getJson('/api/items?perPage=0');

    $response->assertStatus(200)
        ->assertJsonPath('per_page', 1);
});

test('items index handles negative perPage values', function () {
    $response = $this->getJson('/api/items?perPage=-10');

    $response->assertStatus(200)
        ->assertJsonPath('per_page', 1);
});

test('items index with small perPage creates multiple pages', function () {
    $response = $this->getJson('/api/items?perPage=10');

    $response->assertStatus(200)
        ->assertJsonPath('per_page', 10)
        ->assertJsonPath('last_page', 5) // 50 items / 10 per page = 5 pages
        ->assertJsonCount(10, 'data');
});

test('item show returns single item', function () {
    $item = Item::first();

    $response = $this->getJson("/api/items/{$item->id}");

    $response->assertStatus(200)
        ->assertJson([
            'id' => $item->id,
            'name' => $item->name,
            'description' => $item->description,
            'price' => $item->price,
        ]);
});

test('item show returns 404 for non-existent item', function () {
    $response = $this->getJson('/api/items/999');

    $response->assertStatus(404);
});

test('items have correct data structure', function () {
    $response = $this->getJson('/api/items');

    $response->assertStatus(200);

    $firstItem = $response->json('data.0');

    expect($firstItem)->toHaveKeys([
        'id',
        'name',
        'description',
        'price',
        'created_at',
        'updated_at',
    ]);
});
