<?php

namespace App\Contracts;

use Saloon\Http\Request;

/**
 * Interface for models that can be imported from external APIs
 *
 * This contract defines the methods required for a model to participate
 * in the rate-limited import system. Models implementing this interface
 * can be imported without modifying their table structure - all import
 * tracking happens in the separate import_item_statuses table.
 */
interface Importable
{
    /**
     * Get the external ID for this model from the API
     *
     * This is the ID used in the external system (e.g., API ID)
     * which may differ from the local database ID.
     *
     * @return string|int The external identifier
     */
    public function getExternalId(): string|int;

    /**
     * Populate this model from API response data
     *
     * This method receives the raw API response data and should
     * update the model's attributes accordingly. The model is NOT
     * automatically saved - you can set attributes here and the
     * import system will save afterwards.
     *
     * @param array $data The API response data
     * @return void
     */
    public function populateFromApiResponse(array $data): void;

    /**
     * Get the API request for fetching this item's details
     *
     * This should return a Saloon Request object configured to
     * fetch the full details for this specific item.
     *
     * @return Request The Saloon request object
     */
    public function getApiDetailRequest(): Request;
}
