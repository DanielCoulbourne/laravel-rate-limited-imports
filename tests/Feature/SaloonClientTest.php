<?php

use App\Api\DataTransferObjects\Item;
use App\Api\DataTransferObjects\PaginatedItems;
use App\Api\RateTestConnector;
use App\Api\Requests\GetItemRequest;
use App\Api\Requests\GetItemsRequest;

test('connector can fetch paginated items', function () {
    $connector = new RateTestConnector();
    $request = new GetItemsRequest();


    $response = $connector->send($request);


    expect($response->successful())->toBeTrue();


    $data = $response->json();
    expect($data)->toHaveKeys(['current_page', 'data', 'total', 'per_page']);
    expect($data['total'])->toBe(2000);
    expect($data['per_page'])->toBe(15);
});

test('connector can create DTO from paginated response', function () {
    $connector = new RateTestConnector();
    $request = new GetItemsRequest();


    $response = $connector->send($request);
    $paginatedItems = $request->createDtoFromResponse($response);


    expect($paginatedItems)->toBeInstanceOf(PaginatedItems::class);
    expect($paginatedItems->total)->toBe(2000);
    expect($paginatedItems->perPage)->toBe(15);
    expect($paginatedItems->items)->toHaveCount(15);
    expect($paginatedItems->items->first())->toBeInstanceOf(Item::class);
});

test('connector can fetch items with custom perPage', function () {
    $connector = new RateTestConnector();
    $request = new GetItemsRequest(perPage: 25);


    $response = $connector->send($request);
    $paginatedItems = $request->createDtoFromResponse($response);


    expect($paginatedItems->perPage)->toBe(25);
    expect($paginatedItems->items)->toHaveCount(25);
});

test('connector can fetch specific page', function () {
    $connector = new RateTestConnector();
    $request = new GetItemsRequest(page: 2, perPage: 10);


    $response = $connector->send($request);
    $paginatedItems = $request->createDtoFromResponse($response);


    expect($paginatedItems->currentPage)->toBe(2);
    expect($paginatedItems->perPage)->toBe(10);
    expect($paginatedItems->items)->toHaveCount(10);
});

test('connector can fetch single item', function () {
    $connector = new RateTestConnector();
    $request = new GetItemRequest(itemId: 1);


    $response = $connector->send($request);


    expect($response->successful())->toBeTrue();


    $data = $response->json();
    expect($data)->toHaveKeys(['id', 'name', 'description', 'price']);
    expect($data['id'])->toBe(1);
});

test('connector can create DTO from single item response', function () {
    $connector = new RateTestConnector();
    $request = new GetItemRequest(itemId: 1);


    $response = $connector->send($request);
    $item = $request->createDtoFromResponse($response);


    expect($item)->toBeInstanceOf(Item::class);
    expect($item->id)->toBe(1);
    expect($item->name)->not()->toBeEmpty();
});

test('paginated items DTO has helper methods', function () {
    $connector = new RateTestConnector();


    // First page
    $request = new GetItemsRequest(page: 1, perPage: 10);
    $response = $connector->send($request);
    $paginatedItems = $request->createDtoFromResponse($response);


    expect($paginatedItems->isFirstPage())->toBeTrue();
    expect($paginatedItems->isLastPage())->toBeFalse();
    expect($paginatedItems->hasMorePages())->toBeTrue();


    // Last page (2000 items / 10 per page = 200 pages)
    $request = new GetItemsRequest(page: 200, perPage: 10);
    $response = $connector->send($request);
    $paginatedItems = $request->createDtoFromResponse($response);


    expect($paginatedItems->isFirstPage())->toBeFalse();
    expect($paginatedItems->isLastPage())->toBeTrue();
    expect($paginatedItems->hasMorePages())->toBeFalse();
});

test('connector handles 404 for non-existent item', function () {
    $connector = new RateTestConnector();
    $request = new GetItemRequest(itemId: 9999);


    $response = $connector->send($request);


    expect($response->status())->toBe(404);
    expect($response->successful())->toBeFalse();
});

test('connector receives rate limit headers', function () {
    $connector = new RateTestConnector();
    $request = new GetItemsRequest();


    $response = $connector->send($request);


    expect($response->header('X-RateLimit-Limit'))->not()->toBeNull();
    expect($response->header('X-RateLimit-Remaining'))->not()->toBeNull();
    expect($response->header('X-RateLimit-Reset'))->not()->toBeNull();
});

test('item DTO can convert to array', function () {
    $connector = new RateTestConnector();
    $request = new GetItemRequest(itemId: 1);


    $response = $connector->send($request);
    $item = $request->createDtoFromResponse($response);


    $array = $item->toArray();


    expect($array)->toHaveKeys(['id', 'name', 'description', 'price', 'created_at', 'updated_at']);
    expect($array['id'])->toBe(1);
});

test('paginated items DTO can convert to array', function () {
    $connector = new RateTestConnector();
    $request = new GetItemsRequest();


    $response = $connector->send($request);
    $paginatedItems = $request->createDtoFromResponse($response);


    $array = $paginatedItems->toArray();


    expect($array)->toHaveKeys(['current_page', 'data', 'total', 'per_page']);
    expect($array['data'])->toBeArray();
    expect($array['data'])->toHaveCount(15);
});
