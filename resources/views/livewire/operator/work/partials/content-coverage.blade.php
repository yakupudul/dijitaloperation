{{-- Web site SEO içerikler › marka tablosu: how each brand's clusters are answered on its site and where its titles stand. --}}
@if ($coverage !== [])
    <div class="card" data-content-coverage>
        <div class="overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Marka ve site</th>
                    <th title="Sitenin eşleştirilmiş kümelerinden kaçı yeterli bir sayfayla yanıtlanıyor; eksik: uygun sayfa yok, zayıf: sayfa var ama performansı zayıf ya da çakışma olabilir">Konu haritası</th>
                    <th title="Sitenin aşamasına göre bu haftanın yeni yazı ve güncelleme sayısı (ana dil; çeviriler ayrıca), şu an yazılan hizmet">Bu hafta</th>
                    <th title="Yazılıyor: onaylandı, Claude yazıyor. Okunacak: yazıldı, okumanı bekliyor. Gönderildi: son 30 gün.">Yazılar</th>
                    <th class="text-right" title="Son {{ \App\Services\Work\ContentCoverage::OUTCOME_DAYS }} günde taslak gönderilen yazılardan sitede yayında olanlar ve Search Console tıklaması (28 / 90 gün)">Sonuç</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($coverage as $row)
                    <tr class="[&>td]:align-top" wire:key="coverage-{{ $row['site']->id }}" data-coverage-site="{{ $row['site']->id }}">
                        <td class="min-w-60">
                            <button type="button" wire:click="showBrand({{ $row['brand_id'] }})" class="text-left font-medium text-gray-900 hover:underline dark:text-white">{{ $row['brand'] }}</button>
                            <span class="block text-xs text-gray-500">{{ \Illuminate\Support\Str::limit((string) $row['site']->name, 40) }}</span>
                            @php $stage = \App\Services\Work\ContentCoverage::stage($row); @endphp
                            <x-ta.pill class="mt-0.5" :tone="match ($stage['tone']) { 'amber' => 'look', 'rose' => 'look', 'emerald' => 'ok', default => 'info' }" data-stage>{{ $stage['label'] }}</x-ta.pill>
                            @if ($row['reason'])<span class="mt-0.5 block max-w-md text-xs text-gray-500 dark:text-gray-400">{{ $row['reason'] }}</span>@endif
                            @if ($line = \App\Services\Work\ContentCoverage::runLine($row['last_run'] ?? null))<span class="block text-xs text-gray-500" data-last-run>{{ $line }}</span>@endif
                        </td>
                        @php $filled = $row['clusters'] > 0 ? (int) round(100 * $row['ok'] / $row['clusters']) : 0; @endphp
                        <td class="min-w-[11rem] text-xs" data-map>
                            <span class="font-medium tabular-nums text-gray-900 dark:text-white">{{ $row['ok'] }}/{{ $row['clusters'] }} yeterli</span>
                            <span class="mt-1 block h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800"><span class="block h-full rounded-full bg-second" style="width: {{ $filled }}%"></span></span>
                            <span class="mt-1 block text-gray-500"><span @class(['text-warning-700 dark:text-warning-400' => $row['missing'] > 0])>{{ $row['missing'] }} eksik</span>, {{ $row['weak'] }} zayıf</span>
                        </td>
                        <td class="min-w-40 text-xs" data-week>
                            <span class="font-medium tabular-nums">{{ $row['rhythm']['new'] }} yeni + {{ $row['rhythm']['update'] }} güncelleme</span>
                            <span class="block text-gray-500">{{ $row['rhythm']['label'] }}</span>
                            @if ($row['map'] !== [])<span class="block text-gray-500" data-active-service>Yazılan: {{ implode(', ', $row['map']) }}</span>@endif
                            @if (($row['backfill'] ?? null) === \App\Models\ContentSiteState::BACKFILL_ELIGIBLE)<span class="mt-0.5 block text-xs text-warning-700 dark:text-warning-400" data-backfill>Geriye dönük doldurma, havuz {{ $row['waiting'] }}/{{ \App\Services\Work\ContentCoverage::POOL }}</span>@endif
                            @if ($row['translated'] !== [])<span class="block text-xs text-gray-500" data-translated>{{ implode(', ', array_map('strtoupper', $row['translated'])) }}: talep varsa yazılınca çevrilir</span>@endif
                        </td>
                        <td class="min-w-32 text-xs tabular-nums" data-flow>
                            @if ($row['reading'] > 0)<span class="block font-medium text-warning-700 dark:text-warning-400">{{ $row['reading'] }} okunacak</span>@endif
                            <span class="block text-gray-500">{{ $row['writing'] }} yazılıyor, {{ $row['sent'] }} gönderildi</span>
                        </td>
                        @php $outcome = $outcomes[$row['site']->id] ?? ['sent' => 0, 'live' => 0, 'clicks' => 0]; @endphp
                        <td class="whitespace-nowrap text-right text-xs tabular-nums" data-outcome>@if ($outcome['sent'] > 0){{ $outcome['live'] }}/{{ $outcome['sent'] }} yayında<span class="block text-gray-500">{{ $outcome['clicks'] }} tıklama, 90 günde {{ $outcome['clicks90'] ?? 0 }}</span>@else<span class="text-gray-500">—</span>@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        <details class="border-t border-gray-200 px-4 py-2.5 text-xs text-gray-500 dark:border-gray-800" data-cycle-help>
            <summary class="cursor-pointer text-[13px] text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Nasıl çalışır?</summary>
            <p class="mt-1.5 max-w-3xl leading-5">Haftalık döngü: salı akşamı sayfalar yeniden okunur ve kümelerle eşleşir; çarşamba sabahı her siteye aşamasına göre fikir açılır (harita boşsa 3 yeni yazı + 1 güncelleme, yarı doluysa 2 + 2, büyük ölçüde doluysa 1 + 3, dar yerel işte haftada 1). Yeni yazılar şu an yazılan hizmetin konu haritasından, yazım sırasıyla gelir (ana sayfa, karar soruları, süreç, korkular, yerel, mevsim, yurt dışı); bir hizmetin haritası bitmeden sıradakine geçilmez. Pazartesi 05:00 yazılmamış yeni yazılar yazılır, çevrilir ve kontrol edilir; Onarım masasındaki tek tıkla pazartesi, çarşamba ve cuma tarihli olarak siteye gider. Güncellemeler bu tıka girmez, elle gönderilir. Kanıtı olmayan konuya yazı açılmaz.</p>
        </details>
    </div>
@endif
