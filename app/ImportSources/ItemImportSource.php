<?php

namespace App\ImportSources;

use App\Api\RateTestConnector;
use App\Api\Requests\GetItemsRequest;
use App\Contracts\ImportSource;
use App\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Saloon\Http\Connector;
use Saloon\Http\Request;

/**
 * Import source for Item model
 *
 * This class implements the ImportSource contract to define how Items
 * should be imported from the rate-test API. It encapsulates all the
 * API-specific logic for discovering and creating Item records.
 *
 * This is a user-level implementation - each application would create
 * their own ImportSource for their specific models and APIs.
 */
class ItemImportSource implements ImportSource
{
    /**
     * Get the model class that will be imported
     */
    public function getModelClass(): string
    {
        return Item::class;
    }

    /**
     * Get the API request for listing/discovering items
     */
    public function getListRequest(int $page, int $perPage): Request
    {
        return new GetItemsRequest(page: $page, perPage: $perPage);
    }

    /**
     * Create a model instance from a list item
     *
     * At this stage, we only populate the minimal data available
     * from the list endpoint (just the name). The full details
     * (description, price) will be fetched later in parallel.
     */
    public function createModelFromListItem(array $item): Model
    {
        return Item::create([
            'name' => $item['name'],
            'external_id' => $item['id'],
        ]);
    }

    /**
     * Get the Saloon connector for API requests
     */
    public function getConnector(int $importId): Connector
    {
        return new RateTestConnector($importId);
    }

    /**
     * Determine if the API response indicates more pages exist
     */
    public function hasNextPage(array $responseData): bool
    {
        return !empty($responseData['next_page_url']) ||
               ($responseData['current_page'] ?? 0) < ($responseData['last_page'] ?? 0);
    }
}
