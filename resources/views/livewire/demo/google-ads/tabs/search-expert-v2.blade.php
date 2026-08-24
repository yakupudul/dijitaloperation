@php
    $isTr = app()->getLocale() === 'tr';
    $search = is_array($data['search'] ?? null) ? $data['search'] : [];
    $coverage = is_array($search['coverage'] ?? null) ? $search['coverage'] : [];
    $insights = is_array($search['expert_insights'] ?? null) ? $search['expert_insights'] : [];
    $categories = collect($insights['categories'] ?? []);
    $decisions = collect($insights['decisions'] ?? []);
    $filters = is_array($search['filter_options'] ?? null) ? $search['filter_options'] : [];
    $campaignOptions = collect($filters['campaigns'] ?? []);
    $adGroupOptions = collect($filters['ad_groups'] ?? []);
    if (($search_campaign ?? 'all') !== 'all') {
        $adGroupOptions = $adGroupOptions->filter(fn ($row) => empty($row['campaign_id']) || (string) $row['campaign_id'] === (string) $search_campaign);
    }
    $sourceOptions = collect($filters['sources'] ?? []);
    $allKeywords = collect($search['keywords'] ?? []);
    $currency = (string) (data_get($identity ?? [], 'currency') ?: ($professional['currency'] ?? ''));
    $money = function ($value) use ($currency) {
        if (! is_numeric($value)) return '—';
        return trim(number_format((float) $value, 2, ',', '.').' '.$currency);
    };
    $number = fn ($value, int $digits = 0) => is_numeric($value) ? number_format((float) $value, $digits, ',', '.') : '—';
    $pct = fn ($value, int $digits = 1) => is_numeric($value) ? number_format((float) $value, $digits, ',', '.').'%' : '—';
    $hasFilters = filled($search_query ?? '')
        || ($search_campaign ?? 'all') !== 'all'
        || ($search_ad_group ?? 'all') !== 'all'
        || ($search_source ?? 'all') !== 'all'
        || ($keyword_status ?? 'all') !== 'all';
@endphp

