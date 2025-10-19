<?php

namespace App\Models;

use App\Api\Requests\GetItemRequest;
use App\Contracts\Importable;
use App\Traits\HasImportStatus;
use Illuminate\Database\Eloquent\Model;
use Saloon\Http\Request;

class Item extends Model implements Importable
{
    use HasImportStatus;

    protected $fillable = [
        'name',
        'description',
        'price',
        'external_id',
    ];

    /**
     * Get the external ID for this item from the API
     */
    public function getExternalId(): string|int
    {
        return $this->external_id ?? $this->id;
    }

    /**
     * Populate this item from API response data
     *
     * The item list endpoint only gives us the name, which we already have.
     * This method handles the detail endpoint response which includes
     * description and price.
     */
    public function populateFromApiResponse(array $data): void
    {
        $this->description = $data['description'] ?? null;
        $this->price = $data['price'] ?? null;
    }

    /**
     * Get the API request for fetching this item's details
     */
    public function getApiDetailRequest(): Request
    {
        return new GetItemRequest($this->getExternalId());
    }
}
