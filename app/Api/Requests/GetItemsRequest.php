<?php

namespace App\Api\Requests;

use App\Api\DataTransferObjects\PaginatedItems;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;

class GetItemsRequest extends Request
{
    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::GET;

    /**
     * Create a new request instance
     */
    public function __construct(
        protected ?int $page = null,
        protected ?int $perPage = null,
    ) {
    }

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return '/items';
    }

    /**
     * Query parameters for the request
     */
    protected function defaultQuery(): array
    {
        return array_filter([
            'page' => $this->page,
            'perPage' => $this->perPage,
        ], fn ($value) => $value !== null);
    }

    /**
     * Create a DTO from the response
     */
    public function createDtoFromResponse(Response $response): PaginatedItems
    {
        return PaginatedItems::fromArray($response->json());
    }
}
