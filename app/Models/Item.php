namespace App\Models;

use App\Api\Requests\GetItemRequest;
use App\Contracts\Importable;
use App\Traits\HasImportStatus;
use Illuminate\Database\Eloquent\Model;
use Saloon\Http\Request;

/**
 * Item Model
 *
 * This is a placeholder for your application's actual Item model.
 * In a real application, this would be your business logic layer
 * that consumes data from ImportedItem.
 *
 * You might:
 * - Transform ImportedItem data into Item records
 * - Add business rules and validation
 * - Create relationships to other application models
 * - Add computed properties or accessors
 */
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
