{{-- Web site SEO içerikler › marka tablosu: how each brand's clusters are answered on its site and where its titles stand. --}}
@if ($coverage !== [])
    <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-content-coverage>
        <table class="min-w-full text-sm">
            <thead class="text-left text-xs text-gray-500">
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    <th class="px-3 py-2 font-medium">Marka · site</th>
                    <th class="px-3 py-2 text-right font-medium" title="Sitenin eşleştirilmiş kümeleri">Küme</th>
                    <th class="px-3 py-2 text-right font-medium" title="Uygun sayfa yok ya da kapsam yetersiz: yeni yazı / güncelleme gerekiyor">Eksik</th>
                    <th class="px-3 py-2 text-right font-medium" title="Sayfa var ama performansı zayıf, yanlış sayfa görünüyor ya da çakışma olabilir">Zayıf</th>
                    <th class="px-3 py-2 text-right font-medium">Yeterli</th>
                    <th class="px-3 py-2 font-medium" title="Sitenin aşamasına göre bu haftanın yeni yazı ve güncelleme sayısı (ana dil; çeviriler ayrıca), şu an yazılan hizmet ve konu haritasının ne kadarı bitti">Bu hafta</th>
                    <th class="px-3 py-2 text-right font-medium" title="Onaylandı, Claude yazıyor">Yazılıyor</th>
                    <th class="px-3 py-2 text-right font-medium">Okunacak</th>
                    <th class="px-3 py-2 text-right font-medium">Gönderildi (30 gün)</th>
                    <th class="px-3 py-2 text-right font-medium" title="Son {{ \App\Services\Work\ContentCoverage::OUTCOME_DAYS }} günde taslak gönderilen yazılardan sitede yayında olanlar ve Search Console tıklaması (28 / 90 gün)">Sonuç</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($coverage as $row)
                    <tr wire:key="coverage-{{ $row['site']->id }}" data-coverage-site="{{ $row['site']->id }}">
                        <td class="px-3 py-2">
                            <button type="button" wire:click="showBrand({{ $row['brand_id'] }})" class="font-medium text-gray-900 hover:underline dark:text-white">{{ $row['brand'] }}</button>
                            <span class="block text-xs text-gray-500">{{ \Illuminate\Support\Str::limit((string) $row['site']->name, 40) }}</span>
                            @php $stage = \App\Services\Work\ContentCoverage::stage($row); @endphp
                            <span @class(['mt-0.5 inline-block rounded px-1.5 py-0.5 text-[11px] font-semibold', 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => $stage['tone'] === 'amber', 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300' => $stage['tone'] === 'sky', 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => $stage['tone'] === 'rose', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $stage['tone'] === 'emerald']) data-stage>{{ $stage['label'] }}</span>
                            @if ($row['reason'])<span class="block text-xs text-amber-700 dark:text-amber-300">{{ $row['reason'] }}</span>@endif
                            @if ($line = \App\Services\Work\ContentCoverage::runLine($row['last_run'] ?? null))<span class="block text-[11px] text-gray-500" data-last-run>{{ $line }}</span>@endif
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $row['clusters'] }}</td>
                        <td @class(['px-3 py-2 text-right tabular-nums', 'font-semibold text-rose-700 dark:text-rose-300' => $row['missing'] > 0])>{{ $row['missing'] }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $row['weak'] }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-emerald-700 dark:text-emerald-400">{{ $row['ok'] }}</td>
                        <td class="px-3 py-2 text-xs" data-week>
                            <span class="font-semibold tabular-nums">{{ $row['rhythm']['new'] }} yeni + {{ $row['rhythm']['update'] }} güncelleme</span>
                            <span class="block text-gray-500">{{ $row['rhythm']['label'] }} · kümelerin %{{ $row['rhythm']['filled'] }}'i yeterli</span>
                            @if ($row['map'] !== [])<span class="block text-gray-500" data-active-service>Yazılan: {{ implode(', ', $row['map']) }}</span>@endif
                            @if (($row['backfill'] ?? null) === \App\Models\ContentSiteState::BACKFILL_ELIGIBLE)<span class="mt-0.5 inline-block rounded bg-amber-50 px-1.5 py-0.5 text-[11px] font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-300" data-backfill>Geriye dönük doldurma · havuz {{ $row['waiting'] }}/{{ \App\Services\Work\ContentCoverage::POOL }}</span>@endif
                            @if ($row['translated'] !== [])<span class="block text-[11px] text-gray-400" data-translated>{{ implode(', ', array_map('strtoupper', $row['translated'])) }}: talep varsa yazılınca çevrilir</span>@endif
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $row['writing'] }}</td>
                        <td @class(['px-3 py-2 text-right tabular-nums', 'font-semibold text-amber-700 dark:text-amber-300' => $row['reading'] > 0])>{{ $row['reading'] }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $row['sent'] }}</td>
                        @php $outcome = $outcomes[$row['site']->id] ?? ['sent' => 0, 'live' => 0, 'clicks' => 0]; @endphp
                        <td class="px-3 py-2 text-right text-xs tabular-nums" data-outcome>@if ($outcome['sent'] > 0){{ $outcome['live'] }}/{{ $outcome['sent'] }} yayında<span class="block text-gray-500">{{ $outcome['clicks'] }} tıklama · 90 günde {{ $outcome['clicks90'] ?? 0 }}</span>@else<span class="text-gray-400">—</span>@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="border-t border-gray-100 px-3 py-2 text-xs text-gray-500 dark:border-gray-800">Haftalık döngü: salı akşamı sayfalar yeniden okunur ve kümelerle eşleşir; çarşamba sabahı her siteye aşamasına göre fikir açılır (harita boşsa 3 yeni yazı + 1 güncelleme, yarı doluysa 2 + 2, büyük ölçüde doluysa 1 + 3, dar yerel işte haftada 1). Yeni yazılar şu an yazılan hizmetin konu haritasından, yazım sırasıyla gelir (ana sayfa, karar soruları, süreç, korkular, yerel, mevsim, yurt dışı); bir hizmetin haritası bitmeden sıradakine geçilmez. Pazartesi 05:00 yazılmamış yeni yazılar yazılır, çevrilir ve kontrol edilir; Onarım masasındaki tek tıkla pazartesi, çarşamba ve cuma tarihli olarak siteye gider. Güncellemeler bu tıka girmez, elle gönderilir. Kanıtı olmayan konuya yazı açılmaz.</p>
    </div>
@endif
