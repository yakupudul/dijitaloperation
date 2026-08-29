@php
    $isTr = app()->getLocale() === 'tr';
    $allCreatives = collect($professional['creatives'] ?? []);
    $activeCreatives = $allCreatives
        ->filter(fn (array $row): bool =>
            ((float) ($row['spend'] ?? 0)) > 0
            || ((int) ($row['impressions'] ?? 0)) > 0
            || ((int) ($row['clicks'] ?? 0)) > 0
            || ! empty($row['summary_actions'] ?? [])
        )
        ->values();

    // Render the analysis set, not hundreds of zero-activity inventory cards.
    // The complete inventory count is still visible in the summary.
    $displayCreatives = ($activeCreatives->isNotEmpty() ? $activeCreatives : $allCreatives)
        ->take(60)
        ->values()
        ->all();

    $withSpend = $allCreatives->filter(fn (array $row): bool => ((float) ($row['spend'] ?? 0)) > 0)->count();
    $withActions = $allCreatives->filter(fn (array $row): bool => ! empty($row['summary_actions'] ?? []))->count();
    $videoCreatives = $allCreatives->filter(fn (array $row): bool => ! empty($row['video'] ?? []))->count();
    $inactiveCount = max(0, $allCreatives->count() - $activeCreatives->count());

    // Avoid crowning a tiny-sample creative on a misleading CTR.
    $highestCtr = $activeCreatives
        ->filter(fn (array $row): bool => ((int) ($row['impressions'] ?? 0)) >= 1000 && ($row['ctr'] ?? null) !== null)
        ->sortByDesc('ctr')
        ->first();

    $displayName = static function (array $creative) use ($isTr): string {
        $name = trim((string) ($creative['name'] ?? ''));
        $id = (string) ($creative['id'] ?? '');

        if ($name === '' || preg_match('/^(Creative|Kreatif)\s+\d+$/i', $name) === 1) {
            return ($isTr ? 'Kreatif ' : 'Creative ').$id;
        }

        return $name;
    };
@endphp

