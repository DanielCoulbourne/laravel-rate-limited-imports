<?php

namespace App\Api\DataTransferObjects;

use Illuminate\Support\Collection;

class PaginatedItems
{
    public function __construct(
        public readonly int $currentPage,
        public readonly Collection $items,
        public readonly int $from,
        public readonly int $lastPage,
        public readonly int $perPage,
        public readonly int $to,
        public readonly int $total,
        public readonly ?string $firstPageUrl,
        public readonly ?string $lastPageUrl,
        public readonly ?string $nextPageUrl,
        public readonly ?string $prevPageUrl,
    ) {
    }

    /**
     * Create from API response array
     */
    public static function fromArray(array $data): self
    {
        $items = collect($data['data'] ?? [])
            ->map(fn (array $item) => Item::fromArray($item));

        return new self(
            currentPage: $data['current_page'],
            items: $items,
            from: $data['from'] ?? 0,
            lastPage: $data['last_page'],
            perPage: $data['per_page'],
            to: $data['to'] ?? 0,
            total: $data['total'],
            firstPageUrl: $data['first_page_url'] ?? null,
            lastPageUrl: $data['last_page_url'] ?? null,
            nextPageUrl: $data['next_page_url'] ?? null,
            prevPageUrl: $data['prev_page_url'] ?? null,
        );
    }

    /**
     * Check if there are more pages
     */
    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    /**
     * Check if on first page
     */
    public function isFirstPage(): bool
    {
        return $this->currentPage === 1;
    }

    /**
     * Check if on last page
     */
    public function isLastPage(): bool
    {
        return $this->currentPage === $this->lastPage;
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'current_page' => $this->currentPage,
            'data' => $this->items->map(fn (Item $item) => $item->toArray())->toArray(),
            'from' => $this->from,
            'last_page' => $this->lastPage,
            'per_page' => $this->perPage,
            'to' => $this->to,
            'total' => $this->total,
            'first_page_url' => $this->firstPageUrl,
            'last_page_url' => $this->lastPageUrl,
            'next_page_url' => $this->nextPageUrl,
            'prev_page_url' => $this->prevPageUrl,
        ];
    }
}
