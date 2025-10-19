<?php

namespace App\Models\ImportMeta;

use App\Api\Requests\GetItemRequest;
use App\Contracts\Importable;
use App\Traits\HasImportStatus;
use Illuminate\Database\Eloquent\Model;
use Saloon\Http\Request;

/**
 * ImportedItem Model
 *
 * Represents items that have been IMPORTED from the API.
 * This is separate from ApiItem which represents the SOURCE data.
 *
 * Implements Importable so it can participate in the import system.
 */
class ImportedItem extends Model implements Importable
{
    use HasImportStatus;

    protected $fillable = [
        'external_id',
        'name',
        'description',
        'price',
        'last_failed_at',
        'failure_reason',
        'failure_count',
    ];

    protected $casts = [
        'last_failed_at' => 'datetime',
    ];

    /**
     * Get the external ID for this item from the API
     */
    public function getExternalId(): string|int
    {
        return $this->external_id;
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

    /**
     * Mark this item as failed
     */
    public function markAsFailed(string $reason): void
    {
        $this->update([
            'last_failed_at' => now(),
            'failure_reason' => $reason,
            'failure_count' => $this->failure_count + 1,
        ]);
    }

    /**
     * Check if this item has permanently failed
     * (failed and won't be retried)
     */
    public function hasPermanentlyFailed(): bool
    {
        return $this->last_failed_at !== null;
    }
}
