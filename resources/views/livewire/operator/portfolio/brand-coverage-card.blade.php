{{-- Marka › Özet › Küme kapsaması (yakup, 2026-10-26): which clusters of each service have a page, which got an article, what comes next. --}}
@php
    $num = fn ($n) => number_format((int) $n, 0, ',', '.');
    $stateTone = fn (string $state): string => match (true) {
        $state === 'sufficient' => 'ok',
        $state === 'no_page' => 'look',
        default => 'info',
    };
    $contentTone = ['sent' => 'text-success-700 dark:text-success-400', 'ready' => 'text-warning-700 dark:text-warning-400', 'writing' => 'text-gray-700 dark:text-gray-300',
        'idea' => 'text-gray-700 dark:text-gray-300', 'covered' => 'text-gray-500', 'none' => 'text-gray-400'];
@endphp
<section data-brand-coverage>
    <div class="section-head">
        <h2 class="section-title">Küme kapsaması</h2>
        <span class="text-sm text-gray-500">her hizmetin kümelerinden kaçının yeterli sayfası var, hangilerine yazı planlandı (ana dilde)</span>
    </div>
    @if ($sites === [])
        <div class="card"><p class="px-4 py-3 text-[13px] text-gray-500" data-coverage-empty>Markanın sitesine henüz küme eşlenmedi. Hizmetler kataloğa bağlanınca ve kümeler eşlenince burada görünür.</p></div>
    @endif
    <div class="space-y-4">
        @foreach ($sites as $row)
            @php
                $site = $row['site'];
                $pct = fn (int $n): float => $row['total'] > 0 ? round(100 * $n / $row['total'], 1) : 0;
            @endphp
            <div class="card" wire:key="coverage-site-{{ $site->id }}" data-coverage-site="{{ $site->id }}">
                <div class="space-y-2 px-4 py-3.5">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <a href="{{ route('operator.website', ['assetId' => $site->id, 'tab' => 'kumeler']) }}" wire:navigate class="text-sm font-medium text-gray-900 hover:underline dark:text-white">{{ $site->domain ?: $site->name }}</a>
                        <span class="text-[13px] tabular-nums text-gray-500" data-coverage-totals>{{ $num($row['total']) }} küme · {{ $num($row['ok']) }} yeterli · {{ $num($row['thin']) }} kapsam yetersiz · {{ $num($row['none']) }} sayfa yok</span>
                        <span class="text-[13px] tabular-nums text-gray-500" data-coverage-content>{{ $num($row['sent']) }} kümeye yazı gönderildi, {{ $num($row['planned']) }} kümeye yazı planlandı</span>
                    </div>
                    <div class="flex h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800" aria-hidden="true">
                        <span class="block h-full bg-success-500" style="width: {{ $pct($row['ok']) }}%"></span>
                        <span class="block h-full bg-warning-400" style="width: {{ $pct($row['thin']) }}%"></span>
                        <span class="block h-full bg-gray-300 dark:bg-gray-600" style="width: {{ $pct($row['none']) }}%"></span>
                    </div>
                    <p class="text-[13px] text-gray-600 dark:text-gray-300" data-coverage-next>
                        @if ($row['active'] !== [])Şu an yazılan: <span class="font-medium">{{ implode(', ', $row['active']) }}</span>@else Haftalık döngü henüz bir hizmet seçmedi @endif
                        @if ($row['next'])<span class="text-gray-400"> · </span>Sıradaki: <span class="font-medium">{{ $row['next'] }}</span>@endif
                        <span class="text-gray-400"> · </span><span class="text-gray-500" title="Kümelerin hepsi dolunca haftalık kota güncellemeye geçer: zayıf sayfalar, Google'da 4-20. sıradaki aramalar ve son 90 günde tıklaması düşen yazılar.">Kümeler dolunca: güncelleme ve trafiği düşen yazıların yenilenmesi</span>
                    </p>
                </div>
                <div class="overflow-x-auto border-t border-gray-200 dark:border-gray-800">
                    <table class="data-table min-w-[44rem]">
                        <thead>
                            <tr>
                                <th>Hizmet</th>
                                <th class="text-right">Küme</th>
                                <th class="text-right">Yeterli</th>
                                <th class="text-right" title="Sayfa var ama konuyu yeterince karşılamıyor, zayıf ya da çakışıyor">Yetersiz</th>
                                <th class="text-right">Sayfa yok</th>
                                <th class="text-right" title="Gönderilen yazı / planlanan (fikir, yazılıyor ya da gönderime hazır)">Yazı</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($row['services'] as $service)
                                @php $key = $site->id.':'.$service['service_id']; @endphp
                                <tr wire:key="coverage-{{ $key }}" data-coverage-service="{{ $service['service_id'] }}">
                                    <td class="min-w-52">
                                        <span class="font-medium text-gray-900 dark:text-white">@if ($service['main'])★ @endif{{ $service['service'] }}</span>
                                        @if ($service['active'])<x-ta.pill class="ml-1" tone="info">Yazılıyor{{ $service['map'] !== null ? ' %'.$service['map'] : '' }}</x-ta.pill>@endif
                                    </td>
                                    <td class="text-right tabular-nums">{{ $num($service['total']) }}</td>
                                    <td class="text-right tabular-nums">{{ $num($service['ok']) }}</td>
                                    <td class="text-right tabular-nums">{{ $num($service['thin']) }}</td>
                                    <td @class(['text-right tabular-nums', 'font-medium text-gray-900 dark:text-white' => $service['none'] > 0])>{{ $num($service['none']) }}</td>
                                    <td class="whitespace-nowrap text-right tabular-nums">{{ $num($service['sent']) }} / {{ $num($service['planned']) }}</td>
                                    <td class="text-right"><button type="button" wire:click="toggle({{ $site->id }}, {{ $service['service_id'] }})" class="btn-quiet" data-coverage-toggle>{{ $open === $key ? 'Gizle' : 'Kümeler' }}</button></td>
                                </tr>
                                @if ($open === $key)
                                    <tr wire:key="coverage-open-{{ $key }}">
                                        <td colspan="7" class="bg-gray-50/60 p-0! dark:bg-white/[0.02]">
                                            <ul class="divide-y divide-gray-200 dark:divide-gray-800" data-coverage-clusters>
                                                @foreach ($clusters as $cluster)
                                                    <li class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 px-4 py-2 text-[13px]" data-coverage-cluster="{{ $cluster['cluster_id'] }}">
                                                        <span class="min-w-0 flex-1 text-gray-900 dark:text-white">{{ $cluster['name'] }}</span>
                                                        <span class="tabular-nums text-gray-500" title="Sorgu kütüphanesindeki gösterim + aylık arama + sitenin 28 günlük gösterimi">{{ $num($cluster['demand']) }} talep</span>
                                                        <x-ta.pill :tone="$stateTone($cluster['state'])">{{ $cluster['state_label'] }}</x-ta.pill>
                                                        @if ($cluster['page_url'])<a href="{{ $cluster['page_url'] }}" target="_blank" rel="noopener" class="max-w-64 truncate text-gray-500 hover:underline" title="{{ $cluster['page_title'] }}">{{ \App\Services\SeoTasks\SeoText::urlPath((string) $cluster['page_url']) }} ↗</a>@endif
                                                        <span class="{{ $contentTone[$cluster['content']] ?? 'text-gray-500' }}" data-coverage-line="{{ $cluster['content'] }}">{{ $cluster['content_label'] }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    </div>
</section>
