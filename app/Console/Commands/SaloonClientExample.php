<?php

namespace App\Console\Commands;

use App\Api\RateTestConnector;
use App\Api\Requests\GetItemRequest;
use App\Api\Requests\GetItemsRequest;
use Illuminate\Console\Command;

class SaloonClientExample extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'saloon:example';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Demonstrate Saloon API client usage with examples (requires API server running)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('=================================================');
        $this->info('  Saloon API Client Example');
        $this->info('=================================================');
        $this->newLine();

        $connector = new RateTestConnector();

        // Example 1: Fetch paginated items (default)
        $this->example1FetchPaginatedItems($connector);

        // Example 2: Fetch with custom pagination
        $this->example2CustomPagination($connector);

        // Example 3: Fetch a specific item
        $this->example3FetchSingleItem($connector);

        // Example 4: Iterate through multiple pages
        $this->example4IteratePages($connector);

        // Example 5: Demonstrating rate limit handling
        $this->example5RateLimits($connector);

        // Example 6: Working with DTOs
        $this->example6WorkingWithDTOs($connector);

        $this->newLine();
        $this->info('=================================================');
        $this->info('  Example Complete!');
        $this->info('=================================================');

        return Command::SUCCESS;
    }

    protected function example1FetchPaginatedItems(RateTestConnector $connector)
    {
        $this->line('1. Fetching items with default pagination...');

        $request = new GetItemsRequest();
        $response = $connector->send($request);

        if ($response->successful()) {
            $paginatedItems = $request->createDtoFromResponse($response);

            $this->info('   ✓ Success!');
            $this->line("   - Total items: {$paginatedItems->total}");
            $this->line("   - Current page: {$paginatedItems->currentPage}");
            $this->line("   - Per page: {$paginatedItems->perPage}");
            $this->line("   - Items on this page: {$paginatedItems->items->count()}");
            $this->line("   - First item: {$paginatedItems->items->first()->name}");

            // Show rate limit info
            $remaining = $response->header('X-RateLimit-Remaining');
            $limit = $response->header('X-RateLimit-Limit');
            $this->line("   - Rate limit: {$remaining}/{$limit} remaining");
        } else {
            $this->error("   ✗ Failed: " . $response->status());
        }

        $this->newLine();
    }

    protected function example2CustomPagination(RateTestConnector $connector)
    {
        $this->line('2. Fetching items with custom pagination (50 per page)...');

        $request = new GetItemsRequest(perPage: 50);
        $response = $connector->send($request);

        if ($response->successful()) {
            $paginatedItems = $request->createDtoFromResponse($response);

            $this->info('   ✓ Success!');
            $this->line("   - Items on this page: {$paginatedItems->items->count()}");
            $this->line("   - Last page: {$paginatedItems->lastPage}");
            $this->line('   - Has more pages: ' . ($paginatedItems->hasMorePages() ? 'Yes' : 'No'));
        }

        $this->newLine();
    }

    protected function example3FetchSingleItem(RateTestConnector $connector)
    {
        $this->line('3. Fetching a single item (ID: 1)...');

        $request = new GetItemRequest(itemId: 1);
        $response = $connector->send($request);

        if ($response->successful()) {
            $item = $request->createDtoFromResponse($response);

            $this->info('   ✓ Success!');
            $this->line("   - ID: {$item->id}");
            $this->line("   - Name: {$item->name}");
            $this->line("   - Description: {$item->description}");
            $this->line("   - Price: \${$item->price}");
        }

        $this->newLine();
    }

    protected function example4IteratePages(RateTestConnector $connector)
    {
        $this->line('4. Iterating through first 3 pages...');

        for ($page = 1; $page <= 3; $page++) {
            $request = new GetItemsRequest(page: $page, perPage: 10);
            $response = $connector->send($request);

            if ($response->successful()) {
                $paginatedItems = $request->createDtoFromResponse($response);
                $this->line("   Page {$page}: {$paginatedItems->items->count()} items");

                // Show first item on each page
                $firstItem = $paginatedItems->items->first();
                $this->line("      → First item: {$firstItem->name}");
            }
        }

        $this->newLine();
    }

    protected function example5RateLimits(RateTestConnector $connector)
    {
        $this->line('5. Testing rate limits (making 25 rapid requests)...');

        $successCount = 0;
        $rateLimitedCount = 0;

        $this->withProgressBar(25, function () use ($connector, &$successCount, &$rateLimitedCount) {
            for ($i = 1; $i <= 25; $i++) {
                $request = new GetItemsRequest(perPage: 1);
                $response = $connector->send($request);

                if ($response->successful()) {
                    $successCount++;
                } elseif ($response->status() === 429) {
                    $rateLimitedCount++;
                    $retryAfter = $response->header('Retry-After');
                    $this->newLine();
                    $this->warn("   ✗ Request {$i}: Rate limited! Retry after {$retryAfter} seconds");
                }

                yield;
            }
        });

        $this->newLine();
        $this->line('   Results:');
        $this->line("   - Successful: {$successCount}");
        $this->line("   - Rate limited: {$rateLimitedCount}");
        $this->newLine();
    }

    protected function example6WorkingWithDTOs(RateTestConnector $connector)
    {
        $this->line('6. Converting DTOs to arrays...');

        $request = new GetItemRequest(itemId: 1);
        $response = $connector->send($request);

        if ($response->successful()) {
            $item = $request->createDtoFromResponse($response);
            $array = $item->toArray();

            $this->info('   ✓ Item converted to array:');
            $this->line('   ' . json_encode($array, JSON_PRETTY_PRINT));
        }

        $this->newLine();
    }
}
