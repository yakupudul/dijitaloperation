@php
    $isTr = app()->getLocale() === 'tr';
    $lp = $data['landing_pages'] ?? [];
    $rows = collect($lp['rows'] ?? []);
    $currency = (string) (data_get($identity, 'currency') ?: ($professional['currency'] ?? ''));
    $money = fn ($v) => is_numeric($v) ? trim(number_format((float)$v, 2, ',', '.').' '.$currency) : '—';
    $number = fn ($v, int $d = 0) => is_numeric($v) ? number_format((float)$v, $d, ',', '.') : '—';
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Landing Pages</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Google Ads trafiğinin gönderildiği hedef URL’lerin harcama, tıklama ve sağlayıcı dönüşüm performansını inceleyin.' : 'Inspect spend, clicks and provider conversions for destination URLs receiving Google Ads traffic.' }}</p>
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-ta.metric-card :label="$isTr ? 'Hedef URL' : 'Destination URLs'" :value="$rows->count() ? (string)$rows->count() : '—'" />
        <x-ta.metric-card :label="$isTr ? 'Gözlenen harcama' : 'Observed spend'" :value="$rows->isNotEmpty() ? $money($rows->sum('spend')) : '—'" />
        <x-ta.metric-card :label="$isTr ? 'Tıklama' : 'Clicks'" :value="$rows->isNotEmpty() ? number_format((int)$rows->sum('clicks'),0,',','.') : '—'" />
        <x-ta.metric-card :label="$isTr ? 'Google Ads dönüşümü' : 'Google Ads conversions'" :value="$rows->isNotEmpty() ? $number($rows->sum('leads'),2) : '—'" />
    </div>

    <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800"><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Landing page performansı' : 'Landing-page performance' }}</h3><p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Bu tablo yalnız Google Ads provider gerçeklerini gösterir. Teknik hız, mobil UX, SEO ve mesaj uyumu Website dijital varlığı ile çapraz analiz kurulmadan tahmin edilmez.' : 'This table shows Google Ads provider facts only. Technical speed, mobile UX, SEO and message alignment are not guessed until a Website cross-asset analysis exists.' }}</p></div>
        <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-xs uppercase text-gray-400 dark:bg-white/[0.02]"><tr>
            <th class="px-4 py-2.5 text-left">Landing page</th>
            <th class="px-3 py-2.5 text-right">{{ $isTr ? 'Harcama' : 'Spend' }}</th>
            <th class="px-3 py-2.5 text-right">{{ $isTr ? 'Tıklama' : 'Clicks' }}</th>
            <th class="px-3 py-2.5 text-right">{{ $isTr ? 'Gösterim' : 'Impressions' }}</th>
            <th class="px-3 py-2.5 text-right">{{ $isTr ? 'Dönüşüm' : 'Conversions' }}</th>
            <th class="px-3 py-2.5 text-right">CPA</th>
            <th class="px-4 py-2.5"></th>
        </tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($rows as $row)
                @php $cpa = is_numeric($row['spend'] ?? null) && is_numeric($row['leads'] ?? null) && (float)$row['leads'] > 0 ? (float)$row['spend']/(float)$row['leads'] : null; @endphp
                <tr>
                    <td class="max-w-[520px] px-4 py-2.5"><p class="truncate font-medium text-gray-900 dark:text-white" title="{{ $row['url'] }}">{{ $row['url'] }}</p></td>
                    <td class="px-3 py-2.5 text-right tabular-nums">{{ $money($row['spend'] ?? null) }}</td>
                    <td class="px-3 py-2.5 text-right tabular-nums">{{ $number($row['clicks'] ?? null) }}</td>
                    <td class="px-3 py-2.5 text-right tabular-nums">{{ $number($row['impressions'] ?? null) }}</td>
                    <td class="px-3 py-2.5 text-right tabular-nums">{{ $number($row['leads'] ?? null,2) }}</td>
                    <td class="px-3 py-2.5 text-right tabular-nums">{{ $cpa !== null ? $money($cpa) : '—' }}</td>
                    <td class="px-4 py-2.5 text-right"><button type="button" wire:click="openLanding('{{ $row['id'] }}')" class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">{{ $isTr ? 'Detay' : 'Inspect' }}</button></td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">{{ $isTr ? 'Seçili dönem için kullanılabilir landing-page performansı yok.' : 'No usable landing-page performance for the selected period.' }}</td></tr>
            @endforelse
        </tbody></table></div>
    </section>

    <div class="rounded-xl bg-violet-50 px-4 py-3 text-sm text-violet-800 ring-1 ring-inset ring-violet-100 dark:bg-violet-500/10 dark:text-violet-200 dark:ring-violet-500/20">
        <strong>{{ $isTr ? 'Çapraz varlık fırsatı:' : 'Cross-asset opportunity:' }}</strong>
        {{ $isTr ? 'Website asset ile ilişki kurulduğunda yüksek harcama alan URL’ler; hız, mobil deneyim, GA4 davranışı, Search Console görünürlüğü ve web sitesi bulgularıyla aynı kanıt zincirinde değerlendirilebilir.' : 'Once linked to the Website asset, high-spend URLs can be evaluated with speed, mobile UX, GA4 behavior, Search Console visibility and website findings in one evidence chain.' }}
    </div>
</div>

@if ($selectedLanding)
    @php $selectedCpa = is_numeric($selectedLanding['spend'] ?? null) && is_numeric($selectedLanding['leads'] ?? null) && (float)$selectedLanding['leads'] > 0 ? (float)$selectedLanding['spend']/(float)$selectedLanding['leads'] : null; @endphp
    <x-demo.gads-drawer :title="$selectedLanding['url']" :subtitle="$isTr ? 'Google Ads landing page' : 'Google Ads landing page'">
        <div class="grid grid-cols-2 gap-3">
            <div><p class="text-xs text-gray-400">{{ $isTr ? 'Harcama' : 'Spend' }}</p><p class="font-semibold">{{ $money($selectedLanding['spend'] ?? null) }}</p></div>
            <div><p class="text-xs text-gray-400">{{ $isTr ? 'Tıklama' : 'Clicks' }}</p><p class="font-semibold">{{ $number($selectedLanding['clicks'] ?? null) }}</p></div>
            <div><p class="text-xs text-gray-400">{{ $isTr ? 'Dönüşüm' : 'Conversions' }}</p><p class="font-semibold">{{ $number($selectedLanding['leads'] ?? null,2) }}</p></div>
            <div><p class="text-xs text-gray-400">CPA</p><p class="font-semibold">{{ $selectedCpa !== null ? $money($selectedCpa) : '—' }}</p></div>
        </div>
        <p class="text-xs text-gray-500">{{ $isTr ? 'Teknik/mobil/mesaj uyumu bu Google Ads provider kaydından çıkarılmaz. Website cross-asset verisi gerektiğinde ayrıca bağlanır.' : 'Technical/mobile/message alignment is not inferred from this Google Ads provider row. Website cross-asset data is joined separately when available.' }}</p>
    </x-demo.gads-drawer>
@endif
