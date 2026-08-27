@php
    $isTr = app()->getLocale() === 'tr';
    $creatives = $professional['creatives'] ?? [];
    $withSpend = collect($creatives)->filter(fn ($row) => ($row['spend'] ?? 0) > 0)->count();
    $videoCreatives = collect($creatives)->filter(fn ($row) => ! empty($row['video']))->count();
    $highestCtr = collect($creatives)->filter(fn ($row) => ($row['impressions'] ?? 0) > 0 && ($row['ctr'] ?? null) !== null)->sortByDesc('ctr')->first();
@endphp

<section class="space-y-5">
    <div>
        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">{{ $isTr ? 'Kreatif Analizi' : 'Creative Analysis' }}</p>
        <h2 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Kreatif envanterini gerçek delivery verisiyle birlikte oku' : 'Read creative inventory with real delivery data' }}</h2>
        <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Creative snapshot; reklam performansı ve varsa video engagement metrikleriyle creative_id üzerinden birleştirilir.' : 'Creative snapshots are joined by creative_id with ad performance and video engagement metrics when available.' }}</p>
    </div>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1.6fr)_360px]">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($creatives as $creative)
                <article class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <div class="relative flex aspect-[4/3] items-center justify-center bg-gray-100 dark:bg-white/[0.03]">
                        @if (! empty($creative['thumbnail_url']))
                            <img src="{{ $creative['thumbnail_url'] }}" alt="" class="h-full w-full object-cover" loading="lazy">
                        @else
                            <div class="text-center text-gray-400">
                                <svg class="mx-auto h-8 w-8" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 5.5A1.5 1.5 0 0 1 5.5 4h13A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 18.5v-13Z" stroke="currentColor" stroke-width="1.5"/><path d="m7 16 3.5-4 2.5 2.5 1.5-2 2.5 3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <p class="mt-2 text-xs">{{ $isTr ? 'Thumbnail yok' : 'No thumbnail' }}</p>
                            </div>
                        @endif
                        <span class="absolute left-3 top-3 rounded-full bg-black/65 px-2 py-1 text-[10px] font-semibold text-white">{{ $creative['format'] ?? 'Unknown' }}</span>
                    </div>
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-gray-900 dark:text-white">{{ $creative['name'] }}</p>
                                <p class="mt-0.5 truncate text-xs text-gray-400">{{ implode(' · ', array_slice($creative['campaigns'] ?? [], 0, 2)) ?: ($isTr ? 'Kampanya ilişkisi yok' : 'No campaign linkage') }}</p>
                            </div>
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-500 dark:bg-white/[0.05] dark:text-gray-300">{{ $creative['status'] }}</span>
                        </div>

                        <div class="mt-4 grid grid-cols-2 gap-3 text-xs">
                            <div><p class="text-gray-400">{{ $isTr ? 'Harcama' : 'Spend' }}</p><p class="mt-1 font-bold text-gray-800 dark:text-gray-200">{{ $creative['spend_display'] }}</p></div>
                            <div><p class="text-gray-400">CTR</p><p class="mt-1 font-bold text-gray-800 dark:text-gray-200">{{ $creative['ctr'] !== null ? number_format($creative['ctr'], 2).'%' : '—' }}</p></div>
                            <div><p class="text-gray-400">{{ $isTr ? 'Gösterim' : 'Impressions' }}</p><p class="mt-1 font-semibold text-gray-700 dark:text-gray-300">{{ number_format($creative['impressions']) }}</p></div>
                            <div><p class="text-gray-400">{{ $isTr ? 'Tıklama' : 'Clicks' }}</p><p class="mt-1 font-semibold text-gray-700 dark:text-gray-300">{{ number_format($creative['clicks']) }}</p></div>
                        </div>

                        @if (! empty($creative['title']) || ! empty($creative['body']))
                            <div class="mt-4 border-t border-gray-100 pt-3 dark:border-gray-800">
                                @if (! empty($creative['title']))<p class="text-xs font-semibold text-gray-700 dark:text-gray-300">{{ $creative['title'] }}</p>@endif
                                @if (! empty($creative['body']))<p class="mt-1 line-clamp-2 text-[11px] leading-5 text-gray-400">{{ $creative['body'] }}</p>@endif
                            </div>
                        @endif

                        @if (! empty($creative['video']))
                            <div class="mt-4 grid grid-cols-3 gap-2 border-t border-gray-100 pt-3 text-[10px] dark:border-gray-800">
                                @foreach ([
                                    'video_p25_watched_actions' => '25%',
                                    'video_p50_watched_actions' => '50%',
                                    'video_p100_watched_actions' => '100%',
                                ] as $metricKey => $metricLabel)
                                    <div class="rounded-lg bg-gray-50 px-2 py-2 text-center dark:bg-white/[0.03]"><p class="text-gray-400">{{ $metricLabel }}</p><p class="mt-1 font-bold text-gray-700 dark:text-gray-300">{{ isset($creative['video'][$metricKey]) ? number_format($creative['video'][$metricKey]) : '—' }}</p></div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </article>
            @empty
                <div class="col-span-full rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-16 text-center text-sm text-gray-400 dark:border-gray-700 dark:bg-gray-900">{{ $isTr ? 'Kullanılabilir creative snapshot verisi yok.' : 'No usable creative snapshot data.' }}</div>
            @endforelse
        </div>

        <article class="h-fit rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Gözlenen Kreatif Sinyalleri' : 'Observed Creative Signals' }}</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Yalnızca ölçülmüş veriler; winner/fatigue teşhisi yapılmaz.' : 'Measured data only; no winner/fatigue diagnosis is inferred.' }}</p>
            <div class="mt-5 space-y-3">
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><p class="text-xs text-gray-400">{{ $isTr ? 'Toplam kreatif' : 'Total creatives' }}</p><p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ count($creatives) }}</p></div>
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><p class="text-xs text-gray-400">{{ $isTr ? 'Harcama gözlenen kreatif' : 'Creatives with observed spend' }}</p><p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $withSpend }}</p></div>
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><p class="text-xs text-gray-400">{{ $isTr ? 'Video metriği bulunan' : 'With video metrics' }}</p><p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $videoCreatives }}</p></div>
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                    <p class="text-xs text-gray-400">{{ $isTr ? 'Gözlenen en yüksek CTR' : 'Highest observed CTR' }}</p>
                    @if ($highestCtr)
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($highestCtr['ctr'], 2) }}%</p>
                        <p class="mt-1 truncate text-xs text-gray-400">{{ $highestCtr['name'] }}</p>
                    @else
                        <p class="mt-1 text-2xl font-bold text-gray-400">—</p>
                    @endif
                </div>
            </div>
            <div class="mt-5 rounded-xl bg-gray-50 p-4 text-xs leading-5 text-gray-500 dark:bg-white/[0.03] dark:text-gray-400">{{ $isTr ? 'Fatigue, hook, angle ve persona sınıflandırması sonraki analiz katmanıdır. Bu ekranda mevcut olmayan kreatif zekâ sinyalleri uydurulmaz.' : 'Fatigue, hook, angle and persona classification belong to the next analysis layer. Creative-intelligence signals that do not exist are not fabricated here.' }}</div>
        </article>
    </div>
</section>
