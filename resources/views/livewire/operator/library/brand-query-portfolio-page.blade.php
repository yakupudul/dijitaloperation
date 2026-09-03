<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-600">Kütüphane</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Marka Sorgu Portföyü</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">Global sorguları kopyalamadan markaya bağlar; marka override’larını, çoklu bölge kapsamını ve website etkinliğini ayrı tutar.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('operator.library.services') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Hizmetler</a>
            <a href="{{ route('operator.library.search-queries') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Global sorgular</a>
            <a href="{{ route('operator.library.search-demand-clusters', ['brand' => $selectedBrandId]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Sorgu kümeleri</a>
            <a href="{{ route('operator.library.search-demand-visibility') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Görünürlük haritası</a>
            <a href="{{ route('operator.library.search-demand-enrichment') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">SERP zenginleştirme</a>
        </div>
    </div>

    @if ($message !== '')
        <div class="rounded-lg border px-4 py-3 text-sm {{ $messageTone === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' }}">{{ $message }}</div>
    @endif

    <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="grid gap-4 lg:grid-cols-[1.2fr_1fr_auto] lg:items-end">
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-gray-500">Marka</span>
                <select wire:model.live="selectedBrandId" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
                    <option value="">Marka seçin</option>
                    @foreach ($brands as $brandOption)
                        <option value="{{ $brandOption->id }}">{{ $brandOption->name }}{{ $brandOption->customer ? ' · '.$brandOption->customer->name : '' }}</option>
                    @endforeach
                </select>
            </label>
            <div class="text-sm text-gray-500">
                @if ($brand)
                    <p><span class="font-medium text-gray-800 dark:text-gray-200">{{ $brand->name }}</span> · {{ $serviceOptions === [] ? 'Etkin katalog hizmeti yok' : count($serviceOptions).' etkin hizmet' }}</p>
                    <p class="mt-1">{{ $brand->serviceAreas->count() }} etkin hizmet bölgesi · {{ $brand->digitalAssets->count() }} website</p>
                @else
                    Portföyü yönetmek için bir marka seçin.
                @endif
            </div>
            <button wire:click="inheritQueries" wire:loading.attr="disabled" @disabled(! $brand) type="button" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">
                Global sorguları hizmetlerden uygula
            </button>
        </div>
        @error('selectedBrandId') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
        <p class="mt-3 text-xs text-gray-400">Bu işlem yalnız ilişkileri oluşturur. Hizmet×bölge sorgu satırları üretmez; `{location}` varyantları gerektiğinde hesaplanır.</p>
    </section>

    @if ($brand)
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([['Toplam portföy', $summary['total']], ['Etkin', $summary['active']], ['Markaya özel', $summary['custom']], ['Global öneri bekleyen', $summary['proposals']]] as [$label, $value])
                <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><p class="text-xs text-gray-500">{{ $label }}</p><p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($value, 0, ',', '.') }}</p></div>
            @endforeach
        </div>

        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Markaya özel sorgu ekle</h2>
            <p class="mt-1 text-sm text-gray-500">Bu kayıt markada kalır. İsterseniz daha sonra ayrı bir insan incelemesi için global kütüphaneye önerebilirsiniz.</p>
            <form wire:submit="addBrandQuery" class="mt-4 grid gap-3 lg:grid-cols-4">
                <textarea wire:model="brandQueryText" rows="3" placeholder="Sorgu metni" class="rounded-lg border-gray-300 text-sm lg:col-span-2 dark:border-gray-700 dark:bg-gray-950"></textarea>
                <select wire:model="brandQueryServiceId" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Hizmet (isteğe bağlı)</option>@foreach($serviceOptions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>
                <input wire:model="brandQueryDemandFamily" type="text" placeholder="Talep ailesi" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
                <input wire:model="brandQueryLanguage" type="text" placeholder="Dil: tr" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
                <input wire:model="brandQueryMarket" type="text" placeholder="Pazar: TR" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
                <select wire:model="brandQueryLocationScope" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="none">Lokasyon yok</option><option value="country">Ülke</option><option value="city">Şehir</option><option value="district">İlçe</option><option value="pattern">{location} kalıbı</option></select>
                <input wire:model="brandQueryLocationValue" type="text" placeholder="Örn. {location} implant" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
                <label class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300"><input wire:model="brandQueryIsBranded" type="checkbox" class="rounded border-gray-300 text-brand-500" /> Markalı/lisanslı</label>
                <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50 dark:bg-white dark:text-gray-900">Marka portföyüne ekle</button>
            </form>
            @error('brandQueryText') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
            @error('brandQueryServiceId') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
        </section>

        @if ($editingPortfolioItemId !== null)
            <section class="rounded-xl border border-brand-200 bg-brand-50/50 p-5 dark:border-brand-500/30 dark:bg-brand-500/5">
                <div class="flex items-center justify-between gap-3"><div><h2 class="text-base font-semibold text-gray-900 dark:text-white">Marka override’ları</h2><p class="mt-1 text-sm text-gray-500">Boş alanlar global/kaynak değerini miras alır; global kayıt değiştirilmez.</p></div><button wire:click="cancelOverrides" type="button" class="text-sm text-gray-500">Kapat</button></div>
                <form wire:submit="saveOverrides" class="mt-4 grid gap-3 lg:grid-cols-4">
                    <textarea wire:model="overrideQueryText" rows="2" placeholder="Sorgu metni override (boş = miras)" class="rounded-lg border-gray-300 text-sm lg:col-span-2 dark:border-gray-700 dark:bg-gray-950"></textarea>
                    <input wire:model="overrideDemandFamily" type="text" placeholder="Talep ailesi override" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
                    <select wire:model="overrideIsBranded" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="inherit">Marka durumu: miras al</option><option value="yes">Markalı: evet</option><option value="no">Markalı: hayır</option></select>
                    <input wire:model="overrideLanguage" type="text" placeholder="Dil override" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
                    <input wire:model="overrideMarket" type="text" placeholder="Pazar override" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
                    <select wire:model="overrideLocationScope" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Lokasyon: miras al</option><option value="none">Yok</option><option value="country">Ülke</option><option value="city">Şehir</option><option value="district">İlçe</option><option value="pattern">{location} kalıbı</option></select>
                    <input wire:model="overrideLocationValue" type="text" placeholder="Lokasyon kalıbı override" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
                    <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white">Override’ları kaydet</button>
                </form>
            </section>
        @endif

        @if ($areaPortfolioItemId !== null)
            <section class="rounded-xl border border-sky-200 bg-sky-50/50 p-5 dark:border-sky-500/30 dark:bg-sky-500/5">
                <div class="flex items-center justify-between gap-3"><div><h2 class="text-base font-semibold text-gray-900 dark:text-white">Bölge kapsamı</h2><p class="mt-1 text-sm text-gray-500">Tüm marka bölgelerini dinamik kullanın veya yalnız seçilen bölgelere sınırlandırın.</p></div><button wire:click="cancelAreas" type="button" class="text-sm text-gray-500">Kapat</button></div>
                <form wire:submit="saveAreas" class="mt-4 space-y-3">
                    <div class="flex flex-wrap gap-4 text-sm"><label class="flex items-center gap-2"><input wire:model.live="areaMode" value="all" type="radio" /> Tüm etkin marka bölgeleri</label><label class="flex items-center gap-2"><input wire:model.live="areaMode" value="selected" type="radio" /> Yalnız seçilen bölgeler</label></div>
                    @if ($areaMode === 'selected')
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                            @forelse ($brand->serviceAreas as $area)
                                <label class="flex items-center gap-2 rounded-lg border border-sky-100 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"><input wire:model="selectedAreaIds" value="{{ $area->id }}" type="checkbox" /> {{ $area->label() }}</label>
                            @empty
                                <p class="text-sm text-amber-700">Bu marka için etkin hizmet bölgesi yok.</p>
                            @endforelse
                        </div>
                    @endif
                    @error('selectedAreaIds') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    <button type="submit" class="rounded-lg bg-sky-600 px-4 py-2.5 text-sm font-semibold text-white">Bölge kapsamını kaydet</button>
                </form>
            </section>
        @endif

        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="grid gap-3 border-b border-gray-100 p-4 lg:grid-cols-[1.2fr_0.8fr_1fr_auto] dark:border-gray-800">
                <input wire:model.live.debounce.350ms="search" type="search" placeholder="Portföy sorgusu ara" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
                <select wire:model.live="portfolioStatus" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="active">Etkin</option><option value="excluded">Hariç</option><option value="archived">Arşiv</option><option value="all">Tümü</option></select>
                <select wire:model.live="selectedWebsiteId" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Website etkinliği için website seçin</option>@foreach($brand->digitalAssets as $website)<option value="{{ $website->id }}">{{ $website->name }}{{ $website->domain ? ' · '.$website->domain : '' }}</option>@endforeach</select>
                <div class="flex items-center text-xs text-gray-500">{{ $items->count() }} kayıt gösteriliyor</div>
            </div>
            @error('selectedWebsiteId') <p class="px-4 pt-2 text-xs text-red-600">{{ $message }}</p> @enderror

            <div class="overflow-x-auto">
                <table class="min-w-full text-xs">
                    <thead class="bg-gray-50 text-left text-gray-500 dark:bg-white/[0.02]"><tr><th class="px-4 py-3">Sorgu / kapsam</th><th class="px-4 py-3">Hizmet / kaynak</th><th class="px-4 py-3">Dinamik bölge varyantı</th><th class="px-4 py-3">Website</th><th class="px-4 py-3">İşlemler</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($items as $item)
                            @php
                                $assetState = $selectedWebsiteId !== '' ? $item->assetStates->firstWhere('digital_asset_id', (int) $selectedWebsiteId) : null;
                                $variants = $item->getAttribute('rendered_location_variants') ?? [];
                            @endphp
                            <tr class="align-top hover:bg-gray-50/60 dark:hover:bg-white/[0.02]">
                                <td class="max-w-md px-4 py-3">
                                    <div class="font-medium text-gray-900 dark:text-white">{{ $item->effectiveQueryText() }}</div>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-600 dark:bg-gray-800">{{ $item->status }}</span>
                                        @if($item->query_text_override || $item->demand_family_override || $item->location_scope_override !== null)<span class="rounded bg-brand-50 px-1.5 py-0.5 text-[10px] text-brand-700">marka override</span>@endif
                                        @if($item->effectiveIsBranded())<span class="rounded bg-red-50 px-1.5 py-0.5 text-[10px] text-red-700">markalı</span>@endif
                                        <span class="rounded bg-violet-50 px-1.5 py-0.5 text-[10px] text-violet-700">{{ $item->effectiveDemandFamily() ?: 'aile bekliyor' }}</span>
                                    </div>
                                    <div class="mt-1 text-[10px] text-gray-400">{{ $item->effectiveMarketCode() ?: '—' }} · {{ $item->effectiveLanguageCode() ?: '—' }} · Intelligence #{{ $item->intelligence_search_term_identity_id ?: '—' }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-gray-700 dark:text-gray-300">{{ $item->services->map(fn($service) => $service->primaryName?->raw_label)->filter()->implode(' · ') ?: 'Hizmet atanmamış' }}</div>
                                    <div class="mt-1 text-gray-400">{{ $item->origin_type === 'global_inherited' ? 'Global ilişki #'.$item->search_query_library_item_id : 'Markaya özel' }}</div>
                                    @if($item->global_proposal_status === 'submitted')<div class="mt-1 text-[10px] text-amber-700">Global incelemeye önerildi</div>@endif
                                </td>
                                <td class="max-w-sm px-4 py-3">
                                    <div class="text-gray-600 dark:text-gray-300">{{ $item->area_scope === 'selected_areas' ? $item->serviceAreas->count().' seçili bölge' : 'Tüm etkin marka bölgeleri' }}</div>
                                    @if($item->effectiveLocationScope() === 'pattern')
                                        <div class="mt-1 space-y-0.5 text-[10px] text-gray-400">
                                            @forelse(array_slice($variants, 0, 3) as $variant)<div>{{ $variant['query'] }}</div>@empty<div>Uygulanabilir etkin bölge yok</div>@endforelse
                                            @if(count($variants) > 3)<div>+{{ count($variants) - 3 }} varyant</div>@endif
                                        </div>
                                    @else
                                        <div class="mt-1 text-[10px] text-gray-400">Metin genişletmesi yok</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if($selectedWebsiteId === '')
                                        <span class="text-gray-400">Website seçilmedi</span>
                                    @elseif($assetState?->status === 'active')
                                        <span class="font-medium text-emerald-600">Etkin</span>
                                        <button wire:click="setWebsiteStatus({{ $item->id }}, 'excluded')" type="button" class="mt-1 block text-[10px] text-red-600">Website’te hariç tut</button>
                                    @else
                                        <span class="text-gray-400">{{ $assetState?->status === 'excluded' ? 'Hariç' : 'Etkin değil' }}</span>
                                        <button wire:click="setWebsiteStatus({{ $item->id }}, 'active')" type="button" class="mt-1 block text-[10px] font-medium text-emerald-600">Website’te etkinleştir</button>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <div class="flex flex-col items-start gap-1.5">
                                        <button wire:click="editOverrides({{ $item->id }})" type="button" class="text-brand-600">Override düzenle</button>
                                        <button wire:click="editAreas({{ $item->id }})" type="button" class="text-sky-600">Bölgeleri seç</button>
                                        @if($item->status === 'active')<button wire:click="setPortfolioStatus({{ $item->id }}, 'excluded')" type="button" class="text-amber-600">Markada hariç tut</button>@else<button wire:click="setPortfolioStatus({{ $item->id }}, 'active')" type="button" class="text-emerald-600">Markada etkinleştir</button>@endif
                                        @if($item->origin_type === 'brand_custom' && $item->global_proposal_status !== 'submitted')<button wire:click="proposeToGlobal({{ $item->id }})" type="button" class="text-violet-600">Globale öner</button>@endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-10 text-center text-sm text-gray-500">Bu marka ve filtreler için portföy sorgusu yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-gray-100 px-4 py-3 text-xs text-gray-400 dark:border-gray-800">En fazla son 300 ilişki gösterilir. Global metin kopyalanmaz; marka ve website kararları ayrı provenance ile kalır.</div>
        </section>
    @endif
</div>
