<?php

namespace App\Filament\Resources\ImportResource\Pages;

use App\Actions\ImportItems;
use App\Actions\ScheduleImport;
use App\Filament\Resources\ImportResource;
use App\Models\ImportMeta\Import;
use Filament\Actions;
use Filament\Forms\Components\Radio;
use Filament\Resources\Pages\ListRecords;
use Filament\Notifications\Notification;

class ListImports extends ListRecords
{
    protected static string $resource = ImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('run_import')
                ->label('Run Import')
                ->icon('heroicon-o-play')
                ->color('success')
                ->form([
                    Radio::make('import_type')
                        ->label('Import Type')
                        ->options([
                            'new' => 'Schedule a NEW import for right now (outside normal schedule)',
                            'reschedule' => 'RESCHEDULE the next import for right now (keep future schedule intact)',
                        ])
                        ->default('new')
                        ->required()
                        ->descriptions([
                            'new' => 'Creates a brand new import to run immediately',
                            'reschedule' => 'Takes the next scheduled import and runs it now',
                        ])
                ])
                ->action(function (array $data) {
                    if ($data['import_type'] === 'new') {
                        // Create and start a new import immediately
                        ImportItems::dispatch(false);

                        Notification::make()
                            ->title('Import Started')
                            ->body('A new import has been queued and will start processing immediately.')
                            ->success()
                            ->send();
                    } else {
                        // Find the next scheduled import and reschedule it for now
                        $nextScheduled = Import::where('status', 'scheduled')
                            ->whereNotNull('scheduled_at')
                            ->where('scheduled_at', '>', now())
                            ->orderBy('scheduled_at', 'asc')
                            ->first();

                        if ($nextScheduled) {
                            $nextScheduled->update(['scheduled_at' => now()]);
                            ImportItems::dispatch(false, $nextScheduled->id);

                            Notification::make()
                                ->title('Import Rescheduled')
                                ->body("Import #{$nextScheduled->id} has been rescheduled to run immediately.")
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('No Scheduled Import Found')
                                ->body('There are no future scheduled imports to reschedule.')
                                ->warning()
                                ->send();
                        }
                    }
                }),
        ];
    }
}