<section class="space-y-5">
    <div>
        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">{{ $isTr ? 'Kreatifler' : 'Creatives' }}</p>
        <h2 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Hangi görsel veya video daha çok ilgi ve sonuç üretiyor?' : 'Which creative generates more interest and outcomes?' }}</h2>
        <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Seçili dönemde gerçekten yayın alan kreatifler öne çıkarılır. Harcama, tıklama ve Meta tarafından ölçülen sonuçlar ilgili reklamlar üzerinden kreatife bağlanır.' : 'Creatives with real delivery in the selected period are prioritized and joined to measured ad outcomes.' }}</p>

        @if ($inactiveCount > 0)
            <p class="mt-2 text-xs text-gray-400">
                {{ $isTr
                    ? number_format($allCreatives->count()).' kreatif envanterde · '.number_format($activeCreatives->count()).' tanesinde seçili dönemde aktivite var · '.number_format($inactiveCount).' pasif kreatif analiz kartlarından gizlendi'
                    : number_format($allCreatives->count()).' in inventory · '.number_format($activeCreatives->count()).' with activity · '.number_format($inactiveCount).' inactive creatives hidden from analysis cards' }}
            </p>
        @endif
    </div>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1.6fr)_360px]">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($displayCreatives as $creative)
                @php
                    $summaryActions = $creative['summary_actions'] ?? [];
                    $status = strtoupper((string) ($creative['status'] ?? ''));
                @endphp

                <article class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <div class="relative flex aspect-[4/3] items-center justify-center bg-gray-100 dark:bg-white/[0.03]">
                        @if (! empty($creative['thumbnail_url']))
                            <img src="{{ $creative['thumbnail_url'] }}" alt="" class="h-full w-full object-cover" loading="lazy">
                        @else
                            <div class="text-center text-gray-400">
                                <svg class="mx-auto h-8 w-8" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 5.5A1.5 1.5 0 0 1 5.5 4h13A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 18.5v-13Z" stroke="currentColor" stroke-width="1.5"/><path d="m7 16 3.5-4 2.5 2.5 1.5-2 2.5 3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <p class="mt-2 text-xs">{{ $isTr ? 'Görsel önizleme alınamadı' : 'Visual preview unavailable' }}</p>
                            </div>
                        @endif

                        @if (($creative['format'] ?? '—') !== '—')
                            <span class="absolute left-3 top-3 rounded-full bg-black/65 px-2 py-1 text-[10px] font-semibold text-white">{{ $creative['format'] }}</span>
                        @endif
                    </div>

                    <div class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-gray-900 dark:text-white">{{ $displayName($creative) }}</p>
                                <p class="mt-0.5 truncate text-xs text-gray-400">{{ implode(' · ', array_slice($creative['campaigns'] ?? [], 0, 2)) ?: ($isTr ? 'Kampanya ilişkisi bulunamadı' : 'No campaign linkage') }}</p>
                            </div>

                            @if ($status !== '' && $status !== 'UNKNOWN')
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-500 dark:bg-white/[0.05] dark:text-gray-300">{{ $status === 'ACTIVE' && $isTr ? 'Aktif' : ($status === 'PAUSED' && $isTr ? 'Durduruldu' : $status) }}</span>
                            @endif
                        </div>

                        <div class="mt-4 grid grid-cols-2 gap-3 text-xs">
                            <div><p class="text-gray-400">{{ $isTr ? 'Reklam Harcaması' : 'Ad Spend' }}</p><p class="mt-1 font-bold text-gray-800 dark:text-gray-200">{{ $creative['spend_display'] }}</p></div>
                            <div><p class="text-gray-400">{{ $isTr ? 'Tıklama Oranı' : 'Click Rate' }} <span class="text-gray-300">(CTR)</span></p><p class="mt-1 font-bold text-gray-800 dark:text-gray-200">{{ $creative['ctr'] !== null ? number_format($creative['ctr'], 2).'%' : '—' }}</p></div>
                            <div><p class="text-gray-400">{{ $isTr ? 'Reklam Gösterimleri' : 'Impressions' }}</p><p class="mt-1 font-semibold text-gray-700 dark:text-gray-300">{{ number_format($creative['impressions']) }}</p></div>
                            <div><p class="text-gray-400">{{ $isTr ? 'Toplam Tıklamalar' : 'Clicks' }}</p><p class="mt-1 font-semibold text-gray-700 dark:text-gray-300">{{ number_format($creative['clicks']) }}</p></div>
                        </div>

                        @if (! empty($summaryActions))
                            <div class="mt-4 border-t border-gray-100 pt-3 dark:border-gray-800">
                                <p class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-gray-400">{{ $isTr ? 'Öne çıkan ölçülen sonuçlar' : 'Headline observed outcomes' }}</p>
                                @foreach (array_slice($summaryActions, 0, 3) as $action)
                                    <div class="flex items-center justify-between gap-3 py-1 text-xs">
                                        <span class="truncate text-gray-600 dark:text-gray-300">{{ $isTr ? $action['label_tr'] : $action['label_en'] }}</span>
                                        <strong class="tabular-nums text-gray-900 dark:text-white">{{ number_format((float) $action['value'], 2) }}</strong>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if (! empty($creative['title']) || ! empty($creative['body']))
                            <div class="mt-4 border-t border-gray-100 pt-3 dark:border-gray-800">
                                @if (! empty($creative['title']))<p class="text-xs font-semibold text-gray-700 dark:text-gray-300">{{ $creative['title'] }}</p>@endif
                                @if (! empty($creative['body']))<p class="mt-1 line-clamp-2 text-[11px] leading-5 text-gray-400">{{ $creative['body'] }}</p>@endif
                            </div>
                        @endif

                        @if (! empty($creative['video']))
                            <div class="mt-4 grid grid-cols-3 gap-2 border-t border-gray-100 pt-3 text-[10px] dark:border-gray-800">
                                @foreach (['video_p25_watched_actions' => '25%','video_p50_watched_actions' => '50%','video_p100_watched_actions' => '100%'] as $metricKey => $metricLabel)
                                    <div class="rounded-lg bg-gray-50 px-2 py-2 text-center dark:bg-white/[0.03]"><p class="text-gray-400">{{ $isTr ? 'Video '.$metricLabel : $metricLabel.' watched' }}</p><p class="mt-1 font-bold text-gray-700 dark:text-gray-300">{{ isset($creative['video'][$metricKey]) ? number_format($creative['video'][$metricKey]) : '—' }}</p></div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </article>
            @empty
                <div class="col-span-full rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-16 text-center text-sm text-gray-400 dark:border-gray-700 dark:bg-gray-900">{{ $isTr ? 'Seçili dönemde analiz edilebilecek kreatif aktivitesi yok.' : 'No creative activity is available for analysis in this period.' }}</div>
            @endforelse
        </div>

        <article class="h-fit rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Kreatif Özeti' : 'Creative Summary' }}</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Envanter ile seçili dönem performansı birbirinden ayrılır.' : 'Inventory and selected-period performance are kept separate.' }}</p>

            <div class="mt-5 space-y-3">
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><p class="text-xs text-gray-400">{{ $isTr ? 'Toplam kreatif envanteri' : 'Total creative inventory' }}</p><p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($allCreatives->count()) }}</p></div>
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><p class="text-xs text-gray-400">{{ $isTr ? 'Bu dönemde harcama yapılan kreatif' : 'Creatives with spend in period' }}</p><p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($withSpend) }}</p></div>
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><p class="text-xs text-gray-400">{{ $isTr ? 'Öne çıkan sonucu bulunan kreatif' : 'Creatives with headline outcomes' }}</p><p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($withActions) }}</p></div>
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><p class="text-xs text-gray-400">{{ $isTr ? 'Video izleme metriği bulunan' : 'With video metrics' }}</p><p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($videoCreatives) }}</p></div>

                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                    <p class="text-xs text-gray-400">{{ $isTr ? 'En yüksek tıklama oranı' : 'Highest click rate' }}</p>
                    <p class="mt-0.5 text-[10px] text-gray-400">{{ $isTr ? 'En az 1.000 gösterim alan kreatifler arasında' : 'Among creatives with at least 1,000 impressions' }}</p>
                    @if ($highestCtr)
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($highestCtr['ctr'], 2) }}%</p>
                        <p class="mt-1 truncate text-xs text-gray-400">{{ $displayName($highestCtr) }}</p>
                    @else
                        <p class="mt-1 text-2xl font-bold text-gray-400">—</p>
                    @endif
                </div>
            </div>

            <div class="mt-5 rounded-xl bg-gray-50 p-4 text-xs leading-5 text-gray-500 dark:bg-white/[0.03] dark:text-gray-400">{{ $isTr ? '“Kazanan kreatif” veya “yoruluyor” gibi teşhisler, yeterli örneklem ve analiz kuralı oluşmadan otomatik yazılmaz.' : 'Winner/fatigue diagnoses are not generated without sufficient sample size and explicit analysis rules.' }}</div>
        </article>
    </div>
</section>
