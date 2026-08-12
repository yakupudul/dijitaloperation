@php
    $presets = [
        'last_7' => 'Last 7 days',
        'last_14' => 'Last 14 days',
        'last_28' => 'Last 28 days',
        'last_30' => 'Last 30 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
    ];
    $compareOn = (bool) ($compare ?? true);
@endphp

<div class="flex flex-wrap items-center gap-1.5">
    @foreach ($presets as $key => $label)
        <button type="button" wire:click="setPeriod('{{ $key }}')"
            @class([
                'rounded-md px-2.5 py-1.5 text-xs font-medium transition',
                'bg-brand-500 text-white' => $period === $key,
                'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $period !== $key,
            ])>
            {{ $label }}
        </button>
    @endforeach

    <button type="button" wire:click="openCustomPicker"
        @class([
            'rounded-md px-2.5 py-1.5 text-xs font-medium transition',
            'bg-brand-500 text-white' => $period === 'custom',
            'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $period !== 'custom',
        ])>
        Custom
    </button>

    <button type="button" wire:click="toggleCompare"
        @class([
            'rounded-md px-2.5 py-1.5 text-xs font-medium transition',
            'bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-200 dark:bg-brand-500/15 dark:text-brand-400 dark:ring-brand-500/30' => $compareOn,
            'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => ! $compareOn,
        ])
        title="Compare to previous period">
        Compare{{ $compareOn ? ' · on' : '' }}
    </button>

    @if ($showCustomPicker || $period === 'custom')
        <div class="flex items-center gap-1.5 rounded-md bg-white px-2 py-1 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"
            wire:ignore.self>
            <input type="text" data-flatpickr-range
                wire:model="periodStart"
                placeholder="Start → End"
                class="w-44 bg-transparent px-1.5 py-1 text-xs text-gray-700 dark:text-gray-300"
                x-data
                x-on:demo-range-selected.window="
                    const parts = $event.detail.dateStr.split(' to ');
                    if (parts.length === 2) {
                        $wire.set('periodStart', parts[0]);
                        $wire.set('periodEnd', parts[1]);
                    }
                " />
            <button type="button" wire:click="applyCustomPeriod"
                class="rounded-md bg-brand-500 px-2 py-1 text-xs font-medium text-white hover:bg-brand-600">Apply</button>
        </div>
    @endif
</div>
