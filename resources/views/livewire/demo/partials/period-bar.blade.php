@php
    $presets = [
        'last_7' => 'Last 7 days',
        'last_14' => 'Last 14 days',
        'last_28' => 'Last 28 days',
        'last_30' => 'Last 30 days',
        'last_90' => 'Last 3 months',
        'this_month' => 'This month',
        'last_month' => 'Last month',
    ];
    $compareOn = (bool) ($compare ?? true);
    $appliedLabel = method_exists($this, 'appliedPeriodLabel') ? $this->appliedPeriodLabel() : ($period === 'custom' && $periodStart && $periodEnd ? $periodStart.' – '.$periodEnd : ($presets[$period] ?? 'Custom'));
    $compareLabel = method_exists($this, 'comparePeriodLabel') ? $this->comparePeriodLabel() : null;
    $maxDate = method_exists($this, 'periodAnchorDate')
        ? $this->periodAnchorDate()
        : \App\Support\Demo\DemoPeriod::anchor()->toDateString();
    $minDate = \Carbon\Carbon::parse($maxDate)->subDays(89)->toDateString();
@endphp

<div class="flex flex-wrap items-center gap-1.5" data-demo-period-bar>
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
        ])
        aria-expanded="{{ $showCustomPicker ? 'true' : 'false' }}">
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

    <span class="ml-1 text-xs text-gray-500 dark:text-gray-400" data-applied-range>
        {{ $appliedLabel }}
        @if ($compareOn && $compareLabel)
            <span class="text-gray-400">vs</span> {{ $compareLabel }}
        @endif
    </span>

    @if ($showCustomPicker)
        <div class="flex w-full flex-wrap items-end gap-2 rounded-lg bg-white p-2 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 sm:w-auto"
            role="group"
            aria-label="Custom date range">
            <label class="flex flex-col gap-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                Start
                <input type="date"
                    wire:model="draftPeriodStart"
                    min="{{ $minDate }}"
                    max="{{ $maxDate }}"
                    class="rounded-md border-0 bg-gray-50 px-2 py-1.5 text-xs text-gray-800 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:text-gray-200 dark:ring-gray-600" />
            </label>
            <label class="flex flex-col gap-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                End
                <input type="date"
                    wire:model="draftPeriodEnd"
                    min="{{ $minDate }}"
                    max="{{ $maxDate }}"
                    class="rounded-md border-0 bg-gray-50 px-2 py-1.5 text-xs text-gray-800 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:text-gray-200 dark:ring-gray-600" />
            </label>
            <div class="flex items-center gap-1.5">
                <button type="button" wire:click="cancelCustomPeriod"
                    class="rounded-md px-2.5 py-1.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-white/5">
                    Cancel
                </button>
                <button type="button" wire:click="applyCustomPeriod"
                    class="rounded-md bg-brand-500 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-brand-600"
                    wire:loading.attr="disabled">
                    Apply
                </button>
            </div>
            @if ($customPeriodError)
                <p class="w-full text-xs text-rose-600 dark:text-rose-400" role="alert">{{ $customPeriodError }}</p>
            @endif
        </div>
    @endif
</div>
