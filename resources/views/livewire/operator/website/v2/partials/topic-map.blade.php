{{-- Konu haritası ve yazı karnesi (yakup, 2026-10-16): per service how much of its map is done, what is live and in the top 10, what waits; the sent articles' index state and numbers. --}}
<section class="{{ $card }} space-y-3" data-section="topic-map">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Konu haritası</h3>
        <x-operator.ai-prompt-info operation="content.topic_map" />
    </div>
    @if ($topicMap === [])
        <p class="text-xs text-gray-500">Bu sitenin konu haritası henüz çizilmedi. Çarşamba sabahki fikir turunda, işe en çok değer katan hizmetten başlayarak çizilir; "Haftalık içerik öner" ile hemen başlatılabilir.</p>
    @else
        <ul class="divide-y divide-gray-100 text-xs dark:divide-gray-800">
            @foreach ($topicMap as $service)
                <li class="py-2" wire:key="tm-{{ $service['service_id'] }}" data-topic-service="{{ $service['service_id'] }}">
                    <button type="button" wire:click="$set('openService', {{ $openService === $service['service_id'] ? 'null' : $service['service_id'] }})" class="flex w-full flex-wrap items-center gap-2 text-left">
                        <span class="font-medium text-gray-900 dark:text-white">{{ $service['service'] }}</span>
                        @if ($service['active'])<span class="{{ $chip }} bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">şu an yazılıyor</span>@endif
                        <span class="h-1.5 w-28 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800"><span class="block h-full bg-emerald-500" style="width: {{ $service['percent'] }}%"></span></span>
                        <span class="text-gray-600 dark:text-gray-300">%{{ $service['percent'] }} bitti · {{ $service['live'] }} yazı yayında, {{ $service['top10'] }} sayfası ilk 10'da · sırada {{ $service['waiting'] }} konu</span>
                    </button>
                    @if ($openService === $service['service_id'])
                        <ol class="mt-2 space-y-1 pl-4">
                            @foreach ($service['topics'] as $topic)
                                <li class="flex flex-wrap items-baseline gap-2" data-topic="{{ $topic->id }}">
                                    <span class="{{ $chip }} bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ \App\Models\ContentTopic::TYPE_LABELS[$topic->type] ?? $topic->type }}</span>
                                    <span @class(['text-gray-900 dark:text-white', 'line-through text-gray-400' => $topic->status === 'dropped'])>{{ $topic->title }}</span>
                                    <span class="text-gray-500">{{ match (true) {
                                        $topic->status === 'live' => 'yayında',
                                        $topic->status === 'sent' => 'siteye gönderildi',
                                        $topic->status === 'idea' => 'fikir hazır',
                                        $topic->coverage === 'covered' => 'sitede karşılanıyor',
                                        $topic->coverage === 'weak' => 'sayfası zayıf, güçlendirilecek',
                                        $topic->status === 'dropped' => 'bırakıldı',
                                        default => 'yazılacak',
                                    } }}@if ($topic->page) · {{ \App\Services\SeoTasks\SeoText::urlPath((string) $topic->page->url) }}@endif</span>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
    <div class="border-t border-gray-100 pt-2 dark:border-gray-800">
        <button type="button" wire:click="$toggle('showScorecard')" class="{{ $ghost }}">{{ $showScorecard ? 'Yazı karnesini gizle' : 'Yazı karnesi' }}</button>
        @if ($scorecard !== null)
            @if ($scorecard === [])
                <p class="mt-2 text-xs text-gray-500">Son 180 günde bu siteye gönderilmiş yeni yazı yok.</p>
            @else
                <div class="mt-2 overflow-x-auto">
                    <table class="min-w-full text-xs" data-scorecard>
                        <thead class="text-left text-gray-500">
                            <tr><th class="py-1 pr-2 font-medium">Yazı</th><th class="py-1 pr-2 font-medium">Dizin</th><th class="py-1 pr-2 text-right font-medium">28 gün</th><th class="py-1 pr-2 text-right font-medium">90 gün</th><th class="py-1 font-medium">Not</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($scorecard as $row)
                                <tr wire:key="sc-{{ $row['id'] }}">
                                    <td class="py-1 pr-2"><span class="font-medium">{{ \Illuminate\Support\Str::limit($row['title'], 60) }}</span><span class="block text-gray-500">{{ $row['sent_at'] }}@if ($row['url']) · {{ \App\Services\SeoTasks\SeoText::urlPath($row['url']) }}@endif</span></td>
                                    <td class="py-1 pr-2">{{ match ($row['index']) { 'PASS' => 'dizinde', null => ($row['live'] ? 'bakılmadı' : '—'), default => 'dizinde değil' } }}</td>
                                    @foreach (['d28', 'd90'] as $w)
                                        <td class="py-1 pr-2 text-right tabular-nums">{{ $row[$w]['impressions'] }} gösterim · {{ $row[$w]['clicks'] }} tık
                                            @if ($row[$w]['position'] !== null || $row[$w]['conversions'] > 0)
                                                <span class="block text-gray-500">{{ $row[$w]['position'] !== null ? number_format($row[$w]['position'], 1, ',', '.').'. sıra' : '' }}{{ $row[$w]['position'] !== null && $row[$w]['conversions'] > 0 ? ' · ' : '' }}{{ $row[$w]['conversions'] > 0 ? $row[$w]['conversions'].' dönüşüm' : '' }}</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td @class(['py-1', 'text-amber-700 dark:text-amber-300' => $row['flag'] !== null])>{{ $row['flag'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    </div>
</section>
