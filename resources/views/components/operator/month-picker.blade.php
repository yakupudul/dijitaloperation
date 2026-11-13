@props([
    /** The month shown (CarbonInterface or Y-m). */
    'month',
    /** Livewire property that holds the month (Y-m; empty = this month). */
    'property' => 'month',
    /** false: later months than this one cannot be chosen (reports that only look back). */
    'future' => true,
    /** Small line above the month in the button. */
    'caption' => 'Ay',
])

{{--
    The date picker's month form (yakup, 2026-11-13: "sistemde tarih filtresi olan her yere bu modeli kur"): screens that
    count by calendar month (Finans, Pazarlama, Satış ay raporu, Takvim) keep their ‹ › steps, and the month in between
    opens the same panel as x-operator.date-range-picker: quick choices on the left, the year's twelve months on the
    right. Choosing a month sets the screen's month property at once (this month = empty, like the arrows do).
--}}
@php
    $tz = 'Europe/Istanbul';
    $shown = $month instanceof \Carbon\CarbonInterface ? \Carbon\CarbonImmutable::instance($month) : \Carbon\CarbonImmutable::parse(((string) $month ?: now($tz)->format('Y-m')).'-01', $tz);
    $shownKey = $shown->format('Y-m');
    $thisMonth = \Carbon\CarbonImmutable::now($tz)->startOfMonth();
    $names = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    $quick = [[$thisMonth, 'Bu ay'], [$thisMonth->subMonthNoOverflow(), 'Geçen ay']];
    if ($future) {
        $quick[] = [$thisMonth->addMonthNoOverflow(), 'Gelecek ay'];
    }
@endphp
{{-- The key changes with the month: the arrows and other screen parts change the month on the server, and a new key makes
     Livewire put a fresh picker in place instead of keeping the old one's chosen month (2026-11-13: "Bu ay" did nothing after ‹). --}}
<div {{ $attributes->merge(['class' => 'relative']) }} data-month-picker wire:key="month-picker-{{ $property }}-{{ $shownKey }}"
     x-data="monthPicker({ month: '{{ $shownKey }}', current: '{{ $thisMonth->format('Y-m') }}', future: {{ $future ? 'true' : 'false' }}, property: '{{ $property }}' })"
     @keydown.escape.window="open = false" @click.outside="open = false">
    <button type="button" @click="open = !open" :aria-expanded="open.toString()" aria-haspopup="dialog"
            class="flex h-11 items-center gap-3 rounded-lg border border-gray-200 bg-white px-3 text-left hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-gray-800">
        <svg class="h-4 w-4 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
        <span class="flex flex-col leading-tight">
            <span class="text-xs text-gray-500">{{ $caption === 'Ay' && $shownKey === $thisMonth->format('Y-m') ? 'Bu ay' : $caption }}</span>
            <span class="whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white" data-month-label>{{ $names[$shown->month - 1] }} {{ $shown->year }}</span>
        </span>
        <svg class="h-3.5 w-3.5 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
    </button>

    <div x-show="open" x-cloak x-transition.opacity role="dialog" aria-label="Ay seç"
         class="absolute right-0 z-40 mt-2 grid w-[min(460px,92vw)] grid-cols-1 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xl sm:grid-cols-[150px_minmax(0,1fr)] dark:border-gray-700 dark:bg-gray-900">
        <div class="flex flex-row flex-wrap gap-1 border-b border-gray-200 p-2 sm:flex-col sm:border-b-0 sm:border-r dark:border-gray-700">
            @foreach ($quick as [$q, $qLabel])
                <button type="button" @click="choose('{{ $q->format('Y-m') }}')" :class="month === '{{ $q->format('Y-m') }}' ? 'bg-gray-50 font-semibold text-gray-700 dark:bg-gray-500/10 dark:text-gray-300' : 'text-gray-700 dark:text-gray-300'"
                        class="h-9 rounded-lg px-3 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800">{{ $qLabel }}</button>
            @endforeach
        </div>
        <div class="space-y-3 p-4">
            <div class="flex items-center justify-between">
                <button type="button" @click="year--" class="btn-quiet h-8 px-2" aria-label="Önceki yıl">‹</button>
                <p class="text-sm font-semibold text-gray-900 dark:text-white" x-text="year"></p>
                <button type="button" @click="year++" :disabled="!future && year >= Number(current.slice(0, 4))" class="btn-quiet h-8 px-2 disabled:opacity-30" aria-label="Sonraki yıl">›</button>
            </div>
            <div class="grid grid-cols-3 gap-1.5">
                @foreach ($names as $i => $name)
                    <button type="button" @click="choose(key({{ $i + 1 }}))" :disabled="locked({{ $i + 1 }})" :class="cellClass({{ $i + 1 }})" class="h-9 rounded-lg text-sm">{{ $name }}</button>
                @endforeach
            </div>
        </div>
    </div>
</div>
