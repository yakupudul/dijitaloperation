@php
    $isTr = app()->getLocale() === 'tr';
    $rawRecommendations = data_get($professional, 'optimization.google_recommendations', []);
    $googleRecommendations = collect(is_iterable($rawRecommendations) ? $rawRecommendations : []);
    $boundary = (string) (data_get($professional, 'optimization.boundary') ?? '');
@endphp

<div class="space-y-5">
    <div class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-600 dark:text-brand-400">{{ $isTr ? 'OPTİMİZASYON MERKEZİ' : 'OPTIMIZATION CENTER' }}</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Ne değiştirilmeli, ne sadece izlenmeli?' : 'What should change, and what should only be monitored?' }}</h2>
            <p class="mt-1 max-w-4xl text-sm text-gray-500 dark:text-gray-400">
                {{ $isTr
                    ? 'Google’ın provider önerilerini MOXDOP karar zincirinden ayrı tutun. Bu ekran Google Ads hesabında otomatik değişiklik yapmaz.'
                    : 'Keep Google provider suggestions separate from the MOXDOP decision chain. This screen never makes automatic changes in Google Ads.' }}
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ url('/findings') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">{{ $isTr ? 'Bulgular' : 'Findings' }}</a>
            <a href="{{ url('/recommendations') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">{{ $isTr ? 'Öneriler' : 'Recommendations' }}</a>
            <a href="{{ url('/tasks') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-semibold text-brand-600 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:text-brand-400 dark:ring-brand-800 dark:hover:bg-brand-500/10">{{ $isTr ? 'İşler' : 'Tasks' }}</a>
        </div>
    </div>

    <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-4 py-4 dark:border-gray-800">
            <div>
                <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Google provider önerileri' : 'Google provider recommendations' }}</h3>
                @if ($boundary !== '')
                    <p class="mt-1 max-w-3xl text-xs text-gray-500 dark:text-gray-400">{{ $boundary }}</p>
                @endif
            </div>
            <span class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700 dark:bg-blue-500/10 dark:text-blue-300">
                {{ $googleRecommendations->count() }} {{ $isTr ? 'öneri' : 'recommendations' }}
            </span>
        </div>

        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($googleRecommendations as $row)
                @php
                    $rowData = is_array($row) ? $row : (is_object($row) ? (array) $row : []);
                    $recommendationType = $rowData['recommendation_type'] ?? null;
                    $campaignResource = $rowData['campaign_resource_name'] ?? null;
                    $observedDate = $rowData['observed_date'] ?? null;
                    $metadata = $rowData['metadata'] ?? [];
                    $metadata = is_array($metadata) ? $metadata : [];

                    $recommendationType = is_scalar($recommendationType) && (string) $recommendationType !== ''
                        ? (string) $recommendationType
                        : ($isTr ? 'Google önerisi' : 'Google recommendation');
                    $campaignResource = is_scalar($campaignResource) && (string) $campaignResource !== ''
                        ? (string) $campaignResource
                        : ($isTr ? 'Hesap düzeyi' : 'Account level');
                    $observedDate = is_scalar($observedDate) && (string) $observedDate !== ''
                        ? (string) $observedDate
                        : '—';

                    $metadataText = collect($metadata)
                        ->except(['provider', 'api_version', 'collector_layer', 'provider_fact', 'derived_rates_stored'])
                        ->map(function ($value, $key) {
                            if (is_scalar($value) || $value === null) {
                                $rendered = $value === null ? '—' : (string) $value;
                            } else {
                                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                $rendered = $encoded === false ? '—' : $encoded;
                            }

                            return (string) $key.': '.$rendered;
                        })
                        ->take(4)
                        ->implode(' · ');
                @endphp

                <div class="grid gap-3 px-4 py-4 md:grid-cols-[1fr_auto] md:items-start">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $recommendationType }}</p>
                        <p class="mt-1 break-all text-xs text-gray-500 dark:text-gray-400">{{ $campaignResource }} · {{ $observedDate }}</p>
                        @if ($metadataText !== '')
                            <p class="mt-2 break-words text-xs text-gray-500 dark:text-gray-400">{{ $metadataText }}</p>
                        @endif
                    </div>
                    <span class="rounded-full bg-gray-100 px-2 py-1 text-[11px] font-medium text-gray-600 dark:bg-white/5 dark:text-gray-300">Google</span>
                </div>
            @empty
                <div class="px-6 py-10 text-center">
                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Aktif Google recommendation snapshotı yok' : 'No active Google recommendation snapshot' }}</h4>
                    <p class="mx-auto mt-2 max-w-2xl text-sm text-gray-500 dark:text-gray-400">
                        {{ $isTr
                            ? 'Bu durum sıfır öneri olduğu anlamına gelebilir veya recommendation datasetinin henüz materialize edilmemiş olabileceğini gösterebilir. MOXDOP burada öneri uydurmaz.'
                            : 'This can mean there are no current provider recommendations or that the recommendation dataset has not been materialized yet. MOXDOP does not fabricate suggestions here.' }}
                    </p>
                </div>
            @endforelse
        </div>
    </section>

    <section class="grid gap-3 lg:grid-cols-3">
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">1 · {{ $isTr ? 'Gözlemle' : 'Observe' }}</p>
            <h3 class="mt-2 font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Provider gerçeği' : 'Provider truth' }}</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Google recommendation yalnızca Google’ın önerisidir; MOXDOP bulgusu değildir.' : 'A Google recommendation is only a provider suggestion; it is not a MOXDOP finding.' }}</p>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">2 · {{ $isTr ? 'Teşhis et' : 'Diagnose' }}</p>
            <h3 class="mt-2 font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'MOXDOP kararı' : 'MOXDOP decision' }}</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'İş hedefi ve kanıt yeterliyse Bulgu → Öneri zincirine taşınır.' : 'When business context and evidence are sufficient, the issue moves into the Finding → Recommendation chain.' }}</p>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">3 · {{ $isTr ? 'Uygula' : 'Execute' }}</p>
            <h3 class="mt-2 font-semibold text-gray-900 dark:text-white">Google Ads</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Değişiklik Google Ads tarafında uygulanır; MOXDOP şimdilik gözlemler, teşhis eder ve işi yönetir.' : 'The change is executed in Google Ads; MOXDOP currently observes, diagnoses and manages the work.' }}</p>
        </div>
    </section>

    <div class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20">
        <strong>{{ $isTr ? 'Karar sınırı:' : 'Decision boundary:' }}</strong>
        {{ $isTr
            ? 'Bütçe artırma, teklif stratejisi değiştirme veya başka bir Google Ads mutasyonu bu ekrandan otomatik uygulanmaz.'
            : 'Budget increases, bidding-strategy changes and other Google Ads mutations are never auto-applied from this screen.' }}
    </div>
</div>
