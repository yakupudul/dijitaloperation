@php
    $number = static fn ($value, int $decimals = 0): string => $value === null ? '—' : number_format((float) $value, $decimals, ',', '.');
    $delta = static fn ($value, int $decimals = 0): string => $value === null ? '—' : (((float) $value > 0 ? '+' : '').number_format((float) $value, $decimals, ',', '.'));
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-600">Arama talebi</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Sorgu–URL Görünürlük Haritası</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">Website’te etkinleştirilmiş marka sorgularını gözlemlenen GSC sorgu–URL ilişkileriyle, URL kimliği/HTML durumu ve GA4 landing performansıyla birleştirir.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('operator.library.brand-query-portfolios', ['brand' => $website?->brand_id]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Marka portföyü</a>
            <a href="{{ route('operator.library.search-demand-clusters', ['brand' => $website?->brand_id]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Sorgu kümeleri</a>
            <a href="{{ route('operator.library.search-demand-enrichment', ['website' => $website?->id]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">SERP zenginleştirme</a>
        </div>
    </div>

    <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="grid gap-3 lg:grid-cols-5">
            <label class="block lg:col-span-2"><span class="mb-1 block text-xs font-medium text-gray-500">Website</span><select wire:model.live="selectedWebsiteId" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Website seçin</option>@foreach($websites as $websiteOption)<option value="{{ $websiteOption->id }}">{{ $websiteOption->name }} · {{ $websiteOption->brand?->name }}</option>@endforeach</select></label>
            <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Dönem başlangıcı</span><input wire:model.live.debounce.400ms="periodStart" type="date" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" /></label>
            <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Dönem sonu</span><input wire:model.live.debounce.400ms="periodEnd" type="date" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" /></label>
            <div class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-500 dark:bg-white/[0.03]"><span class="block font-medium text-gray-700 dark:text-gray-200">Güncel dönem</span>{{ data_get($result, 'period.start', '—') }} → {{ data_get($result, 'period.end', '—') }}</div>
            <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Karşılaştırma başlangıcı</span><input wire:model.live.debounce.400ms="comparisonStart" type="date" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" /></label>
            <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Karşılaştırma sonu</span><input wire:model.live.debounce.400ms="comparisonEnd" type="date" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" /></label>
            <select wire:model.live="clusterId" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Tüm kümeler</option>@foreach($clusters as $cluster)<option value="{{ $cluster->id }}">{{ $cluster->name }}</option>@endforeach</select>
            <select wire:model.live="serviceId" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Tüm hizmetler</option>@foreach($services as $service)<option value="{{ $service->id }}">{{ $service->primaryName?->raw_label ?: '#'.$service->id }}</option>@endforeach</select>
            <select wire:model.live="areaId" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Tüm bölgeler</option>@foreach($areas as $area)<option value="{{ $area->id }}">{{ $area->label() }}</option>@endforeach</select>
            <select wire:model.live="observation" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="all">Gözlemlenen + gözlemlenmeyen</option><option value="observed">Yalnız gözlemlenen</option><option value="unobserved">Yalnız gözlemlenmeyen</option></select>
            <input wire:model.live.debounce.350ms="search" type="search" placeholder="Sorgu ara" class="rounded-lg border-gray-300 text-sm lg:col-span-2 dark:border-gray-700 dark:bg-gray-950" />
        </div>
        <p class="mt-3 text-xs text-gray-400">“Gözlemlenmeyen”, seçili dönemde GSC sorgu–URL satırı bulunmadığını söyler; sıfır gösterim anlamına gelmez. Karşılaştırma yalnız iki dönemde de bulunan değerlerde hesaplanır.</p>
    </section>

    @if ($website)
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
            @foreach ([['Website sorgusu', data_get($result, 'summary.portfolio_queries')], ['Gözlemlenen sorgu', data_get($result, 'summary.observed_queries')], ['Gözlemlenmeyen', data_get($result, 'summary.unobserved_queries')], ['Sorgu–URL çifti', data_get($result, 'summary.observed_query_url_pairs')], ['GSC tıklama', data_get($result, 'summary.clicks')], ['GSC gösterim', data_get($result, 'summary.impressions')]] as [$label, $value])
                <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><p class="text-xs text-gray-500">{{ $label }}</p><p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $number($value) }}</p></div>
            @endforeach
        </div>

        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Kaynak kapsamı</h2>
            <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                @foreach(['gsc' => 'GSC sorgu–URL', 'ga4' => 'GA4 landing', 'website' => 'Website URL / HTML', 'dataforseo' => 'DataForSEO SERP / tahmin'] as $source => $label)
                    @php($coverage = data_get($result, 'coverage.'.$source, []))
                    <div class="rounded-lg border border-gray-200 p-3 text-xs dark:border-gray-700"><div class="flex items-center justify-between gap-2"><span class="font-medium text-gray-800 dark:text-gray-200">{{ $label }}</span><span class="rounded px-1.5 py-0.5 {{ data_get($coverage, 'state') === 'available' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">{{ data_get($coverage, 'state', 'unavailable') }}</span></div><p class="mt-2 text-gray-400">{{ data_get($coverage, 'source', '—') }}{{ data_get($coverage, 'reason') ? ' · '.data_get($coverage, 'reason') : '' }}</p></div>
                @endforeach
            </div>
        </section>

        @if ($queryDetailRows->isNotEmpty() || $pageDetailRows->isNotEmpty())
            <section class="rounded-xl border border-brand-200 bg-brand-50/40 p-5 dark:border-brand-500/30 dark:bg-brand-500/5">
                <div class="flex items-center justify-between gap-3"><h2 class="text-base font-semibold text-gray-900 dark:text-white">Detay</h2><button wire:click="closeDetails" type="button" class="text-sm text-gray-500">Kapat</button></div>
                @if($queryDetailRows->isNotEmpty())
                    @php($queryRow = $queryDetailRows->first())
                    <div class="mt-3"><p class="font-medium text-gray-900 dark:text-white">Sorgu: {{ $queryRow['query'] }}</p><p class="mt-1 text-xs text-gray-500">{{ data_get($queryRow, 'cluster.name', 'Kümelenmemiş') }} · {{ collect($queryRow['services'])->pluck('name')->filter()->implode(' · ') ?: 'Hizmet yok' }} · {{ $queryDetailRows->where('observed', true)->count() }} güncel URL ilişkisi · DataForSEO hacim {{ $number(data_get($queryRow, 'enrichment.search_volume')) }} (tahmin) · marka sırası {{ data_get($queryRow, 'enrichment.brand_rank') ? '#'.data_get($queryRow, 'enrichment.brand_rank') : '—' }}</p></div>
                @endif
                @if($pageDetailRows->isNotEmpty())
                    @php($pageRow = $pageDetailRows->first())
                    <div class="mt-3"><p class="break-all font-medium text-gray-900 dark:text-white">URL: {{ data_get($pageRow, 'page.preferred_url', $pageRow['url']) }}</p><p class="mt-1 text-xs text-gray-500">HTTP {{ data_get($pageRow, 'page.http_status', '—') }} · {{ data_get($pageRow, 'page.indexability', 'unknown') }} · HTML {{ data_get($pageRow, 'page.html_observed') ? 'gözlemlendi' : 'gözlemlenmedi' }} · {{ $pageDetailRows->pluck('portfolio_item_id')->unique()->count() }} sorgu</p></div>
                @endif
            </section>
        @endif

        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800"><h2 class="text-base font-semibold text-gray-900 dark:text-white">Görünürlük matrisi</h2><p class="mt-1 text-sm text-gray-500">GSC değerleri sorgu–URL grain’indedir; GA4 değerleri aynı URL’nin landing-page grain’idir ve sorguya atfedilmiş değildir.</p></div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-xs">
                    <thead class="bg-gray-50 text-left text-gray-500 dark:bg-white/[0.02]"><tr><th class="px-4 py-3">Sorgu / küme</th><th class="px-4 py-3">URL / durum</th><th class="px-3 py-3 text-right">Tıklama</th><th class="px-3 py-3 text-right">Gösterim</th><th class="px-3 py-3 text-right">CTR</th><th class="px-3 py-3 text-right">Pozisyon</th><th class="px-3 py-3 text-right">GA4 oturum</th><th class="px-3 py-3 text-right">DFS hacim</th><th class="px-3 py-3 text-right">DFS marka</th><th class="px-4 py-3">Kaynak</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse($result['rows'] as $row)
                            <tr class="align-top hover:bg-gray-50/60 dark:hover:bg-white/[0.02]">
                                <td class="max-w-sm px-4 py-3"><button wire:click="showQuery({{ $row['portfolio_item_id'] }})" type="button" class="text-left font-medium text-gray-900 hover:text-brand-600 dark:text-white">{{ $row['query'] }}</button><div class="mt-1 text-[10px] text-gray-400">{{ data_get($row, 'cluster.name', 'Kümelenmemiş') }} · {{ $row['demand_family'] ?: 'talep ailesi yok' }}</div></td>
                                <td class="max-w-md px-4 py-3">
                                    @if($row['url'])<div class="break-all text-gray-700 dark:text-gray-200">{{ $row['url'] }}</div>@else<span class="text-gray-400">Seçili dönemde URL gözlemi yok</span>@endif
                                    <div class="mt-1 flex flex-wrap gap-1">@if($row['page_profile_id'])<button wire:click="showPage({{ $row['page_profile_id'] }})" type="button" class="rounded bg-sky-50 px-1.5 py-0.5 text-[10px] text-sky-700">URL kimliği #{{ $row['page_profile_id'] }}</button>@else<span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500">kimlik çözülmedi</span>@endif<span class="rounded px-1.5 py-0.5 text-[10px] {{ $row['observed'] ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">{{ $row['observed'] ? 'gözlemlendi' : 'gözlemlenmedi' }}</span><span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-600">{{ data_get($row, 'page.indexability', 'unknown') }}</span></div>
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums"><div>{{ $number(data_get($row, 'current.clicks')) }}</div><div class="mt-1 text-[10px] text-gray-400">Δ {{ $delta(data_get($row, 'change.clicks')) }}</div></td>
                                <td class="px-3 py-3 text-right tabular-nums"><div>{{ $number(data_get($row, 'current.impressions')) }}</div><div class="mt-1 text-[10px] text-gray-400">Δ {{ $delta(data_get($row, 'change.impressions')) }}</div></td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $number(data_get($row, 'current.ctr'), 2) }}{{ data_get($row, 'current.ctr') === null ? '' : '%' }}</td>
                                <td class="px-3 py-3 text-right tabular-nums"><div>{{ $number(data_get($row, 'current.average_position'), 2) }}</div><div class="mt-1 text-[10px] text-gray-400">Δ {{ $delta(data_get($row, 'change.average_position'), 2) }}</div></td>
                                <td class="px-3 py-3 text-right tabular-nums"><div>{{ $number(data_get($row, 'current.sessions')) }}</div><div class="mt-1 text-[10px] text-gray-400">Δ {{ $delta(data_get($row, 'change.sessions')) }}</div></td>
                                <td class="px-3 py-3 text-right tabular-nums"><div>{{ $number(data_get($row, 'enrichment.search_volume')) }}</div><div class="mt-1 text-[10px] text-gray-400">provider tahmini</div></td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ data_get($row, 'enrichment.brand_rank') ? '#'.data_get($row, 'enrichment.brand_rank') : '—' }}</td>
                                <td class="px-4 py-3 text-[10px] text-gray-400">{{ collect($row['provenance'])->filter()->implode(' · ') ?: 'Kaynak gözlemi yok' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="px-4 py-10 text-center text-sm text-gray-500">Filtreye uyan website-etkin marka sorgusu yok. Marka portföyünde website etkinliğini kontrol edin.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-gray-100 px-4 py-3 text-xs text-gray-400 dark:border-gray-800">En fazla 500 satır gösterilir. {{ $result['truncated'] ? 'Sonuç kesildi; filtreleri daraltın.' : 'Sonuç kesilmedi.' }} “—” eksik/gözlemlenmemiş değerdir; 0 değildir.</div>
        </section>

        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800"><h2 class="text-base font-semibold text-gray-900 dark:text-white">Küme özeti</h2></div>
            <div class="overflow-x-auto"><table class="min-w-full text-xs"><thead class="bg-gray-50 text-left text-gray-500 dark:bg-white/[0.02]"><tr><th class="px-4 py-3">Küme</th><th class="px-3 py-3 text-right">Sorgu</th><th class="px-3 py-3 text-right">Gözlemlenen</th><th class="px-3 py-3 text-right">URL</th><th class="px-3 py-3 text-right">Tıklama</th><th class="px-4 py-3 text-right">Gösterim</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">@forelse($result['cluster_summary'] as $cluster)<tr><td class="px-4 py-3 font-medium text-gray-800 dark:text-gray-200">{{ $cluster['cluster_name'] }}</td><td class="px-3 py-3 text-right">{{ $number($cluster['query_count']) }}</td><td class="px-3 py-3 text-right">{{ $number($cluster['observed_query_count']) }}</td><td class="px-3 py-3 text-right">{{ $number($cluster['url_count']) }}</td><td class="px-3 py-3 text-right">{{ $number($cluster['clicks']) }}</td><td class="px-4 py-3 text-right">{{ $number($cluster['impressions']) }}</td></tr>@empty<tr><td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">Küme özeti yok.</td></tr>@endforelse</tbody></table></div>
        </section>
    @endif
</div>
