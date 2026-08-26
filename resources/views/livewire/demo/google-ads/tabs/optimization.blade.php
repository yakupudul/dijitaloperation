@php
    $isTr = app()->getLocale() === 'tr';
    $googleRecommendations = collect(data_get($professional, 'optimization.google_recommendations', []));
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Optimizasyon' : 'Optimization' }}</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Google’ın sağlayıcı önerilerini MOXDOP bulgu/öneri/görev zincirinden ayrı değerlendirin.' : 'Evaluate Google provider recommendations separately from MOXDOP findings, recommendations and tasks.' }}</p>
    </div>

    <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
            <div><h3 class="font-semibold text-gray-900 dark:text-white">Google Recommendations</h3><p class="mt-1 text-xs text-gray-500">{{ data_get($professional, 'optimization.boundary') }}</p></div>
            <span class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700 dark:bg-blue-500/10 dark:text-blue-300">{{ $googleRecommendations->count() }} {{ $isTr ? 'öneri' : 'recommendations' }}</span>
        </div>
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($googleRecommendations as $row)
                @php
                    $m = data_get($row, 'metadata', []);
                    $m = is_array($m) ? $m : [];
                    $recommendationType = data_get($row, 'recommendation_type', 'Google recommendation');
                    $campaignResource = data_get($row, 'campaign_resource_name') ?: ($isTr ? 'Hesap düzeyi' : 'Account level');
                    $observedDate = data_get($row, 'observed_date', '—');
                @endphp
                <div class="grid gap-2 px-4 py-3 md:grid-cols-[1fr_auto] md:items-start">
                    <div><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $recommendationType }}</p><p class="mt-1 text-xs text-gray-500">{{ $campaignResource }} · {{ $observedDate }}</p>@if($m)<p class="mt-2 text-xs text-gray-500">{{ collect($m)->except(['provider','api_version','collector_layer','provider_fact','derived_rates_stored'])->map(fn($v,$k) => $k.': '.(is_scalar($v)?$v:json_encode($v)))->take(4)->implode(' · ') }}</p>@endif</div>
                    <span class="rounded-full bg-gray-100 px-2 py-1 text-[11px] font-medium text-gray-600 dark:bg-white/5 dark:text-gray-300">Google</span>
                </div>
            @empty
                <div class="px-4 py-8 text-center text-sm text-gray-400">{{ $isTr ? 'Google tarafından dönen aktif recommendation snapshotı yok.' : 'No active Google recommendation snapshot is available.' }}</div>
            @endforelse
        </div>
    </section>

    <div class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20">
        <strong>{{ $isTr ? 'Karar sınırı:' : 'Decision boundary:' }}</strong>
        {{ $isTr ? 'Google’ın bütçe artırma veya teklif değiştirme önerisi MOXDOP tarafından otomatik onaylanmaz. Önce provider verisi, iş hedefi ve mevcut bulgularla değerlendirilir.' : 'Google budget or bidding suggestions are never auto-approved by MOXDOP. They are evaluated against provider facts, business goals and existing findings first.' }}
    </div>

    @include('livewire.demo.google-ads.tabs.operations')
</div>
