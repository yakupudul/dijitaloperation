{{-- Konu haritası ve yazı karnesi (yakup, 2026-10-16): per service how much of its map is done, what is live and in the top 10, what waits; the sent articles' index state and numbers. --}}
<section class="min-w-0" data-section="topic-map">
    <div class="section-head justify-between">
        <div class="flex min-w-0 flex-wrap items-baseline gap-x-3">
            <h2 class="section-title">Konu haritası</h2>
            <span class="text-[13px] text-gray-500 dark:text-gray-400">her hizmetin konularının ne kadarı yazıldı</span>
        </div>
        <div class="flex items-center gap-1">
            <button type="button" wire:click="$toggle('showScorecard')" class="btn-quiet">{{ $showScorecard ? 'Yazı karnesini gizle' : 'Yazı karnesi' }}</button>
            <x-operator.ai-prompt-info operation="content.topic_map" />
        </div>
    </div>
    @if ($topicMap === [])
        <div class="card"><x-ta.empty-state size="sm" icon="none" title="Konu haritası henüz çizilmedi" message="Çarşamba sabahki fikir turunda, işe en çok değer katan hizmetten başlayarak çizilir; «Haftalık içerik öner» ile hemen başlatılabilir." /></div>
    @else
        <ul class="card">
            @foreach ($topicMap as $service)
                @php $isOpen = $openService === $service['service_id']; @endphp
                <li class="border-t border-gray-200 first:border-t-0 dark:border-gray-800" wire:key="tm-{{ $service['service_id'] }}" data-topic-service="{{ $service['service_id'] }}">
                    <button type="button" wire:click="$set('openService', {{ $isOpen ? 'null' : $service['service_id'] }})" aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                            class="flex w-full min-w-0 flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3.5 text-left hover:bg-gray-50/70 sm:flex-nowrap dark:hover:bg-white/[0.02]">
                        <svg @class(['h-4 w-4 shrink-0 text-gray-400 transition', 'rotate-90' => $isOpen]) viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.17 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
                        <span class="min-w-0 flex-[1_1_14rem]">
                            <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
                                <span class="min-w-0 break-words text-sm font-medium text-gray-900 dark:text-white">{{ $service['service'] }}</span>
                                @if ($service['active'])<x-ta.pill tone="live">şu an yazılıyor</x-ta.pill>@endif
                            </span>
                            <span class="mt-0.5 block text-[13px] text-gray-500 dark:text-gray-400">{{ $service['live'] }} yazı yayında, {{ $service['top10'] }} sayfası ilk 10'da, sırada {{ $service['waiting'] }} konu</span>
                        </span>
                        <span class="flex w-full items-center gap-2.5 pl-7 sm:w-48 sm:pl-0">
                            <span class="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800"><span class="block h-full rounded-full bg-success-500" style="width: {{ $service['percent'] }}%"></span></span>
                            <span class="shrink-0 text-xs tabular-nums text-gray-600 dark:text-gray-300">%{{ $service['percent'] }} bitti</span>
                        </span>
                    </button>
                    @if ($isOpen)
                        <ol class="border-t border-gray-200 bg-gray-50/70 px-4 py-2 sm:pl-11 dark:border-gray-800 dark:bg-white/[0.02]">
                            @foreach ($service['topics'] as $topic)
                                <li class="flex min-w-0 flex-wrap items-baseline gap-x-3 gap-y-0.5 py-1.5" data-topic="{{ $topic->id }}">
                                    <span class="w-24 shrink-0 text-xs text-gray-500">{{ \App\Models\ContentTopic::TYPE_LABELS[$topic->type] ?? $topic->type }}</span>
                                    <span @class(['min-w-0 flex-[1_1_14rem] break-words text-[13px] text-gray-900 dark:text-white', 'line-through text-gray-400!' => $topic->status === 'dropped'])>{{ $topic->title }}</span>
                                    <span class="min-w-0 wrap-anywhere text-xs text-gray-500">{{ match (true) {
                                        $topic->status === 'live' => 'yayında',
                                        $topic->status === 'sent' => 'siteye gönderildi',
                                        $topic->status === 'idea' => 'fikir hazır',
                                        $topic->coverage === 'covered' => 'sitede karşılanıyor',
                                        $topic->coverage === 'weak' => 'sayfası zayıf, güçlendirilecek',
                                        $topic->status === 'dropped' => 'bırakıldı',
                                        default => 'yazılacak',
                                    } }}@if ($topic->page), {{ \App\Services\SeoTasks\SeoText::urlPath((string) $topic->page->url) }}@endif</span>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($scorecard !== null)
        <div class="mt-6">
            <div class="section-head">
                <h3 class="section-title">Yazı karnesi</h3>
                <span class="text-[13px] text-gray-500 dark:text-gray-400">son 180 günde gönderilen yazılar</span>
            </div>
            @if ($scorecard === [])
                <div class="card px-4 py-6 text-[13px] text-gray-500">Son 180 günde bu siteye gönderilmiş yeni yazı yok.</div>
            @else
                <div class="card overflow-x-auto">
                    <table class="data-table" data-scorecard>
                        <thead>
                            <tr><th>Yazı</th><th>Dizin</th><th class="text-right!">28 gün</th><th class="text-right!">90 gün</th><th>Not</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($scorecard as $row)
                                <tr wire:key="sc-{{ $row['id'] }}">
                                    <td class="min-w-[14rem]"><span class="font-medium text-gray-900 dark:text-white">{{ \Illuminate\Support\Str::limit($row['title'], 60) }}</span><span class="block text-xs text-gray-500">{{ $row['sent_at'] }}@if ($row['url']), {{ \App\Services\SeoTasks\SeoText::urlPath($row['url']) }}@endif</span></td>
                                    <td class="whitespace-nowrap text-[13px]">{{ match ($row['index']) { 'PASS' => 'dizinde', null => ($row['live'] ? 'bakılmadı' : '—'), default => 'dizinde değil' } }}</td>
                                    @foreach (['d28', 'd90'] as $w)
                                        <td class="whitespace-nowrap text-right text-[13px] tabular-nums">{{ $row[$w]['impressions'] }} gösterim, {{ $row[$w]['clicks'] }} tık
                                            @if ($row[$w]['position'] !== null || $row[$w]['conversions'] > 0)
                                                <span class="block text-xs text-gray-500">{{ $row[$w]['position'] !== null ? number_format($row[$w]['position'], 1, ',', '.').'. sıra' : '' }}{{ $row[$w]['position'] !== null && $row[$w]['conversions'] > 0 ? ', ' : '' }}{{ $row[$w]['conversions'] > 0 ? $row[$w]['conversions'].' dönüşüm' : '' }}</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td @class(['min-w-[10rem] text-[13px]', 'text-warning-700 dark:text-warning-300' => $row['flag'] !== null])>{{ $row['flag'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
</section>
