<?php

namespace App\Jobs;

use App\Models\Import;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FinalizeImportJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $importId
    ) {
    }

    /**
     * Execute the job.
     *
     * This job runs after all ImportItemDetailsJob jobs should be complete.
     * It checks if the import is actually complete, and if not, re-queues itself
     * to check again later.
     */
    public function handle(): void
    {
        $import = Import::find($this->importId);

        if (!$import) {
            return;
        }

        // Check if all items have been imported
        if ($import->isComplete()) {
            $import->markAsEnded();
        } else {
            // Not complete yet, check again in 10 seconds
            static::dispatch($this->importId)->delay(now()->addSeconds(10));
        }
    }
}
