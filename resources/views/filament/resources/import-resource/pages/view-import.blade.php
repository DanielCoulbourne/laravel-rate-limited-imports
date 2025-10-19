<x-filament-panels::page>
    <div wire:poll.1s="refreshRecord">
        {{-- Large Progress Bar at Top --}}
        <div class="mb-6">
            <div class="flex items-center justify-between mb-2">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                    Import Progress
                </h3>
                <span class="text-2xl font-bold text-gray-900 dark:text-white">
                    {{ number_format($record->getProgressPercentage(), 2) }}%
                </span>
            </div>

            <div class="w-full bg-gray-200 rounded-full h-8 dark:bg-gray-700 overflow-hidden">
                <div
                    class="h-8 rounded-full transition-all duration-500 flex items-center justify-end px-3"
                    style="width: {{ $record->getProgressPercentage() }}%; background-color: {{ $record->isComplete() ? 'rgb(34, 197, 94)' : 'rgb(59, 130, 246)' }};"
                >
                    <span class="text-sm font-medium text-white">
                        {{ number_format($record->items_imported_count) }} / {{ number_format($record->items_count) }}
                    </span>
                </div>
            </div>

            @if(!$record->ended_at)
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    Import in progress... This page updates automatically every second.
                </p>
            @else
                <p class="mt-2 text-sm text-green-600 dark:text-green-400 font-medium">
                    ✓ Import completed {{ $record->ended_at->diffForHumans() }}
                </p>
            @endif
        </div>

        @if ($this->hasInfolist())
            {{ $this->infolist }}
        @else
            <div
                wire:key="{{ $this->getId() }}.forms.{{ $this->getFormStatePath() }}"
            >
                {{ $this->form }}
            </div>
        @endif
    </div>
</x-filament-panels::page>
