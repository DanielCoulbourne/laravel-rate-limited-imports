<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Model;
use Saloon\Http\Connector;
use Saloon\Http\Request;

/**
 * Interface for import source configurations
 *
 * An ImportSource defines HOW to import a specific type of model.
 * It encapsulates all the API-specific logic: how to discover items,
 * how to create models from API data, and which connector to use.
 *
 * This allows the import system to be completely generic - users just
 * implement this interface for their specific use case.
 */
interface ImportSource
{
    /**
     * Get the model class name that will be imported
     *
     * @return class-string<Model> The fully qualified model class name
     */
    public function getModelClass(): string;

    /**
     * Get the API request for listing/discovering items
     *
     * This should return a Saloon Request configured for pagination.
     * The import system will call this repeatedly with increasing
     * page numbers until all items are discovered.
     *
     * @param int $page The page number to fetch
     * @param int $perPage Number of items per page
     * @return Request The Saloon request object
     */
    public function getListRequest(int $page, int $perPage): Request;

    /**
     * Create a model instance from a list item
     *
     * Receives a single item from the list API response and should
     * create (and save) a new model instance with minimal data.
     * The full details will be fetched later in parallel.
     *
     * @param array $item Single item from the API list response
     * @return Model The created model instance
     */
    public function createModelFromListItem(array $item): Model;

    /**
     * Get the Saloon connector for API requests
     *
     * This connector will be used for all API requests during import.
     * It should include rate limiting configuration.
     *
     * @param int $importId The import ID (for tracking rate limits per import)
     * @return Connector The Saloon connector instance
     */
    public function getConnector(int $importId): Connector;

    /**
     * Determine if the API response indicates more pages exist
     *
     * @param array $responseData The full API response data
     * @return bool True if there are more pages to fetch
     */
    public function hasNextPage(array $responseData): bool;
}
