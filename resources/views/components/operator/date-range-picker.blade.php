@props([
    /** \App\Services\Site\Analysis\SiteRange of the screen */
    'range',
    /** Last data day (Y-m-d): presets end here and later days are disabled. */
    'lastDay',
    /** One line under the calendar, e.g. how late the source's data arrives. */
    'note' => null,
])

{{--
    The one date picker of every digital asset screen (Web sitesi, Search Console, Analytics, Google Ads, Meta, İşletme
    Profili): presets, two-month calendar, custom range, comparison. "Uygula" calls the screen's setRange(days, start,
    end, compare).
--}}
@php
    $presets = \App\Services\Site\Analysis\SiteRange::PRESETS;
    $window = $range->window(\Carbon\CarbonImmutable::parse($lastDay));
    $rangeLabel = \App\Services\Site\Analysis\SiteRange::format($window['start'], $window['end']);
    $compareLabel = \App\Services\Site\Analysis\SiteRange::format($window['prev_start'], $window['prev_end']);
@endphp
<div {{ $attributes->merge(['class' => 'relative']) }} data-date-picker
     x-data="sitePicker({ days: {{ $range->custom() ? 0 : $range->days }}, start: '{{ $window['start'] }}', end: '{{ $window['end'] }}', compare: '{{ $range->compare }}', last: '{{ $lastDay }}' })"
     @keydown.escape.window="open = false" @click.outside="open = false">
    <button type="button" @click="open = !open" :aria-expanded="open.toString()" aria-haspopup="dialog"
            class="flex h-11 items-center gap-3 rounded-lg border border-gray-300 bg-white px-3 text-left hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-gray-800">
        <svg class="h-4 w-4 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
        <span class="flex flex-col leading-tight">
            <span class="text-[11px] text-gray-500">{{ $range->label() }}</span>
            <span class="text-sm font-semibold text-gray-900 dark:text-white" data-range-label>{{ $rangeLabel }}</span>
        </span>
        <span class="hidden border-l border-gray-200 pl-3 text-xs text-gray-500 sm:inline dark:border-gray-700">karşılaştırma: {{ $compareLabel }}</span>
        <svg class="h-3.5 w-3.5 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
    </button>

    <div x-show="open" x-cloak x-transition.opacity role="dialog" aria-label="Tarih aralığı"
         class="absolute right-0 z-40 mt-2 grid w-[min(760px,92vw)] grid-cols-1 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xl sm:grid-cols-[190px_minmax(0,1fr)] dark:border-gray-700 dark:bg-gray-900">
        <div class="flex flex-row flex-wrap gap-1 border-b border-gray-200 p-2 sm:flex-col sm:border-b-0 sm:border-r dark:border-gray-700">
            @foreach ($presets as $presetDays => $presetLabel)
                <button type="button" @click="preset({{ $presetDays }})" :class="days === {{ $presetDays }} ? 'bg-blue-50 font-semibold text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' : 'text-gray-700 dark:text-gray-300'"
                        class="h-9 rounded-lg px-3 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800">{{ $presetLabel }}</button>
            @endforeach
            <button type="button" @click="lastMonth()" :class="days === -1 ? 'bg-blue-50 font-semibold text-blue-700' : 'text-gray-700 dark:text-gray-300'" class="h-9 rounded-lg px-3 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800">Geçen ay</button>
            <button type="button" @click="custom()" :class="days === 0 ? 'bg-blue-50 font-semibold text-blue-700' : 'text-gray-700 dark:text-gray-300'" class="h-9 rounded-lg px-3 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800">Özel aralık</button>
        </div>
        <div class="space-y-3 p-4">
            <div class="flex items-end gap-2">
                <label class="flex-1 text-[11px] text-gray-500">Başlangıç
                    <input type="date" x-model="start" @change="days = 0" :max="end" class="mt-1 block h-9 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
                </label>
                <span class="pb-2 text-gray-400">–</span>
                <label class="flex-1 text-[11px] text-gray-500">Bitiş
                    <input type="date" x-model="end" @change="days = 0" :min="start" :max="last" class="mt-1 block h-9 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
                </label>
            </div>
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <template x-for="month in months()" :key="month.key">
                    <div>
                        <p class="mb-2 text-center text-xs font-semibold text-gray-800 dark:text-gray-100" x-text="month.title"></p>
                        <div class="grid grid-cols-7 text-center text-[11px] text-gray-500">
                            <template x-for="w in ['Pt','Sa','Ça','Pe','Cu','Ct','Pz']" :key="w"><span class="pb-1" x-text="w"></span></template>
                            <template x-for="cell in month.cells" :key="cell.key">
                                <button type="button" :disabled="!cell.day || cell.future" @click="pick(cell.iso)" x-text="cell.day || ''"
                                        :class="cellClass(cell)" class="h-8 text-xs"></button>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
            <div class="flex flex-wrap items-center gap-3 border-t border-gray-200 pt-3 text-xs dark:border-gray-700">
                <span class="font-medium text-gray-700 dark:text-gray-300">Karşılaştır:</span>
                <label class="flex items-center gap-1.5"><input type="radio" value="prev" x-model="compare"> Önceki dönem</label>
                <label class="flex items-center gap-1.5"><input type="radio" value="year" x-model="compare"> Geçen yıl aynı dönem</label>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="text-[11px] text-gray-500">@if ($note){{ $note }} @endif Son veri {{ \Carbon\CarbonImmutable::parse($lastDay)->format('d.m.Y') }}.</span>
                <span class="flex gap-2">
                    <button type="button" @click="open = false" class="h-9 rounded-lg border border-gray-300 px-3 text-sm dark:border-gray-700">İptal</button>
                    <button type="button" @click="apply()" class="h-9 rounded-lg bg-brand-500 px-3 text-sm font-semibold text-white hover:bg-brand-600" data-date-apply>Uygula</button>
                </span>
            </div>
        </div>
    </div>
</div>
