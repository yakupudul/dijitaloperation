@php
    $presets = [
        'last_7' => __('operator.period.presets.last_7'),
        'last_14' => __('operator.period.presets.last_14'),
        'last_28' => __('operator.period.presets.last_28'),
        'last_30' => __('operator.period.presets.last_30'),
        'last_90' => __('operator.period.presets.last_90'),
        'this_month' => __('operator.period.presets.this_month'),
        'last_month' => __('operator.period.presets.last_month'),
    ];
    $compareOn = (bool) ($compare ?? true);
    $supportsYoY = method_exists($this, 'supportsYearOverYearComparison') && $this->supportsYearOverYearComparison();
    $effectiveCompareMode = method_exists($this, 'effectiveCompareMode') ? $this->effectiveCompareMode() : 'previous';
    $appliedLabel = method_exists($this, 'appliedPeriodLabel') ? $this->appliedPeriodLabel() : ($period === 'custom' && $periodStart && $periodEnd ? $periodStart.' – '.$periodEnd : ($presets[$period] ?? __('operator.period.custom')));

    if (app()->getLocale() === 'tr' && filled($periodStart ?? null) && filled($periodEnd ?? null)) {
        $periodStartDate = \Carbon\CarbonImmutable::parse($periodStart);
        $periodEndDate = \Carbon\CarbonImmutable::parse($periodEnd);
        $appliedLabel = $periodStartDate->locale('tr')->translatedFormat('j M').' – '.$periodEndDate->locale('tr')->translatedFormat('j M');
    }

    $compareLabel = method_exists($this, 'comparePeriodLabel') ? $this->comparePeriodLabel() : null;
    if ($compareOn && app()->getLocale() === 'tr' && filled($periodStart ?? null) && filled($periodEnd ?? null)) {
        $currentStart = \Carbon\CarbonImmutable::parse($periodStart)->startOfDay();
        $currentEnd = \Carbon\CarbonImmutable::parse($periodEnd)->startOfDay();
        $days = max(1, $currentStart->diffInDays($currentEnd) + 1);

        if ($effectiveCompareMode === 'yoy') {
            $compareStart = $currentStart->subYearNoOverflow();
            $compareEnd = $compareStart->addDays($days - 1);
        } else {
            $compareEnd = $currentStart->subDay();
            $compareStart = $compareEnd->subDays($days - 1);
        }

        $compareLabel = $compareStart->locale('tr')->translatedFormat('j M').' – '.$compareEnd->locale('tr')->translatedFormat('j M');
    }

    $maxDate = method_exists($this, 'periodPickerMaxDate')
        ? $this->periodPickerMaxDate()
        : (method_exists($this, 'periodAnchorDate')
            ? $this->periodAnchorDate()
            : \App\Support\Demo\DemoPeriod::ANCHOR_DATE);
    $minDate = method_exists($this, 'periodPickerMinDate')
        ? $this->periodPickerMinDate()
        : \Carbon\Carbon::parse($maxDate)->subDays(89)->toDateString();
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
        {{ __('operator.period.custom') }}
    </button>

    <button type="button" wire:click="toggleCompare"
        @class([
            'rounded-md px-2.5 py-1.5 text-xs font-medium transition',
            'bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-200 dark:bg-brand-500/15 dark:text-brand-400 dark:ring-brand-500/30' => $compareOn,
            'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => ! $compareOn,
        ])
        title="{{ __('operator.period.compare') }}">
        {{ $compareOn ? __('operator.period.compare_on') : __('operator.period.compare') }}
    </button>

    @if ($compareOn && $supportsYoY)
        <button type="button" wire:click="setCompareMode('previous')"
            @class([
                'rounded-md px-2.5 py-1.5 text-xs font-medium transition',
                'bg-brand-500 text-white' => $effectiveCompareMode !== 'yoy',
                'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $effectiveCompareMode === 'yoy',
            ])>
            {{ __('operator.period.compare_previous') }}
        </button>
        <button type="button" wire:click="setCompareMode('yoy')"
            @class([
                'rounded-md px-2.5 py-1.5 text-xs font-medium transition',
                'bg-brand-500 text-white' => $effectiveCompareMode === 'yoy',
                'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $effectiveCompareMode !== 'yoy',
            ])>
            {{ __('operator.period.compare_yoy') }}
        </button>
    @endif

    <span class="ml-1 text-xs text-gray-500 dark:text-gray-400" data-applied-range>
        {{ $appliedLabel }}
        @if ($compareOn && $compareLabel)
            <span class="text-gray-400">{{ __('operator.period.vs') }}</span> {{ $compareLabel }}
        @endif
    </span>

    @if ($showCustomPicker)
        <div class="flex w-full flex-wrap items-end gap-2 rounded-lg bg-white p-2 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 sm:w-auto"
            role="group"
            aria-label="{{ __('operator.period.custom_range') }}">
            <label class="flex flex-col gap-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                {{ __('operator.period.start') }}
                <input type="date"
                    wire:model="draftPeriodStart"
                    min="{{ $minDate }}"
                    max="{{ $maxDate }}"
                    class="rounded-md border-0 bg-gray-50 px-2 py-1.5 text-xs text-gray-800 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:text-gray-200 dark:ring-gray-600" />
            </label>
            <label class="flex flex-col gap-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                {{ __('operator.period.end') }}
                <input type="date"
                    wire:model="draftPeriodEnd"
                    min="{{ $minDate }}"
                    max="{{ $maxDate }}"
                    class="rounded-md border-0 bg-gray-50 px-2 py-1.5 text-xs text-gray-800 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:text-gray-200 dark:ring-gray-600" />
            </label>
            <div class="flex items-center gap-1.5">
                <button type="button" wire:click="cancelCustomPeriod"
                    class="rounded-md px-2.5 py-1.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-white/5">
                    {{ __('operator.period.cancel') }}
                </button>
                <button type="button" wire:click="applyCustomPeriod"
                    class="rounded-md bg-brand-500 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-brand-600"
                    wire:loading.attr="disabled">
                    {{ __('operator.period.apply') }}
                </button>
            </div>
            @if ($customPeriodError)
                <p class="w-full text-xs text-rose-600 dark:text-rose-400" role="alert">{{ $customPeriodError }}</p>
            @endif
        </div>
    @endif
</div>
