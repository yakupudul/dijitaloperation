@php
    $isTr = app()->getLocale() === 'tr';
    $history = $professional['history'] ?? [];
    $health = collect($professional['data_health'] ?? []);
    $devices = collect(data_get($professional, 'performance.device', []));
    $networks = collect(data_get($professional, 'performance.network', []));
    $topDevice = $devices->sortByDesc('cost')->first();
    $topNetwork = $networks->sortByDesc('cost')->first();
    $recommendationCount = count(data_get($professional, 'optimization.google_recommendations', []));
    $changeCount = count($professional['changes'] ?? []);
@endphp

<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
    <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <p class="text-xs font-medium text-gray-400">{{ $isTr ? 'Hesap geçmişi' : 'Account history' }}</p>
        <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ data_get($history, 'active_months', '—') }} {{ $isTr ? 'aktif ay' : 'active months' }}</p>
        <p class="mt-1 text-xs text-gray-500">{{ data_get($history, 'first_activity_month', '—') }} → {{ data_get($history, 'last_activity_month', '—') }}</p>
    </div>
    <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <p class="text-xs font-medium text-gray-400">{{ $isTr ? 'Lifetime harcama' : 'Lifetime spend' }}</p>
        <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ is_numeric(data_get($history, 'lifetime_spend')) ? number_format((float) data_get($history, 'lifetime_spend'), 2, ',', '.') : '—' }} {{ $professional['currency'] ?? '' }}</p>
        <p class="mt-1 text-xs text-gray-500">{{ number_format((int) data_get($history, 'lifetime_clicks', 0), 0, ',', '.') }} {{ $isTr ? 'tıklama' : 'clicks' }}</p>
    </div>
    <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <p class="text-xs font-medium text-gray-400">{{ $isTr ? 'En çok harcayan cihaz' : 'Top spend device' }}</p>
        <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $topDevice['device'] ?? '—' }}</p>
        <p class="mt-1 text-xs text-gray-500">{{ isset($topDevice['cost']) ? number_format((float) $topDevice['cost'], 2, ',', '.').' '.($professional['currency'] ?? '') : '—' }}</p>
    </div>
    <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <p class="text-xs font-medium text-gray-400">{{ $isTr ? 'Google önerileri' : 'Google recommendations' }}</p>
        <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $recommendationCount }}</p>
        <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Sağlayıcı önerisi · otomatik uygulanmaz' : 'Provider suggestions · never auto-applied' }}</p>
    </div>
    <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <p class="text-xs font-medium text-gray-400">{{ $isTr ? 'Veri sağlığı' : 'Data health' }}</p>
        <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $health->where('partial', false)->count() }}/{{ $health->count() ?: '—' }}</p>
        <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'dataset kullanılabilir' : 'datasets available' }} · {{ $changeCount }} {{ $isTr ? 'değişiklik' : 'changes' }}</p>
    </div>
</div>

@if ($topNetwork)
    <div class="mt-3 rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-white/[0.02] dark:text-gray-300 dark:ring-gray-800">
        <span class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Hızlı bağlam:' : 'Quick context:' }}</span>
        {{ $isTr ? 'Seçili dönemde en yüksek harcamalı reklam ağı' : 'Highest-spend ad network in the selected period' }}
        <strong>{{ $topNetwork['ad_network_type'] ?? '—' }}</strong> ·
        {{ number_format((float) ($topNetwork['cost'] ?? 0), 2, ',', '.') }} {{ $professional['currency'] ?? '' }}.
        {{ $isTr ? 'Detay için Performans sekmesini kullanın.' : 'Use Performance for the full breakdown.' }}
    </div>
@endif
