<?php

namespace App\Actions;

use App\Models\ImportMeta\Import;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Start Scheduled Import Action
 *
 * Handles the scheduler logic:
 * 1. Check if there's an overdue import
 * 2. If multiple overdue, cancel all but the latest
 * 3. Start the latest overdue import
 * 4. Ensure a future import is always scheduled
 *
 * Can be used as:
 * - Queued job: StartScheduledImport::dispatch()
 * - Direct call: StartScheduledImport::run()
 * - Command: php artisan import:start-scheduled
 */
class StartScheduledImport
{
    use AsAction;

    public string $commandSignature = 'import:start-scheduled';
    public string $commandDescription = 'Start overdue scheduled imports';

    public function handle(): int
    {
        // Get all overdue imports
        $overdueImports = Import::getOverdueImports();

        if ($overdueImports->isEmpty()) {
            // No overdue imports, ensure we have a future one scheduled
            if (!Import::hasFutureScheduledImport()) {
                ScheduleImport::run();
                Log::info('No future import scheduled, created one');
            }
            return Command::SUCCESS;
        }

        // If multiple overdue, cancel all but the latest
        if ($overdueImports->count() > 1) {
            $latestOverdue = $overdueImports->first();

            foreach ($overdueImports->skip(1) as $import) {
                $import->markAsCancelled();
                Log::info("Cancelled overdue import {$import->id}");
            }

            Log::info("Multiple overdue imports found, kept latest: {$latestOverdue->id}");
        } else {
            $latestOverdue = $overdueImports->first();
        }

        // Start the latest overdue import
        Log::info("Starting overdue import {$latestOverdue->id}");
        ImportItems::dispatch(false, $latestOverdue->id);

        return Command::SUCCESS;
    }

    public function asCommand(Command $command): int
    {
        $overdueImports = Import::getOverdueImports();

        if ($overdueImports->isEmpty()) {
            $command->info('No overdue imports found');

            if (!Import::hasFutureScheduledImport()) {
                $command->warn('No future import scheduled, creating one...');
            }
        } else {
            if ($overdueImports->count() > 1) {
                $command->warn("Found {$overdueImports->count()} overdue imports, cancelling all but latest");
            }

            $command->info("Starting overdue import...");
        }

        $result = $this->handle();

        if ($result === Command::SUCCESS) {
            $command->info('✓ Scheduled import processing complete');
        }

        return $result;
    }
}
