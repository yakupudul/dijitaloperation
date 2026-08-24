@php
    $isTr = app()->getLocale() === 'tr';
    $search = $data['search'] ?? [];
    $terms = collect($termRows ?? []);
    $keywords = collect($search['keywords'] ?? []);
    $clusters = collect($search['clusters'] ?? []);
    $intentAvailable = $clusters->isNotEmpty() || $terms->contains(fn ($row) => filled($row['intent'] ?? null));
    $currency = (string) (data_get($identity, 'currency') ?: ($professional['currency'] ?? ''));
    $money = fn ($v) => is_numeric($v) ? trim(number_format((float)$v, 2, ',', '.').' '.$currency) : '—';
    $number = fn ($v, int $d = 0) => is_numeric($v) ? number_format((float)$v, $d, ',', '.') : '—';
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Arama & anahtar kelimeler' : 'Search & keywords' }}</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Kullanıcıların gerçekten ne aradığını, hangi keyword’lerin harcama ve dönüşüm ürettiğini ve negatif keyword kapsamını inceleyin.' : 'Inspect what people actually searched, which keywords generated spend/conversions, and negative-keyword coverage.' }}</p>
        <p class="mt-1 text-xs text-violet-700 dark:text-violet-300">{{ $isTr ? 'Arama terimi ≠ keyword. Arama terimi kullanıcı sorgusudur; keyword hesabın hedefleme unsurudur.' : 'Search term ≠ keyword. A search term is the user query; a keyword is an account targeting entity.' }}</p>
    </div>

    <div class="inline-flex flex-wrap rounded-lg ring-1 ring-inset ring-gray-300 dark:ring-gray-700" role="tablist">
        @foreach ([
            'terms' => $isTr ? 'Arama terimleri' : 'Search terms',
            'keywords' => $isTr ? 'Anahtar kelimeler' : 'Keywords',
            'inbox' => $isTr ? 'Karar Kutusu' : 'Decision Inbox',
            'drift' => $isTr ? 'Intent analizi' : 'Intent analysis',
        ] as $key => $label)
            <button type="button" wire:click="setSearchSub('{{ $key }}')" @class([
                'px-3 py-2 text-xs font-medium',
                'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white' => $search_sub === $key,
                'text-gray-600 dark:text-gray-300' => $search_sub !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($search_sub === 'terms')
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-ta.metric-card :label="$isTr ? 'Gözlenen terim' : 'Observed terms'" :value="$terms->count() ? number_format($terms->count(), 0, ',', '.') : '—'" />
            <x-ta.metric-card :label="$isTr ? 'Toplam harcama' : 'Observed spend'" :value="$terms->isNotEmpty() ? $money($terms->sum('spend')) : '—'" />
            <x-ta.metric-card :label="$isTr ? 'Dönüşüm' : 'Conversions'" :value="$terms->isNotEmpty() ? $number($terms->sum('leads'), 2) : '—'" />
            <x-ta.metric-card :label="$isTr ? 'MOXDOP intent analizi' : 'MOXDOP intent analysis'" :value="$intentAvailable ? ($isTr ? 'Hazır' : 'Available') : '—'" :delta="$intentAvailable ? null : ($isTr ? 'Henüz üretilmedi' : 'Not generated yet')" />
        </div>

        <div class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800"><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Gerçek kullanıcı sorguları' : 'Actual user queries' }}</h3><p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Google’ın gizlilik/eşik kuralları nedeniyle Search Terms raporu tüm sorguları göstermek zorunda değildir.' : 'Google privacy/threshold rules mean Search Terms may not expose every query.' }}</p></div>
            <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-xs uppercase text-gray-400 dark:bg-white/[0.02]"><tr>
                <th class="px-4 py-2.5 text-left">{{ $isTr ? 'Arama terimi' : 'Search term' }}</th>
                <th class="px-3 py-2.5 text-left">{{ $isTr ? 'Kampanya' : 'Campaign' }}</th>
                <th class="px-3 py-2.5 text-right">{{ $isTr ? 'Harcama' : 'Spend' }}</th>
                <th class="px-3 py-2.5 text-right">{{ $isTr ? 'Tıklama' : 'Clicks' }}</th>
                <th class="px-3 py-2.5 text-right">{{ $isTr ? 'Dönüşüm' : 'Conversions' }}</th>
                <th class="px-3 py-2.5 text-right">CPA</th>
                @if ($intentAvailable)<th class="px-4 py-2.5 text-left">Intent / {{ $isTr ? 'karar' : 'decision' }}</th>@endif
            </tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($terms as $row)
                    @php $cpa = is_numeric($row['spend'] ?? null) && is_numeric($row['leads'] ?? null) && (float)$row['leads'] > 0 ? (float)$row['spend']/(float)$row['leads'] : null; @endphp
                    <tr>
                        <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white">{{ $row['term'] }}</td>
                        <td class="px-3 py-2.5 text-xs text-gray-500">{{ $row['campaign'] ?: '—' }}</td>
                        <td class="px-3 py-2.5 text-right tabular-nums">{{ $money($row['spend'] ?? null) }}</td>
                        <td class="px-3 py-2.5 text-right tabular-nums">{{ $number($row['clicks'] ?? null) }}</td>
                        <td class="px-3 py-2.5 text-right tabular-nums">{{ $number($row['leads'] ?? null, 2) }}</td>
                        <td class="px-3 py-2.5 text-right tabular-nums">{{ $cpa !== null ? $money($cpa) : '—' }}</td>
                        @if ($intentAvailable)<td class="px-4 py-2.5 text-xs text-gray-600 dark:text-gray-300">{{ filled($row['intent'] ?? null) ? $row['intent'] : '—' }} @if(filled($row['decision'] ?? null)) · {{ $row['decision'] }} @endif</td>@endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $intentAvailable ? 7 : 6 }}" class="px-4 py-10 text-center text-gray-400">{{ $isTr ? 'Seçili dönem için kullanılabilir arama terimi verisi yok.' : 'No usable search-term data for the selected period.' }}</td></tr>
                @endforelse
            </tbody></table></div>
        </div>

    @elseif ($search_sub === 'keywords')
        <div class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800"><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Anahtar kelime performansı' : 'Keyword performance' }}</h3></div>
            <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-xs uppercase text-gray-400 dark:bg-white/[0.02]"><tr>
                <th class="px-4 py-2.5 text-left">Keyword</th><th class="px-3 py-2.5 text-left">Match</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Harcama' : 'Spend' }}</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Tıklama' : 'Clicks' }}</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Dönüşüm' : 'Conversions' }}</th><th class="px-4 py-2.5 text-right">CPA</th>
            </tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($keywords as $kw)
                    @php $cpa = is_numeric($kw['spend'] ?? null) && is_numeric($kw['leads'] ?? null) && (float)$kw['leads'] > 0 ? (float)$kw['spend']/(float)$kw['leads'] : null; @endphp
                    <tr><td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white">{{ $kw['keyword'] }}</td><td class="px-3 py-2.5 text-xs">{{ $kw['match'] ?? '—' }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $money($kw['spend'] ?? null) }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $number($kw['clicks'] ?? null) }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $number($kw['leads'] ?? null,2) }}</td><td class="px-4 py-2.5 text-right tabular-nums">{{ $cpa !== null ? $money($cpa) : '—' }}</td></tr>
                @empty <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">{{ $isTr ? 'Seçili dönem için kullanılabilir keyword performansı yok.' : 'No usable keyword performance for the selected period.' }}</td></tr> @endforelse
            </tbody></table></div>
        </div>

    @elseif ($search_sub === 'inbox')
        @if ($clusters->isEmpty())
            <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-6 py-12 text-center dark:border-gray-700 dark:bg-white/[0.02]">
                <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Karar Kutusu henüz üretilmedi' : 'Decision Inbox has not been generated yet' }}</h3>
                <p class="mx-auto mt-2 max-w-2xl text-sm text-gray-500">{{ $isTr ? 'Search Term verisi mevcut olsa bile negatif keyword adayı, yeni keyword fırsatı, içerik fırsatı veya strateji incelemesi ayrı bir MOXDOP analiz çıktısıdır. Analiz çalışmadan bu sayaçları 0 olarak göstermiyoruz.' : 'Negative-keyword candidates, keyword opportunities, content opportunities and strategy reviews are MOXDOP analysis outputs. We do not show fake zero counts before that analysis runs.' }}</p>
            </div>
        @else
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <x-ta.metric-card :label="$isTr ? 'Negatif aday' : 'Negative candidates'" :value="(string)data_get($search,'inbox_summary.negative','—')" />
                <x-ta.metric-card :label="$isTr ? 'Keyword adayı' : 'Keyword candidates'" :value="(string)data_get($search,'inbox_summary.keyword','—')" />
                <x-ta.metric-card :label="$isTr ? 'İçerik fırsatı' : 'Content opportunities'" :value="(string)data_get($search,'inbox_summary.content','—')" />
                <x-ta.metric-card :label="$isTr ? 'Strateji incelemesi' : 'Strategy review'" :value="(string)data_get($search,'inbox_summary.strategy','—')" />
            </div>
            <ul class="space-y-2">
                @foreach ($clusters as $cluster)
                    <li class="flex items-center justify-between gap-3 rounded-2xl border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-white/[0.03]"><div><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $cluster['title'] }}</p><p class="mt-1 text-xs text-gray-500">{{ $cluster['campaign'] ?? '—' }} · {{ $money($cluster['spend'] ?? null) }}</p></div><button type="button" wire:click="openCluster('{{ $cluster['id'] }}')" class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">{{ $isTr ? 'İncele' : 'Review' }}</button></li>
                @endforeach
            </ul>
        @endif

    @else
        @if (! $intentAvailable)
            <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-6 py-12 text-center dark:border-gray-700 dark:bg-white/[0.02]">
                <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Intent analizi henüz hesaplanmadı' : 'Intent analysis has not been computed yet' }}</h3>
                <p class="mx-auto mt-2 max-w-2xl text-sm text-gray-500">{{ $isTr ? 'Provider Search Term verisini değiştirmeden, ayrı bir analiz katmanında brand/generic/competitor/price/informational/transactional gibi intent sınıfları üretilecek. Bu veri oluşana kadar sahte dağılım veya drift göstermiyoruz.' : 'Intent classes such as brand/generic/competitor/price/informational/transactional will be derived in a separate analysis layer without changing provider facts. No fake distribution or drift is shown before then.' }}</p>
            </div>
        @else
            <div class="grid gap-3 lg:grid-cols-2">
                <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Intent dağılımı' : 'Intent distribution' }}</h3><ul class="mt-3 space-y-2">@foreach ($search['intent_distribution'] ?? [] as $row)<li><div class="mb-1 flex justify-between text-xs"><span>{{ $row['label'] }}</span><span>{{ $row['pct'] }}%</span></div><x-ta.progress-bar :value="$row['pct']" :max="100" tone="primary" /></li>@endforeach</ul></section>
                <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Intent değişimi' : 'Intent drift' }}</h3><ul class="mt-3 space-y-2 text-sm">@foreach ($search['intent_drift'] ?? [] as $row)<li class="flex justify-between gap-2"><span>{{ $row['label'] }}</span><span class="tabular-nums font-medium">{{ $row['from'] }}% → {{ $row['to'] }}%</span></li>@endforeach</ul></section>
            </div>
        @endif
    @endif
