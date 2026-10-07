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
                    <th class="px-3 py-2 text-right font-medium">Okunacak</th>
                    <th class="px-3 py-2 text-right font-medium">Gönderildi (30 gün)</th>
                    <th class="px-3 py-2 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($coverage as $row)
                    <tr wire:key="coverage-{{ $row['site']->id }}" data-coverage-site="{{ $row['site']->id }}">
                        <td class="px-3 py-2">
                            <button type="button" wire:click="showBrand({{ $row['brand_id'] }})" class="font-medium text-gray-900 hover:underline dark:text-white">{{ $row['brand'] }}</button>
                            <span class="block text-xs text-gray-500">{{ \Illuminate\Support\Str::limit((string) $row['site']->name, 40) }}</span>
                            @if ($row['reason'])<span class="block text-xs text-amber-700 dark:text-amber-300">{{ $row['reason'] }}</span>@endif
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
                        <td @class(['px-3 py-2 text-right tabular-nums', 'font-semibold text-amber-700 dark:text-amber-300' => $row['reading'] > 0])>{{ $row['reading'] }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $row['sent'] }}</td>
                        <td class="px-3 py-2 text-right">
                            @if ($row['clusters'] > 0 && min($row['pool'] ?: [0]) < \App\Services\Work\ContentCoverage::POOL)
                                <button type="button" wire:click="makeTitles({{ $row['site']->id }})" wire:loading.attr="disabled" class="whitespace-nowrap text-xs font-semibold text-brand-600 hover:underline" data-make-titles>Fikir üret</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="border-t border-gray-100 px-3 py-2 text-xs text-gray-500 dark:border-gray-800">Her sabah her sitenin her aktif dilindeki fikir havuzu {{ \App\Services\Work\ContentCoverage::POOL }}'ye tamamlanır; pazartesi üstüne haftalık yeni fikirler, ayın ilk haftası kümeler dışı fırsatlar eklenir. Fikirler kümelerin gerçek aramalarına, eksiklerine ve AI asistanı sorularına dayanır; yazı yalnız onayınızla yazılır.</p>
    </div>
@endif
