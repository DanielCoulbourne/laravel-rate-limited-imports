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
                    ->formatStateUsing(function (Import $record) {
                        if ($record->items_count === null) {
                            return '—';
                        }
                        return number_format($record->items_count);
                    }),

                ProgressColumn::make('progress')
                    ->label('Progress')
                    ->getStateUsing(fn (Import $record) => $record->getProgressPercentage())
                    ->progress(fn (Import $record) => $record->getProgressPercentage()),

                Tables\Columns\TextColumn::make('duration')
                    ->label('Duration')
                    ->getStateUsing(function (Import $record) {
                        $seconds = $record->getTotalDurationSeconds();
                        if (!$seconds) {
                            return '—';
                        }
                        $minutes = floor($seconds / 60);
                        $secs = $seconds % 60;
                        return $minutes > 0 ? "{$minutes}m {$secs}s" : "{$secs}s";
                    }),

                Tables\Columns\TextColumn::make('rate_limits')
                    ->label('Rate Limits')
                    ->getStateUsing(function (Import $record) {
                        $hits = $record->rate_limit_hits_count;
                        $sleepSeconds = $record->total_sleep_seconds;

                        if ($hits === 0) {
                            return '—';
                        }

                        $formatTime = function ($seconds) {
                            if ($seconds === null || $seconds === 0) return '0s';
                            $minutes = floor($seconds / 60);
                            $secs = $seconds % 60;
                            return $minutes > 0 ? "{$minutes}m{$secs}s" : "{$secs}s";
                        };

                        return "{$hits} (🕑 {$formatTime($sleepSeconds)})";
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(function (Import $record) {
                        if ($record->isCancelled()) {
                            return 'Cancelled';
                        }
                        if ($record->isComplete()) {
                            return 'Completed';
                        }
                        if ($record->isRunning()) {
                            return 'Running';
                        }
                        if ($record->isOverdue()) {
                            return 'Overdue';
                        }
                        if ($record->isScheduled()) {
                            return 'Scheduled';
                        }
                        return 'Unknown';
                    })
                    ->color(fn (Import $record) => match(true) {
                        $record->isCancelled() => 'gray',
                        $record->isComplete() => 'success',
                        $record->isRunning() => 'warning',
                        $record->isOverdue() => 'danger',
                        $record->isScheduled() => 'info',
                        default => 'gray',
                    })
                    ->icon(fn (Import $record) => match(true) {
                        $record->isCancelled() => 'heroicon-o-x-circle',
                        $record->isComplete() => 'heroicon-o-check-circle',
                        $record->isRunning() => 'heroicon-o-clock',
                        $record->isOverdue() => 'heroicon-o-exclamation-triangle',
                        $record->isScheduled() => 'heroicon-o-calendar',
                        default => 'heroicon-o-question-mark-circle',
                    }),
                Tables\Columns\TextColumn::make('scheduled_at')
                    ->label('Scheduled For')
                    ->sortable()
                    ->toggleable()
                    ->formatStateUsing(function (Import $record) {
                        if (!$record->scheduled_at) {
                            return '—';
                        }

                        $scheduledAt = $record->scheduled_at;
                        $now = now();

                        // Format time in EST
                        $timeFormatted = $scheduledAt->timezone('America/New_York')->format('g:ia T');

                        // Check if today
                        if ($scheduledAt->isToday()) {
                            return $timeFormatted;
                        }

                        // Check if tomorrow
                        if ($scheduledAt->isTomorrow()) {
                            return "Tomorrow at {$timeFormatted}";
                        }

                        // Check if yesterday
                        if ($scheduledAt->isYesterday()) {
                            return "Yesterday at {$timeFormatted}";
                        }

                        // Calculate days difference
                        $daysDiff = (int) $now->startOfDay()->diffInDays($scheduledAt->startOfDay(), false);

                        if ($daysDiff > 0) {
                            // Future dates
                            return abs($daysDiff) . " days from now at {$timeFormatted}";
                        } else {
                            // Past dates
                            return abs($daysDiff) . " days ago at {$timeFormatted}";
                        }
                    }),
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
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\Action::make('cancel')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Cancel Import')
                        ->modalDescription('Are you sure you want to cancel this import? This action cannot be undone.')
                        ->action(fn (Import $record) => $record->markAsCancelled())
                        ->visible(fn (Import $record) => $record->isScheduled() || $record->isOverdue()),
                    Tables\Actions\DeleteAction::make()
                        ->visible(fn (Import $record) => !$record->isScheduled() && !$record->isOverdue() && !$record->isRunning()),
                ]),
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
        // Allow deleting any import that's not scheduled, overdue, or running
        return !$record->isScheduled() && !$record->isOverdue() && !$record->isRunning();
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