</div>

@if ($selectedCluster)
    <x-demo.gads-drawer :title="$selectedCluster['title']" :subtitle="$selectedCluster['campaign'] ?? ''" :severity="$selectedCluster['type'] ?? null">
        <div><p class="text-xs text-gray-400">{{ $isTr ? 'Neden gösterildi?' : 'Why surfaced' }}</p><p class="text-gray-800 dark:text-white/90">{{ $selectedCluster['why'] ?? '—' }}</p></div>
        <div class="flex flex-wrap gap-2"><button type="button" wire:click="markClusterReviewed('{{ $selectedCluster['id'] }}')" class="rounded-lg bg-brand-500 px-3 py-2 text-xs font-medium text-white">{{ $isTr ? 'İncelendi olarak işaretle' : 'Mark reviewed' }}</button><button type="button" wire:click="createRecommendation('{{ $selectedCluster['title'] }}')" class="rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">{{ $isTr ? 'Öneri oluştur' : 'Create Recommendation' }}</button></div>
        <p class="text-[11px] text-gray-400">{{ $isTr ? 'Google Ads’e otomatik keyword/negatif keyword yazımı yapılmaz.' : 'No automatic keyword/negative-keyword write is made to Google Ads.' }}</p>
    </x-demo.gads-drawer>
@endif
