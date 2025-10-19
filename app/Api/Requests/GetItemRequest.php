<?php

namespace App\Api\Requests;

use App\Api\DataTransferObjects\Item;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;

class GetItemRequest extends Request
{
    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::GET;

    /**
     * Create a new request instance
     */
    public function __construct(
        protected int $itemId,
    ) {
    }

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return '/items/' . $this->itemId;
    }

    /**
     * Create a DTO from the response
     */
    public function createDtoFromResponse(Response $response): Item
    {
        return Item::fromArray($response->json());
    }
}
