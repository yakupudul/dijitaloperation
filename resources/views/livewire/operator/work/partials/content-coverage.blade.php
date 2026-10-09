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
                    <th class="px-3 py-2 font-medium" title="Onay bekleyen fikirler / havuz hedefi, sitenin her aktif dili için">Fikir havuzu (dil başına {{ \App\Services\Work\ContentCoverage::POOL }})</th>
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
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap gap-1" data-pool>
                                @foreach ($row['pool'] as $code => $waiting)
                                    <span @class(['rounded px-1.5 py-0.5 text-[11px] font-semibold tabular-nums', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $waiting >= \App\Services\Work\ContentCoverage::POOL, 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => $waiting > 0 && $waiting < \App\Services\Work\ContentCoverage::POOL, 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => $waiting === 0]) title="{{ \App\Services\Work\ContentBoard::LANGUAGE_LABELS[$code] ?? $code }}">{{ strtoupper($code) }} {{ $waiting }}/{{ \App\Services\Work\ContentCoverage::POOL }}</span>
                                @endforeach
                            </div>
                            @if ($row['translated'] !== [])<span class="block text-[11px] text-gray-400" data-translated>{{ implode(', ', array_map('strtoupper', $row['translated'])) }}: yazılınca çevrilir</span>@endif
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
        <p class="border-t border-gray-100 px-3 py-2 text-xs text-gray-500 dark:border-gray-800">Havuz günde iki kez (09:17, 15:17) kendiliğinden {{ \App\Services\Work\ContentCoverage::POOL }} fikre tamamlanır; pazartesi üstüne haftalık yeni fikirler, ayın ilk haftası kümeler dışı fırsatlar eklenir. Konuyu veri seçer: sitenin 4–20. sırada kendi sayfası olmadan göründüğü aramalar, sayfası olmayan ya da zayıf kümeler, güçlendirilecek sayfalar ve AI asistanı soruları (karışım yaklaşık %60 yeni yazı, %25 güncelleme, %15 AI sorusu); AI yalnız başlığı ve taslağı yazar. Daha önce tıklama getiren yazı türleri öne geçer. Yazı yalnız onayınızla yazılır.</p>
    </div>
@endif
