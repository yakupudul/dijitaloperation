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
                    <th class="px-3 py-2 text-right font-medium">Onay bekleyen</th>
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
                        <td class="px-3 py-2 text-right tabular-nums">{{ $row['waiting'] }}<span class="text-xs text-gray-400"> / {{ $row['capacity'] }}</span></td>
                        <td @class(['px-3 py-2 text-right tabular-nums', 'font-semibold text-amber-700 dark:text-amber-300' => $row['reading'] > 0])>{{ $row['reading'] }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $row['sent'] }}</td>
                        <td class="px-3 py-2 text-right">
                            @if ($row['missing'] > 0 && $row['waiting'] < $row['capacity'])
                                <button type="button" wire:click="makeTitles({{ $row['site']->id }})" wire:loading.attr="disabled" class="whitespace-nowrap text-xs font-semibold text-brand-600 hover:underline" data-make-titles>Başlık üret</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="border-t border-gray-100 px-3 py-2 text-xs text-gray-500 dark:border-gray-800">Başlıklar her pazartesi eksik kümesi olan ve onay bekleyen başlığı haftalık kapasitesinin altında kalan sitelere kendiliğinden üretilir; yazı yalnız onayınızla yazılır.</p>
    </div>
@endif
