<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ImportResource\Pages;
use App\Models\ImportMeta\Import;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use RyanChandler\FilamentProgressColumn\ProgressColumn;

class ImportResource extends Resource
{
    protected static ?string $model = Import::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?string $navigationLabel = 'Imports';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('items_count')
                    ->label('Items')
                    ->sortable()
                    ->formatStateUsing(fn (Import $record) => number_format($record->items_count)),

                ProgressColumn::make('progress')
                    ->label('Progress')
                    ->getStateUsing(fn (Import $record) => $record->getProgressPercentage())
                    ->progress(fn (Import $record) => $record->getProgressPercentage()),

                Tables\Columns\TextColumn::make('duration')
                    ->label('Duration')
                    ->getStateUsing(function (Import $record) {
                        $seconds = $record->getTotalDurationSeconds();
                        if (!$seconds) {
                            return 'N/A';
                        }
                        $minutes = floor($seconds / 60);
                        $secs = $seconds % 60;
                        return $minutes > 0 ? "{$minutes}m {$secs}s" : "{$secs}s";
                    }),

                Tables\Columns\TextColumn::make('rate_limits')
                    ->label('Rate Limits (Hits/Sleeps)')
                    ->getStateUsing(function (Import $record) {
                        return "{$record->rate_limit_hits_count} / {$record->rate_limit_sleeps_count}";
                    })
                    ->badge()
                    ->color(fn (Import $record) => $record->rate_limit_hits_count > 0 ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('time_breakdown')
                    ->label('Sleep/Active Time')
                    ->getStateUsing(function (Import $record) {
                        $sleepSeconds = $record->total_sleep_seconds;
                        $activeSeconds = $record->getActiveImportingSeconds();

                        $formatTime = function ($seconds) {
                            if ($seconds === null) return 'N/A';
                            $minutes = floor($seconds / 60);
                            $secs = $seconds % 60;
                            return $minutes > 0 ? "{$minutes}m{$secs}s" : "{$secs}s";
                        };

                        return $formatTime($sleepSeconds) . ' / ' . $formatTime($activeSeconds);
                    }),

                Tables\Columns\IconColumn::make('status')
                    ->label('Status')
                    ->getStateUsing(fn (Import $record) => $record->isComplete())
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-clock')
                    ->trueColor('success')
                    ->falseColor('warning'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('completed')
                    ->label('Status')
                    ->placeholder('All imports')
                    ->trueLabel('Completed')
                    ->falseLabel('In Progress')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('ended_at'),
                        false: fn ($query) => $query->whereNull('ended_at'),
                    ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([])
            ->poll('1s');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListImports::route('/'),
            'view' => Pages\ViewImport::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
