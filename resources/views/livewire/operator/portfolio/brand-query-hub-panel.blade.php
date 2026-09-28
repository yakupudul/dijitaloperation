<section class="space-y-4 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" aria-labelledby="brand-query-hub-heading">
    <div>
        <h2 id="brand-query-hub-heading" class="font-semibold text-gray-800 dark:text-white/90">Sorgu merkezi</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Markanın tüm sorguları tek listede: kaynak, hizmet, sektör, niyet ve ilgililik. Belirsizleri hizmete atayın, alakasızları işaretleyin; onayladığınız atamalar haftalık yenilemede korunur.
        </p>
    </div>

    @if ($message !== '')
        <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>
    @endif

    <div class="flex flex-wrap gap-2 text-xs">
        <button type="button" wire:click="$set('relevance', 'unclear')" class="rounded-full px-3 py-1 ring-1 ring-inset {{ $relevance === 'unclear' ? 'bg-amber-50 text-amber-800 ring-amber-300' : 'text-gray-600 ring-gray-200 dark:text-gray-300 dark:ring-gray-700' }}">Belirsiz: {{ $relevanceCounts['unclear'] ?? 0 }}</button>
        <button type="button" wire:click="$set('relevance', 'relevant')" class="rounded-full px-3 py-1 ring-1 ring-inset {{ $relevance === 'relevant' ? 'bg-emerald-50 text-emerald-800 ring-emerald-300' : 'text-gray-600 ring-gray-200 dark:text-gray-300 dark:ring-gray-700' }}">İlgili: {{ $relevanceCounts['relevant'] ?? 0 }}</button>
        <button type="button" wire:click="$set('relevance', 'irrelevant')" class="rounded-full px-3 py-1 ring-1 ring-inset {{ $relevance === 'irrelevant' ? 'bg-rose-50 text-rose-800 ring-rose-300' : 'text-gray-600 ring-gray-200 dark:text-gray-300 dark:ring-gray-700' }}">Alakasız: {{ $relevanceCounts['irrelevant'] ?? 0 }}</button>
    </div>

    @if ($serviceCounts !== [])
        <div class="flex flex-wrap gap-2 text-xs" aria-label="Hizmete göre sorgu sayısı">
            @foreach ($serviceCounts as $service)
                <button type="button" wire:key="hub-service-{{ $service['id'] }}" wire:click="showService('{{ $service['id'] }}')"
                    class="rounded-lg px-2.5 py-1 ring-1 ring-inset {{ $offering === $service['id'] ? 'bg-brand-50 text-brand-700 ring-brand-300' : 'text-gray-700 ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700' }}">
                    {{ $service['name'] }} <span class="tabular-nums text-gray-500">{{ $service['total'] }}</span>
                </button>
            @endforeach
        </div>
    @endif

    <div class="grid gap-2 sm:grid-cols-3 lg:grid-cols-6">
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Sorgu ara…" aria-label="Sorgu ara" class="rounded-lg border border-gray-200 bg-transparent px-3 py-1.5 text-sm dark:border-gray-700 dark:text-white" />
        <select wire:model.live="source" aria-label="Kaynak" class="rounded-lg border border-gray-200 bg-transparent px-2 py-1.5 text-sm dark:border-gray-700 dark:text-white">
            <option value="">Tüm kaynaklar</option>
            @foreach ($sourceLabels as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model.live="offering" aria-label="Hizmet" class="rounded-lg border border-gray-200 bg-transparent px-2 py-1.5 text-sm dark:border-gray-700 dark:text-white">
            <option value="">Tüm hizmetler</option>
            <option value="none">Hizmet yok</option>
            @foreach ($offerings as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
        <select wire:model.live="relevance" aria-label="İlgililik" class="rounded-lg border border-gray-200 bg-transparent px-2 py-1.5 text-sm dark:border-gray-700 dark:text-white">
            <option value="">Alakasız hariç</option>
            <option value="all">Tümü</option>
            @foreach ($relevanceLabels as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model.live="branded" aria-label="Markalı" class="rounded-lg border border-gray-200 bg-transparent px-2 py-1.5 text-sm dark:border-gray-700 dark:text-white">
            <option value="">Markalı + markasız</option>
            <option value="1">Markalı</option>
            <option value="0">Markasız</option>
        </select>
        @if ($sites->count() > 1)
            <select wire:model.live="site" aria-label="Web sitesi" class="rounded-lg border border-gray-200 bg-transparent px-2 py-1.5 text-sm dark:border-gray-700 dark:text-white">
                <option value="">Tüm siteler</option>
                @foreach ($sites as $siteOption)
                    <option value="{{ $siteOption->id }}">{{ $siteOption->domain ?: $siteOption->primary_url }}</option>
                @endforeach
            </select>
        @endif
    </div>

    <div class="flex flex-wrap items-center gap-3 text-xs text-gray-500">
        <span>{{ $rows->total() }} sorgu · {{ count($selected) }} seçili</span>
        <button type="button" wire:click="selectPage" class="text-brand-600">Bu sayfayı seç</button>
        <button type="button" wire:click="$set('selected', [])">Seçimi kaldır</button>
    </div>

    @if (count($selected) > 0)
        <div class="flex flex-wrap items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/[0.03]">
            <select wire:model="bulkOffering" aria-label="Atanacak hizmet" class="rounded-lg border border-gray-200 bg-transparent px-2 py-1 text-sm dark:border-gray-700 dark:text-white">
                <option value="">Hizmet seçin…</option>
                @foreach ($offerings as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
            <button type="button" wire:click="bulkAssign" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-3 py-1 font-medium text-white hover:bg-brand-600">Hizmete ata</button>
            <button type="button" wire:click="bulkConfirm" wire:loading.attr="disabled" class="rounded-lg px-3 py-1 font-medium text-gray-700 ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700">Onayla</button>
            <button type="button" wire:click="bulkIrrelevant" wire:loading.attr="disabled" class="rounded-lg px-3 py-1 font-medium text-rose-600 ring-1 ring-inset ring-rose-200 dark:ring-rose-500/30">Alakasız işaretle</button>
        </div>
    @endif

    @if ($rows->total() === 0)
        <p class="text-sm text-gray-500">Bu filtrelerle sorgu yok. Hesaplar bağlandıktan ve veri geldikten sonra (ya da sorgu portföyü doldurulunca) sorgular burada görünür.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-gray-400">
                    <tr>
                        <th class="py-2 pr-2"><span class="sr-only">Seç</span></th>
                        <th class="py-2 pr-3">Sorgu</th>
                        <th class="py-2 pr-3">Hizmet</th>
                        <th class="py-2 pr-3">Kaynak</th>
                        <th class="py-2 pr-3 text-right">Tık / gösterim</th>
                        <th class="py-2 pr-3 text-right">Sıra</th>
                        <th class="py-2 pr-3 text-right">Ads</th>
                        <th class="py-2 pr-3 text-right">Hacim</th>
                        <th class="py-2 text-right">Eğilim</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($rows as $row)
                        <tr wire:key="hub-row-{{ $row->id }}" data-hub-row="{{ $row->id }}">
                            <td class="py-2 pr-2 align-top"><input type="checkbox" wire:model.live="selected" value="{{ $row->id }}" aria-label="{{ $row->query }} seç" class="rounded border-gray-300 text-brand-500" /></td>
                            <td class="py-2 pr-3 align-top">
                                <span class="text-gray-800 dark:text-gray-200">{{ $row->query }}</span>
                                <span class="mt-0.5 flex flex-wrap gap-1 text-[11px]">
                                    @if ($row->is_branded)<span class="rounded bg-indigo-50 px-1.5 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">markalı</span>@endif
                                    @if ($row->relevance === 'unclear')<span class="rounded bg-amber-50 px-1.5 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">belirsiz</span>@endif
                                    @if ($row->relevance === 'irrelevant')<span class="rounded bg-rose-50 px-1.5 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">alakasız</span>@endif
                                    @if ($row->intent)<span class="text-gray-400">{{ ['transactional' => 'işlem', 'local' => 'yerel', 'commercial' => 'karşılaştırma', 'informational' => 'bilgi', 'navigational' => 'yönlendirme'][$row->intent] ?? $row->intent }}</span>@endif
                                    @if ($row->sector)<span class="text-gray-400">· {{ $sectorNames[$row->sector] ?? $row->sector }}</span>@endif
                                </span>
                            </td>
                            <td class="py-2 pr-3 align-top">
                                @if ($row->brand_offering_id !== null)
                                    <span class="text-gray-800 dark:text-gray-200">{{ $offerings[$row->brand_offering_id] ?? 'Hizmet #'.$row->brand_offering_id }}</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                                @if ($row->assignment_method)
                                    <span class="block text-[11px] text-gray-400">{{ $methodLabels[$row->assignment_method] ?? $row->assignment_method }}@if ($row->assignment_confidence !== null && $row->assignment_method !== 'operator') · %{{ (int) round($row->assignment_confidence * 100) }}@endif @if ($row->assignment_source === 'operator') · onaylı @endif</span>
                                @endif
                            </td>
                            <td class="py-2 pr-3 align-top text-xs text-gray-500">{{ collect((array) $row->sources)->map(fn ($s) => $sourceLabels[$s] ?? $s)->implode(', ') }}</td>
                            <td class="py-2 pr-3 text-right align-top tabular-nums">{{ number_format((int) $row->gsc_clicks, 0, ',', '.') }} / {{ number_format((int) $row->gsc_impressions, 0, ',', '.') }}</td>
                            <td class="py-2 pr-3 text-right align-top tabular-nums">{{ $row->gsc_position !== null ? number_format($row->gsc_position, 1, ',', '.') : '—' }}</td>
                            <td class="py-2 pr-3 text-right align-top tabular-nums">{{ (int) $row->ads_clicks }} tık · {{ number_format((float) $row->ads_conversions, 1, ',', '.') }} dön.</td>
                            <td class="py-2 pr-3 text-right align-top tabular-nums">{{ $row->search_volume !== null ? number_format((int) $row->search_volume, 0, ',', '.') : ($row->gbp_impressions > 0 ? 'İP '.number_format((int) $row->gbp_impressions, 0, ',', '.') : '—') }}</td>
                            <td class="py-2 text-right align-top text-xs">
                                @switch($row->trend())
                                    @case('rising') <span class="text-emerald-600">↑ artıyor</span> @break
                                    @case('falling') <span class="text-rose-600">↓ düşüyor</span> @break
                                    @case('flat') <span class="text-gray-500">→ sabit</span> @break
                                    @case('new') <span class="text-brand-600">yeni</span> @break
                                    @default <span class="text-gray-400">—</span>
                                @endswitch
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $rows->links() }}
    @endif
</section>
