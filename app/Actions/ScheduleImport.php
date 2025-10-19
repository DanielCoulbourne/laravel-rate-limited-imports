<?php

namespace App\Actions;

use App\ImportSources\ItemImportSource;
use App\Models\ImportMeta\Import;
use Illuminate\Console\Command;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Schedule Import Action
 *
 * Creates a new scheduled import for the next scheduled time slot.
 * Schedule: 0, 3, 6, 9, 12, 15, 18, 21 o'clock
 *
 * Can be used as:
 * - Queued job: ScheduleImport::dispatch()
 * - Direct call: ScheduleImport::run()
 * - Command: php artisan import:schedule
 */
class ScheduleImport
{
    use AsAction;

    public string $commandSignature = 'import:schedule';
    public string $commandDescription = 'Schedule the next import';

    public function handle(): int
    {
        $source = new ItemImportSource();
        $nextScheduledTime = Import::getNextScheduledTime();

        // Create the scheduled import
        $import = Import::create([
            'importable_type' => $source->getModelClass(),
            'status' => 'scheduled',
            'scheduled_at' => $nextScheduledTime,
        ]);

        return Command::SUCCESS;
    }

    public function asCommand(Command $command): int
    {
        $result = $this->handle();

        if ($result === Command::SUCCESS) {
            $import = Import::getLatestScheduled();
            if ($import) {
                $command->info("✓ Import scheduled for {$import->scheduled_at->format('Y-m-d H:i:s')}");
                $command->comment("Import ID: {$import->id}");
            }
        }

        return $result;
    }
}
