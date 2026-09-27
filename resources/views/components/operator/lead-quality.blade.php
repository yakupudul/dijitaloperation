@props(['quality', 'days' => 30])
@php
    $tl = fn (?float $v): string => $v === null ? '—' : number_format($v, 0, ',', '.').' ₺';
    $labels = \App\Models\LeadOutcome::STATUSES;
@endphp
<div {{ $attributes->merge(['class' => 'space-y-3']) }}>
    <div class="grid gap-3 sm:grid-cols-4">
        <div>
            <p class="text-xs text-gray-500">Lead ({{ $days }} gün)</p>
            <p class="text-xl font-semibold text-gray-900 dark:text-white">{{ number_format($quality['total'], 0, ',', '.') }}</p>
            <p class="text-xs text-gray-500">{{ $quality['marked'] }} işaretli · {{ $quality['unmarked'] }} sonuç bekliyor</p>
        </div>
        <div>
            <p class="text-xs text-gray-500">Nitelikli oran</p>
            <p class="text-xl font-semibold text-gray-900 dark:text-white">{{ $quality['qualified_rate'] !== null ? '%'.number_format($quality['qualified_rate'], 1, ',', '.') : '—' }}</p>
            <p class="text-xs text-gray-500">randevu + satış / işaretli lead</p>
        </div>
        <div>
            <p class="text-xs text-gray-500">Nitelikli lead başı maliyet</p>
            <p class="text-xl font-semibold text-gray-900 dark:text-white">{{ $tl($quality['cost_per_qualified']) }}</p>
            <p class="text-xs text-gray-500">Reklam harcaması {{ $tl($quality['spend']) }}@if ($quality['cost_per_lead'] !== null) · lead başı {{ $tl($quality['cost_per_lead']) }}@endif</p>
        </div>
        <div>
            <p class="text-xs text-gray-500">Bildirilen satış değeri</p>
            <p class="text-xl font-semibold text-gray-900 dark:text-white">{{ $quality['value'] > 0 ? $tl($quality['value']) : '—' }}</p>
        </div>
    </div>
    <div class="flex flex-wrap gap-2 text-xs">
        @foreach ($quality['by_status'] as $key => $count)
            @if ($count > 0)<span class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-700 dark:bg-white/5 dark:text-gray-300">{{ $labels[$key] ?? $key }}: {{ $count }}</span>@endif
        @endforeach
    </div>
    <p class="text-xs text-gray-400">Harcama: aynı günlerdeki Google Ads + Meta reklam harcaması (hesap para birimi). Sonuçlar kliniğin bildirdiği kadardır; CRM değildir.</p>
</div>