<div class="space-y-4">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Arama' : 'Search' }}</h2>
            <p class="mt-1 max-w-4xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Kullanıcı sorguları, hedefleme keyword’leri, negatifler ve analizler ayrı çalışma alanlarıdır.' : 'User queries, targeting keywords, negatives, and analysis are separate work areas.' }}</p>
        </div>
        <div class="flex flex-wrap gap-2 text-xs">
            <span class="rounded-full bg-blue-50 px-2.5 py-1 font-medium text-blue-700 ring-1 ring-inset ring-blue-200 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20">{{ $isTr ? 'Provider gerçekleri' : 'Provider facts' }}</span>
            <span class="rounded-full bg-violet-50 px-2.5 py-1 font-medium text-violet-700 ring-1 ring-inset ring-violet-200 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-500/20">{{ $isTr ? 'MOXDOP yorumu ayrı' : 'MOXDOP interpretation separate' }}</span>
        </div>
    </div>

    <div class="overflow-x-auto">
        <div class="inline-flex min-w-max rounded-lg ring-1 ring-inset ring-gray-300 dark:ring-gray-700" role="tablist">
            @foreach ([
                'terms' => $isTr ? 'Arama Terimleri' : 'Search Terms',
                'keywords' => $isTr ? 'Anahtar Kelimeler' : 'Search Keywords',
                'negatives' => $isTr ? 'Negatifler' : 'Negatives',
                'insights' => $isTr ? 'Arama Analizleri' : 'Search Insights',
            ] as $key => $label)
                <button type="button" wire:click="setSearchSub('{{ $key }}')" @class([
                    'px-3.5 py-2 text-xs font-semibold transition',
                    'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white' => $search_sub === $key,
                    'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]' => $search_sub !== $key,
                ])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    @if (in_array($search_sub, ['terms', 'keywords'], true))
        <div class="rounded-xl bg-white p-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-6">
                <input type="search" wire:model.live.debounce.400ms="search_query" placeholder="{{ $isTr ? 'Terim, keyword, kampanya veya reklam grubu ara…' : 'Search term, keyword, campaign or ad group…' }}" class="h-9 w-full rounded-lg border border-gray-300 bg-white px-3 text-xs text-gray-800 outline-none focus:border-brand-400 xl:col-span-2 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200">
                <select wire:model.live="search_campaign" class="h-9 rounded-lg border border-gray-300 bg-white px-2.5 text-xs text-gray-700 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300">
                    <option value="all">{{ $isTr ? 'Tüm kampanyalar' : 'All campaigns' }}</option>
                    @foreach ($campaignOptions as $option)<option value="{{ $option['id'] }}">{{ $option['name'] }}</option>@endforeach
                </select>
                <select wire:model.live="search_ad_group" class="h-9 rounded-lg border border-gray-300 bg-white px-2.5 text-xs text-gray-700 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300">
                    <option value="all">{{ $isTr ? 'Tüm reklam grupları' : 'All ad groups' }}</option>
                    @foreach ($adGroupOptions as $option)<option value="{{ $option['id'] }}">{{ $option['name'] }}</option>@endforeach
                </select>
                @if ($search_sub === 'terms')
                    <select wire:model.live="search_source" class="h-9 rounded-lg border border-gray-300 bg-white px-2.5 text-xs text-gray-700 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300">
                        <option value="all">{{ $isTr ? 'Tüm kaynaklar' : 'All sources' }}</option>
                        @foreach ($sourceOptions as $source)<option value="{{ $source }}">{{ $source }}</option>@endforeach
                    </select>
                @else
                    <select wire:model.live="keyword_status" class="h-9 rounded-lg border border-gray-300 bg-white px-2.5 text-xs text-gray-700 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300">
                        <option value="all">{{ $isTr ? 'Tüm durumlar' : 'All statuses' }}</option>
                        <option value="ENABLED">{{ $isTr ? 'Etkin' : 'Enabled' }}</option>
                        <option value="PAUSED">{{ $isTr ? 'Duraklatıldı' : 'Paused' }}</option>
                        <option value="REMOVED">{{ $isTr ? 'Kaldırıldı' : 'Removed' }}</option>
                    </select>
                @endif
                <div class="flex h-9 items-center justify-end gap-2">
                    <span class="text-[11px] text-gray-400">{{ $isTr ? 'Satır' : 'Rows' }}</span>
                    @foreach ([50,100,250] as $size)
                        <button type="button" wire:click="setSearchPerPage({{ $size }})" @class(['rounded px-1.5 py-1 text-[11px] font-semibold','bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $search_per_page === $size,'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300' => $search_per_page !== $size])>{{ $size }}</button>
                    @endforeach
                </div>
            </div>
            @if ($hasFilters)<div class="mt-2 text-right"><button type="button" wire:click="clearSearchFilters" class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">{{ $isTr ? 'Filtreleri temizle' : 'Clear filters' }}</button></div>@endif
        </div>
    @endif

    @if ($search_sub === 'terms')
        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            <x-ta.metric-card :label="$isTr ? 'Açıklanan sorgu' : 'Disclosed queries'" :value="number_format((int)($coverage['disclosed_terms'] ?? 0), 0, ',', '.')" />
            <x-ta.metric-card :label="$isTr ? 'Açıklanan harcama' : 'Disclosed spend'" :value="$money($coverage['disclosed_spend'] ?? null)" />
            <x-ta.metric-card :label="$isTr ? 'Search ağ harcaması' : 'Search network spend'" :value="$money($coverage['search_network_spend'] ?? null)" />
            <x-ta.metric-card :label="$isTr ? 'Ham sorgu görünürlüğü' : 'Raw-query visibility'" :value="$pct($coverage['visibility_pct'] ?? null)" />
        </div>

        <div class="rounded-xl bg-amber-50 px-4 py-3 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/20">
            <p class="text-sm font-semibold text-amber-900 dark:text-amber-100">{{ $isTr ? 'Az satır görmek her zaman eksik collection demek değildir' : 'A small row count does not always mean incomplete collection' }}</p>
            <p class="mt-1 text-xs leading-5 text-amber-800 dark:text-amber-200/90">{{ $isTr ? 'Google, gizlilik ve düşük hacim eşikleri nedeniyle Search Terms raporunda tüm gerçek sorguları tek tek açıklamaz. MOXDOP gizlenen sorguları uydurmaz. Search ağ harcaması ile açıklanan terim harcaması arasındaki oran, problemi collection eksikliği ile provider gizliliği arasında ayırmaya yardım eder.' : 'Google does not individually disclose every query because of privacy and low-volume thresholds. MOXDOP never fabricates hidden queries. Comparing Search-network spend with disclosed term spend helps distinguish collection gaps from provider privacy limits.' }}</p>
            @if (is_numeric($coverage['unreported_spend_estimate'] ?? null) && (float)$coverage['unreported_spend_estimate'] > 0)
                <p class="mt-2 text-xs font-semibold text-amber-900 dark:text-amber-100">{{ $isTr ? 'Tek tek açıklanmayan tahmini Search harcaması:' : 'Estimated Search spend not individually disclosed:' }} {{ $money($coverage['unreported_spend_estimate']) }}</p>
            @endif
        </div>

        <div class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-800"><div><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Arama terimleri' : 'Search terms' }}</h3><p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Google’ın seçili dönem için açıkladığı gerçek kullanıcı sorguları.' : 'Actual user queries Google disclosed for the selected period.' }}</p></div><span class="text-xs text-gray-500">{{ number_format((int)($termRowsTotal ?? 0),0,',','.') }} {{ $isTr ? 'sonuç' : 'results' }}</span></div>
            <div class="overflow-x-auto"><table class="min-w-[1120px] w-full text-sm"><thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-400 dark:bg-white/[0.02]"><tr>
                <th class="px-4 py-2.5 text-left">{{ $isTr ? 'Arama terimi' : 'Search term' }}</th><th class="px-3 py-2.5 text-left">{{ $isTr ? 'Kaynak' : 'Source' }}</th><th class="px-3 py-2.5 text-left">{{ $isTr ? 'Kampanya' : 'Campaign' }}</th><th class="px-3 py-2.5 text-left">{{ $isTr ? 'Reklam grubu' : 'Ad group' }}</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Gösterim' : 'Impr.' }}</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Tıklama' : 'Clicks' }}</th><th class="px-3 py-2.5 text-right">CTR</th><th class="px-3 py-2.5 text-right">Avg. CPC</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Harcama' : 'Cost' }}</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Dönüşüm' : 'Conv.' }}</th><th class="px-3 py-2.5 text-right">CVR</th><th class="px-4 py-2.5 text-right">CPA</th>
            </tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($termRows ?? [] as $row)
                    <tr class="hover:bg-gray-50/70 dark:hover:bg-white/[0.02]"><td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white">{{ $row['term'] }}</td><td class="px-3 py-2.5"><span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ $row['source'] ?? 'Search' }}</span></td><td class="px-3 py-2.5 text-xs text-gray-600 dark:text-gray-300">{{ $row['campaign'] ?? '—' }}</td><td class="px-3 py-2.5 text-xs text-gray-600 dark:text-gray-300">{{ $row['ad_group'] ?? '—' }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $number($row['impressions'] ?? null) }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $number($row['clicks'] ?? null) }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $pct($row['ctr'] ?? null) }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $money($row['avg_cpc'] ?? null) }}</td><td class="px-3 py-2.5 text-right tabular-nums font-medium">{{ $money($row['spend'] ?? null) }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $number($row['leads'] ?? null,2) }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $pct($row['cvr'] ?? null) }}</td><td class="px-4 py-2.5 text-right tabular-nums">{{ $money($row['cpa'] ?? null) }}</td></tr>
                @empty
                    <tr><td colspan="12" class="px-5 py-12 text-center"><p class="font-medium text-gray-700 dark:text-gray-200">{{ $isTr ? 'Bu dönemde Google tarafından açıklanmış Search Term yok.' : 'Google disclosed no Search Terms for this period.' }}</p><p class="mx-auto mt-1 max-w-2xl text-xs leading-5 text-gray-500">{{ $isTr ? 'Bu 0 arama anlamına gelmez. Search ağ harcaması varsa üstteki görünürlük kartı provider gizliliği ile collection sorununu ayırır.' : 'This does not mean zero searches. If Search-network spend exists, the visibility card above distinguishes provider privacy from collection gaps.' }}</p></td></tr>
                @endforelse
            </tbody></table></div>
            <div class="flex items-center justify-between border-t border-gray-100 px-4 py-3 text-xs dark:border-gray-800"><span class="text-gray-500">{{ $isTr ? 'Sayfa' : 'Page' }} {{ $search_page }} / {{ $termRowsLastPage ?? 1 }}</span><div class="flex gap-2"><button type="button" wire:click="previousSearchPage" @disabled($search_page <= 1) class="rounded-lg px-3 py-1.5 font-semibold ring-1 ring-inset ring-gray-300 disabled:opacity-40 dark:ring-gray-700">{{ $isTr ? 'Önceki' : 'Previous' }}</button><button type="button" wire:click="nextSearchPage" @disabled($search_page >= ($termRowsLastPage ?? 1)) class="rounded-lg px-3 py-1.5 font-semibold ring-1 ring-inset ring-gray-300 disabled:opacity-40 dark:ring-gray-700">{{ $isTr ? 'Sonraki' : 'Next' }}</button></div></div>
        </div>

    @elseif ($search_sub === 'keywords')
        @php
            $withPerformance = $allKeywords->where('period_activity', true)->count();
            $withoutPerformance = max(0, $allKeywords->count() - $withPerformance);
            $keywordSpend = $allKeywords->filter(fn ($row) => is_numeric($row['spend'] ?? null))->sum('spend');
        @endphp
        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4"><x-ta.metric-card :label="$isTr ? 'Keyword envanteri' : 'Keyword inventory'" :value="number_format($allKeywords->count(),0,',','.')" /><x-ta.metric-card :label="$isTr ? 'Dönem performansı olan' : 'With period performance'" :value="number_format($withPerformance,0,',','.')" /><x-ta.metric-card :label="$isTr ? 'Yalnız envanter' : 'Inventory only'" :value="number_format($withoutPerformance,0,',','.')" /><x-ta.metric-card :label="$isTr ? 'Keyword harcaması' : 'Keyword spend'" :value="$withPerformance ? $money($keywordSpend) : '—'" /></div>
        <div class="rounded-xl bg-blue-50 px-4 py-3 text-xs leading-5 text-blue-800 ring-1 ring-inset ring-blue-200 dark:bg-blue-500/10 dark:text-blue-200 dark:ring-blue-500/20">{{ $isTr ? 'Envanter ile dönem performansı ayrıdır. Metrik toplanmamış keyword listede kalır fakat performansı “—” görünür; 0 sayılmaz. Sonradan kaldırılmış keyword seçili dönemde performans üretmişse tarihsel raporda korunur.' : 'Inventory and period performance are separate. A keyword stays listed if period metrics are missing, but metrics show “—”, not fake zero. Removed keywords remain in historical reporting when they had period activity.' }}</div>
        <div class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-800"><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Anahtar kelime performansı' : 'Search keyword performance' }}</h3><span class="text-xs text-gray-500">{{ number_format((int)($keywordRowsTotal ?? 0),0,',','.') }} {{ $isTr ? 'sonuç' : 'results' }}</span></div>
            <div class="overflow-x-auto"><table class="min-w-[1220px] w-full text-sm"><thead class="bg-gray-50 text-[11px] uppercase text-gray-400 dark:bg-white/[0.02]"><tr><th class="px-4 py-2.5 text-left">Keyword</th><th class="px-3 py-2.5 text-left">Match</th><th class="px-3 py-2.5 text-left">{{ $isTr ? 'Durum' : 'Status' }}</th><th class="px-3 py-2.5 text-left">{{ $isTr ? 'Kampanya' : 'Campaign' }}</th><th class="px-3 py-2.5 text-left">{{ $isTr ? 'Reklam grubu' : 'Ad group' }}</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Gösterim' : 'Impr.' }}</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Tıklama' : 'Clicks' }}</th><th class="px-3 py-2.5 text-right">CTR</th><th class="px-3 py-2.5 text-right">Avg. CPC</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Harcama' : 'Cost' }}</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Dönüşüm' : 'Conv.' }}</th><th class="px-3 py-2.5 text-right">CVR</th><th class="px-4 py-2.5 text-right">CPA</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($keywordRows ?? [] as $row)
                    <tr class="hover:bg-gray-50/70 dark:hover:bg-white/[0.02]"><td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white">{{ $row['keyword'] ?? '—' }} @if(!($row['period_activity'] ?? false))<span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-normal text-gray-500 dark:bg-white/5">{{ $isTr ? 'envanter' : 'inventory' }}</span>@endif</td><td class="px-3 py-2.5 text-xs">{{ $row['match'] ?? '—' }}</td><td class="px-3 py-2.5 text-xs">{{ $row['status'] ?? '—' }}</td><td class="px-3 py-2.5 text-xs text-gray-600 dark:text-gray-300">{{ $row['campaign'] ?? '—' }}</td><td class="px-3 py-2.5 text-xs text-gray-600 dark:text-gray-300">{{ $row['ad_group'] ?? '—' }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $number($row['impressions'] ?? null) }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $number($row['clicks'] ?? null) }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $pct($row['ctr'] ?? null) }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $money($row['avg_cpc'] ?? null) }}</td><td class="px-3 py-2.5 text-right tabular-nums font-medium">{{ $money($row['spend'] ?? null) }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $number($row['leads'] ?? null,2) }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $pct($row['cvr'] ?? null) }}</td><td class="px-4 py-2.5 text-right tabular-nums">{{ $money($row['cpa'] ?? null) }}</td></tr>
                @empty<tr><td colspan="13" class="px-5 py-12 text-center text-gray-500">{{ $isTr ? 'Filtrelerle eşleşen keyword yok.' : 'No keywords match the filters.' }}</td></tr>@endforelse
            </tbody></table></div>
            <div class="flex items-center justify-between border-t border-gray-100 px-4 py-3 text-xs dark:border-gray-800"><span class="text-gray-500">{{ $isTr ? 'Sayfa' : 'Page' }} {{ $keyword_page }} / {{ $keywordRowsLastPage ?? 1 }}</span><div class="flex gap-2"><button type="button" wire:click="previousKeywordPage" @disabled($keyword_page <= 1) class="rounded-lg px-3 py-1.5 font-semibold ring-1 ring-inset ring-gray-300 disabled:opacity-40 dark:ring-gray-700">{{ $isTr ? 'Önceki' : 'Previous' }}</button><button type="button" wire:click="nextKeywordPage" @disabled($keyword_page >= ($keywordRowsLastPage ?? 1)) class="rounded-lg px-3 py-1.5 font-semibold ring-1 ring-inset ring-gray-300 disabled:opacity-40 dark:ring-gray-700">{{ $isTr ? 'Sonraki' : 'Next' }}</button></div></div>
        </div>

    @elseif ($search_sub === 'negatives')
        <div class="rounded-xl bg-white px-4 py-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Negatif keyword kapsamı' : 'Negative keyword coverage' }}</p><p class="mt-1 text-xs leading-5 text-gray-500">{{ $isTr ? 'Kampanya ve reklam grubu negatifleri yalnız burada gösterilir. Arama teriminden negatif aday üretmek ayrı bir uzman değerlendirmesidir; Google Ads’e otomatik yazım yapılmaz.' : 'Campaign and ad-group negatives live only here. Turning a search term into a negative remains a separate expert decision; nothing is automatically written to Google Ads.' }}</p></div>
        @include('livewire.demo.google-ads.tabs.search-negatives')

    @else
        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4"><x-ta.metric-card :label="$isTr ? 'Ham sorgu görünürlüğü' : 'Raw-query visibility'" :value="$pct($coverage['visibility_pct'] ?? null)" /><x-ta.metric-card :label="$isTr ? 'Analiz edilen sorgu' : 'Queries analyzed'" :value="number_format((int)($coverage['disclosed_terms'] ?? 0),0,',','.')" /><x-ta.metric-card :label="$isTr ? 'Karar adayı' : 'Decision candidates'" :value="number_format($decisions->count(),0,',','.')" /><x-ta.metric-card :label="$isTr ? 'Analiz kapsamı' : 'Analysis scope'" :value="((int)($coverage['disclosed_terms'] ?? 0)) > 0 ? ($isTr ? 'Açıklanan sorgular' : 'Disclosed queries') : '—'" /></div>
        <div class="rounded-xl bg-violet-50 px-4 py-3 ring-1 ring-inset ring-violet-200 dark:bg-violet-500/10 dark:ring-violet-500/20"><p class="text-sm font-semibold text-violet-900 dark:text-violet-100">{{ $isTr ? 'Google Search Terms Insights ile MOXDOP analizini karıştırmıyoruz' : 'Google Search Terms Insights and MOXDOP analysis stay distinct' }}</p><p class="mt-1 text-xs leading-5 text-violet-800 dark:text-violet-200/90">{{ $isTr ? 'Google’ın Search Terms Insights ürünü, ham raporda gizlenen düşük hacimli sorguları kategori/alt kategori halinde kapsayabilir. Buradaki MOXDOP dağılımı yalnız Google’ın açıkladığı sorgulardan türetilir; gizli sorgular tek tek tahmin edilmez.' : 'Google Search Terms Insights can account for low-volume queries hidden from the raw report at category/subcategory level. The MOXDOP distribution here is derived only from queries Google disclosed; hidden queries are never individually guessed.' }}</p></div>
        <div class="grid gap-4 xl:grid-cols-2">
            <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800"><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Açıklanan sorgu intent dağılımı' : 'Disclosed-query intent distribution' }}</h3><p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'MOXDOP sınıflandırması · Google kategorisi değildir.' : 'MOXDOP classification · not a Google category.' }}</p></div><div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-[11px] uppercase text-gray-400 dark:bg-white/[0.02]"><tr><th class="px-4 py-2.5 text-left">Intent</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Terim' : 'Terms' }}</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Tıklama' : 'Clicks' }}</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Harcama' : 'Spend' }}</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Dönüşüm' : 'Conv.' }}</th><th class="px-4 py-2.5 text-right">{{ $isTr ? 'Harcama payı' : 'Spend share' }}</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">@forelse ($categories as $row)<tr><td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white">{{ $row['label'] }}</td><td class="px-3 py-2.5 text-right">{{ $number($row['terms'] ?? null) }}</td><td class="px-3 py-2.5 text-right">{{ $number($row['clicks'] ?? null) }}</td><td class="px-3 py-2.5 text-right">{{ $money($row['spend'] ?? null) }}</td><td class="px-3 py-2.5 text-right">{{ $number($row['conversions'] ?? null,2) }}</td><td class="px-4 py-2.5 text-right">{{ $pct($row['pct'] ?? null) }}</td></tr>@empty<tr><td colspan="6" class="px-5 py-10 text-center text-gray-500">{{ $isTr ? 'Analiz için açıklanmış sorgu yok.' : 'No disclosed queries to analyze.' }}</td></tr>@endforelse</tbody></table></div></section>
            <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-800"><div><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Karar kuyruğu' : 'Decision queue' }}</h3><p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Otomatik değişiklik değil, uzman inceleme sırası.' : 'Expert review queue, not automatic changes.' }}</p></div><span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ $decisions->count() }}</span></div><div class="max-h-[520px] divide-y divide-gray-100 overflow-y-auto dark:divide-gray-800">@forelse ($decisions as $row)<button type="button" wire:click="openCluster('{{ $row['id'] }}')" class="block w-full px-4 py-3 text-left hover:bg-gray-50 dark:hover:bg-white/[0.02]"><div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $row['title'] }}</p><p class="mt-1 text-xs text-gray-500">{{ $row['intent'] }} · {{ $row['decision'] }} @if(filled($row['campaign'] ?? null)) · {{ $row['campaign'] }} @endif</p></div><div class="shrink-0 text-right"><p class="text-xs font-semibold">{{ $money($row['spend'] ?? null) }}</p><p class="mt-0.5 text-[10px] text-gray-400">{{ $number($row['clicks'] ?? null) }} click</p></div></div></button>@empty<div class="px-5 py-12 text-center text-sm text-gray-500">{{ $isTr ? 'Şu anda karar kuyruğuna düşen sorgu yok.' : 'No queries currently require review.' }}</div>@endforelse</div></section>
        </div>
    @endif
</div>

@if ($selectedCluster)
    <x-demo.gads-drawer :title="$selectedCluster['title']" :subtitle="$selectedCluster['campaign'] ?? ''" :severity="$selectedCluster['type'] ?? null">
        <div><p class="text-xs text-gray-400">{{ $isTr ? 'Neden gösterildi?' : 'Why surfaced?' }}</p><p class="text-gray-800 dark:text-white/90">{{ $selectedCluster['why'] ?? '—' }}</p></div>
        <div class="flex flex-wrap gap-2"><button type="button" wire:click="markClusterReviewed('{{ $selectedCluster['id'] }}')" class="rounded-lg bg-brand-500 px-3 py-2 text-xs font-medium text-white">{{ $isTr ? 'İncelendi olarak işaretle' : 'Mark reviewed' }}</button><button type="button" wire:click="createRecommendation" class="rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">{{ $isTr ? 'Öneri oluştur' : 'Create Recommendation' }}</button></div>
    </x-demo.gads-drawer>
@endif
