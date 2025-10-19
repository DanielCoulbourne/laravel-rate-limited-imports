<?php

namespace App\Filament\Resources\ImportResource\Pages;

use App\Filament\Resources\ImportResource;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewImport extends ViewRecord
{
    protected static string $resource = ImportResource::class;

    protected $pollingInterval = '5s';

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Import Details')
                    ->schema([
                        TextEntry::make('id')
                            ->label('Import ID'),

                        TextEntry::make('started_at')
                            ->dateTime()
                            ->label('Started At'),

                        TextEntry::make('ended_at')
                            ->dateTime()
                            ->label('Ended At')
                            ->placeholder('In Progress'),

                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->getStateUsing(fn ($record) => $record->isComplete() ? 'Complete' : 'In Progress')
                            ->color(fn ($record) => $record->isComplete() ? 'success' : 'warning'),
                    ])
                    ->columns(2),

                Section::make('Progress')
                    ->schema([
                        TextEntry::make('items_count')
                            ->label('Total Items Discovered'),

                        TextEntry::make('items_imported_count')
                            ->label('Items Imported'),

                        TextEntry::make('progress')
                            ->label('Progress Percentage')
                            ->getStateUsing(fn ($record) => $record->getProgressPercentage() . '%')
                            ->badge()
                            ->color('success'),
                    ])
                    ->columns(3),

                Section::make('Timing')
                    ->schema([
                        TextEntry::make('total_duration')
                            ->label('Total Duration')
                            ->getStateUsing(function ($record) {
                                $seconds = $record->getTotalDurationSeconds();
                                if (!$seconds) {
                                    return 'N/A';
                                }

                                $minutes = floor($seconds / 60);
                                $secs = $seconds % 60;

                                return $minutes > 0 ? "{$minutes}m {$secs}s" : "{$secs}s";
                            }),

                        TextEntry::make('active_importing_time')
                            ->label('Active Importing Time')
                            ->getStateUsing(function ($record) {
                                $seconds = $record->getActiveImportingSeconds();
                                if ($seconds === null) {
                                    return 'N/A';
                                }

                                $minutes = floor($seconds / 60);
                                $secs = $seconds % 60;

                                return $minutes > 0 ? "{$minutes}m {$secs}s" : "{$secs}s";
                            })
                            ->badge()
                            ->color('success'),

                        TextEntry::make('total_sleep_time')
                            ->label('Total Time Sleeping (Rate Limits)')
                            ->getStateUsing(function ($record) {
                                $seconds = $record->total_sleep_seconds;
                                $minutes = floor($seconds / 60);
                                $secs = $seconds % 60;

                                return $minutes > 0 ? "{$minutes}m {$secs}s" : "{$secs}s";
                            })
                            ->badge()
                            ->color('warning'),
                    ])
                    ->columns(3),

                Section::make('Rate Limiting')
                    ->schema([
                        TextEntry::make('rate_limit_hits_count')
                            ->label('429 Response Hits')
                            ->badge()
                            ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                            ->helperText('Times we got a 429 from the server'),

                        TextEntry::make('rate_limit_sleeps_count')
                            ->label('Client-Side Sleeps')
                            ->badge()
                            ->color('warning')
                            ->helperText('Times Saloon slept to avoid hitting limits'),

                        TextEntry::make('rate_limit_efficiency')
                            ->label('Rate Limit Efficiency')
                            ->getStateUsing(function ($record) {
                                $total = $record->rate_limit_hits_count + $record->rate_limit_sleeps_count;
                                if ($total === 0) {
                                    return '100% (No rate limiting)';
                                }

                                $avoidedPercentage = round(($record->rate_limit_sleeps_count / $total) * 100, 1);

                                return "{$avoidedPercentage}% avoided";
                            })
                            ->badge()
                            ->color(function ($record) {
                                $total = $record->rate_limit_hits_count + $record->rate_limit_sleeps_count;
                                if ($total === 0) {
                                    return 'success';
                                }
                                $percentage = ($record->rate_limit_sleeps_count / $total) * 100;

                                return $percentage > 90 ? 'success' : ($percentage > 50 ? 'warning' : 'danger');
                            })
                            ->helperText('Percentage of rate limits avoided by sleeping vs hitting 429s'),
                    ])
                    ->columns(3),
            ]);
    }
}
