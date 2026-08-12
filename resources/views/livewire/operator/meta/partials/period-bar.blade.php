@php
    use App\Support\Integrations\ComparisonPeriod;
    $presets = ComparisonPeriod::presetLabels();
@endphp

<div class="flex flex-wrap items-center gap-2">
    @foreach ($presets as $key => $label)
        @if ($key !== ComparisonPeriod::PRESET_CUSTOM)
            <button wire:click="setPeriod('{{ $key }}')"
                @class([
                    'rounded-lg px-3 py-2 text-sm font-medium transition',
                    'bg-brand-500 text-white' => $period === $key,
                    'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $period !== $key,
                ])>
                {{ $label }}
            </button>
        @endif
    @endforeach

    <div class="flex items-center gap-2 rounded-lg bg-white px-2 py-1 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <input type="date" wire:model="periodStart" class="bg-transparent text-sm text-gray-700 dark:text-gray-300" />
        <span class="text-gray-400">→</span>
        <input type="date" wire:model="periodEnd" class="bg-transparent text-sm text-gray-700 dark:text-gray-300" />
        <button wire:click="applyCustomPeriod"
            class="rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300">Apply</button>
    </div>

    <button wire:click="toggleCompare"
        @class([
            'rounded-lg px-3 py-2 text-sm font-medium transition',
            'bg-brand-50 text-brand-600 ring-1 ring-inset ring-brand-200 dark:bg-brand-500/15 dark:text-brand-400' => $compare,
            'bg-white text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-400 dark:ring-gray-700' => ! $compare,
        ])>
        Compare {{ $compare ? 'on' : 'off' }}
    </button>
</div>
