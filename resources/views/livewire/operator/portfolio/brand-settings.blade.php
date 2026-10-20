@php
    $input = 'h-9 rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white';
    $textarea = 'rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white';
@endphp
<div class="space-y-10 text-sm dark:text-gray-200" data-brand-settings="{{ $part }}">
    @if ($message !== '')<p role="status" class="card px-4 py-3 text-[13px] text-gray-700 dark:text-gray-200">{{ $message }}</p>@endif

    @if ($part === 'info')
        <div class="grid gap-x-6 gap-y-10 lg:grid-cols-2">
            {{-- Sektör --}}
            <section data-section="sector">
                <div class="section-head">
                    <h2 class="section-title">Sektör</h2>
                </div>
                <p class="-mt-1 mb-3 text-[13px] text-gray-500">Markanın tek sektörü; varlıklar bunu kullanır. Sorgu kütüphanesi ve kümeler sektöre göre okunur.</p>
                <div class="card p-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <select wire:model="sectorId" aria-label="Sektör" class="{{ $input }} min-w-0 flex-1">
                            <option value="">—</option>
                            @foreach ($sectors as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                        </select>
                        <button type="button" wire:click="saveSector" class="btn">Kaydet</button>
                    </div>
                    @error('sectorId')<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                </div>
            </section>

            {{-- Bölgeler --}}
            <section data-section="areas">
                <div class="section-head">
                    <h2 class="section-title">Hizmet bölgeleri <span class="section-count">{{ $areas->count() }}</span></h2>
                </div>
                <p class="-mt-1 mb-3 text-[13px] text-gray-500">Fiziksel yeri olan yerel işletmede en az bir il / ilçe gir ve şubeyi işaretle; yalnız ülke yerel aramaları ayıramaz.</p>
                <ul class="card">
                    @foreach ($areas as $area)
                        <li wire:key="area-{{ $area->id }}" class="card-row flex-wrap gap-y-2">
                            <span class="min-w-0 flex-1 font-medium text-gray-900 dark:text-white">{{ $area->displayName() }}@if ($area->name)<span class="ml-1 text-xs font-normal text-gray-500">{{ $area->label() }}</span>@endif</span>
                            <button type="button" wire:click="togglePhysical({{ $area->id }})" class="inline-flex items-center gap-1.5 rounded-md px-1.5 py-0.5 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5"><span @class(['h-1.5 w-1.5 rounded-full', 'bg-success-500' => $area->physical_branch, 'bg-gray-400' => ! $area->physical_branch]) aria-hidden="true"></span>{{ $area->physical_branch ? 'Fiziksel şube var' : 'Şube yok' }}</button>
                            <button type="button" wire:click="removeArea({{ $area->id }})" wire:confirm="Bölge silinsin mi?" class="btn-quiet h-7! px-2!">Sil</button>
                        </li>
                    @endforeach
                    <li class="border-t border-gray-200 bg-gray-50/60 px-4 py-3 first:border-t-0 dark:border-gray-800 dark:bg-white/[0.02]">
                        <div class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center">
                            <input type="text" wire:model="areaName" placeholder="Ad (ops.)" aria-label="Bölge adı" class="{{ $input }} col-span-2 sm:w-32">
                            <input type="text" wire:model="areaCity" placeholder="İl" aria-label="İl" class="{{ $input }} sm:w-28">
                            <input type="text" wire:model="areaDistrict" placeholder="İlçe" aria-label="İlçe" class="{{ $input }} sm:w-28">
                            <label class="flex items-center gap-1.5 text-[13px] text-gray-600 dark:text-gray-300"><input type="checkbox" wire:model="areaPhysical" class="rounded border-gray-300"> Fiziksel şube</label>
                            <button type="button" wire:click="addArea" class="btn">Ekle</button>
                        </div>
                        @error('areaCity')<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                    </li>
                </ul>
            </section>
        </div>

        {{-- Hizmetler: the one place they are edited --}}
        <section data-section="services">
            <div class="section-head">
                <h2 class="section-title">Hizmetler <span class="section-count">{{ $offerings->count() }}</span></h2>
                <span class="ml-auto flex flex-wrap items-center gap-2">
                    <x-operator.ai-prompt-info operation="brand.services" />
                    <button type="button" wire:click="extractServices" wire:loading.attr="disabled" class="btn">Sayfalardan hizmet çıkar</button>
                </span>
            </div>
            <p class="-mt-1 mb-3 text-[13px] text-gray-500">★ = ana hizmet (SEO planı, Harita ve Ads önce buna bakar). Eşleştirme ifadeleri içe aktarılan sorguları hizmete atar; ana sayfa, hizmete eşlenen en genel sayfadır.</p>
            <ul class="card">
                @forelse ($offerings as $offering)
                    @php
                        $pages = $offeringPages[$offering->id] ?? [];
                        $keywords = $offering->catalogItem?->matchingKeywords ?? collect();
                    @endphp
                    <li wire:key="off-{{ $offering->id }}" class="card-row items-start" data-offering="{{ $offering->id }}">
                        <button type="button" wire:click="togglePriority({{ $offering->id }})" title="{{ $offering->isMain() ? 'Ana hizmet · ikincil yap' : 'İkincil · ana hizmet yap' }}" aria-pressed="{{ $offering->isMain() ? 'true' : 'false' }}" data-priority="{{ $offering->isMain() ? 'main' : 'secondary' }}" @class(['-my-0.5 text-base leading-6', 'text-warning-500' => $offering->isMain(), 'text-gray-300 hover:text-warning-400 dark:text-gray-600' => ! $offering->isMain()])>★</button>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $offering->displayName() }}@if ($offering->locked)<span class="ml-1 text-xs font-normal text-gray-500">onaylı</span>@endif</p>
                            <p class="mt-0.5 flex flex-wrap gap-x-3 text-[13px] text-gray-500">
                                <span>
                                @if ($pages !== [])
                                    Ana sayfa: <a href="{{ route('operator.website', ['assetId' => $pages[0]['website_asset_id'], 'tab' => 'eslestirme']) }}" wire:navigate class="link-neutral" title="{{ $pages[0]['url'] }}, eşleşmeyi değiştir" data-hub>{{ $pages[0]['path'] }}</a>@if (count($pages) > 1) <span>+{{ count($pages) - 1 }} sayfa</span>@endif
                                @else
                                    <span class="text-warning-700 dark:text-warning-400">Sayfa eşlenmedi</span>
                                @endif
                                </span>
                                <span>@if ($keywords->isNotEmpty()){{ $keywords->count() }} eşleştirme ifadesi @else<span class="text-warning-700 dark:text-warning-400">eşleştirme ifadesi yok</span>@endif</span>
                                @if ($offering->service_catalog_item_id)
                                    <a href="{{ route('operator.library.services', ['q' => $offering->displayName()]) }}" wire:navigate class="link-neutral">ifadeleri düzenle</a>
                                @endif
                            </p>
                        </div>
                        <button type="button" wire:click="removeOffering({{ $offering->id }})" wire:confirm="Hizmet kaldırılsın mı?" class="btn-quiet h-7! shrink-0 px-2!">Kaldır</button>
                    </li>
                @empty
                    <li class="card-row text-[13px] text-gray-500">Hizmet yok. "Sayfalardan hizmet çıkar" siteden önerir ya da aşağıdan ekle.</li>
                @endforelse
                <li class="border-t border-gray-200 bg-gray-50/60 px-4 py-3 dark:border-gray-800 dark:bg-white/[0.02]">
                    <form wire:submit="addService" class="flex flex-wrap items-center gap-2" data-add-service>
                        <input type="text" wire:model="newService" placeholder="Hizmet adı (ör. Zirkonyum Kaplama)" aria-label="Yeni hizmet" maxlength="255" class="{{ $input }} min-w-0 flex-1 sm:max-w-sm">
                        <button type="submit" class="btn">Hizmet ekle</button>
                    </form>
                    @error('newService')<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                </li>
            </ul>

            @if ($proposals->isNotEmpty())
                <div class="mt-6" data-service-proposals>
                    <div class="section-head">
                        <h3 class="section-title">Öneriler <span class="section-count">{{ $proposals->count() }}</span></h3>
                        <button type="button" wire:click="mergeProposals" class="btn ml-auto">Birleştir</button>
                    </div>
                    <div class="card relative overflow-x-auto">
                        <table class="data-table min-w-[600px]">
                            <thead><tr><th class="w-8"></th><th>Ad</th><th>Katalog</th><th>Sayfa</th><th>Öncelik</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($proposals as $proposal)
                                    <tr wire:key="prop-{{ $proposal->id }}">
                                        <td><input type="checkbox" wire:model="mergePick.{{ $proposal->id }}" aria-label="Seç" class="rounded border-gray-300"></td>
                                        <td><input type="text" wire:model="proposalNames.{{ $proposal->id }}" value="{{ $proposalNames[$proposal->id] ?? $proposal->name }}" aria-label="Ad" class="{{ $input }} h-8! w-48"></td>
                                        <td>{{ $proposal->catalogItem?->primaryName?->raw_label ?? 'Yeni katalog' }}</td>
                                        <td class="tabular-nums">{{ count((array) $proposal->page_ids) }}</td>
                                        <td>
                                            <select wire:model="proposalPriority.{{ $proposal->id }}" aria-label="Öncelik" class="{{ $input }} h-8!">
                                                @foreach (['main' => '★ Ana', 'secondary' => 'İkincil'] as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                                            </select>
                                        </td>
                                        <td class="whitespace-nowrap text-right">
                                            <button type="button" wire:click="approveProposal({{ $proposal->id }})" class="btn h-7! px-2.5! text-xs!">Onayla</button>
                                            <button type="button" wire:click="skipProposal({{ $proposal->id }})" class="btn-quiet h-7! px-2!">Atla</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </section>

        {{-- Markaya özel yasaklı ifadeler --}}
        <section data-section="forbidden">
            <div class="section-head">
                <h2 class="section-title">Markaya özel yasaklı ifadeler</h2>
            </div>
            <p class="-mt-1 mb-3 text-[13px] text-gray-500">Her satıra bir ifade. Yalnız bu markanın içeriklerinde geçerli; sektörün yasaklı ifadelerine (Sorgular › Yasaklı ifadeler) eklenir.</p>
            <div class="card p-4">
                <textarea wire:model="forbidden" rows="3" aria-label="Markaya özel yasaklı ifadeler" class="{{ $textarea }} w-full"></textarea>
                <button type="button" wire:click="saveForbidden" class="btn mt-2">Kaydet</button>
            </div>
        </section>
    @else
        {{-- Bağlantılar: bind / unbind / move --}}
        <section data-section="assets">
            <div class="section-head">
                <h2 class="section-title" title="Her varlığa bağlı hesaplar. Bağı kaldırma, taşıma ve elle bağlama yalnız Admin içindir.">Bağlantılar <span class="section-count">{{ $assets->count() }} varlık</span></h2>
            </div>
            <ul class="card">
                @forelse ($assets as $asset)
                    <li wire:key="asset-{{ $asset->id }}" class="border-t border-gray-200 px-4 py-3.5 first:border-t-0 dark:border-gray-800" data-asset-row="{{ $asset->id }}">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('operator.asset.sources', ['assetId' => $asset->id]) }}" wire:navigate class="block truncate text-sm font-medium text-gray-900 hover:underline dark:text-white">{{ $asset->name }}</a>
                                <p class="mt-0.5 text-[13px] text-gray-500">{{ \App\Services\Portfolio\BrandCandidateBuilder::typeLabel((string) $asset->type) }}</p>
                            </div>
                            @if ($isAdmin)
                                <span class="flex w-full items-center gap-2 sm:w-auto">
                                    <select wire:model="moveTo.{{ $asset->id }}" aria-label="Taşı" class="{{ $input }} h-8! min-w-0 flex-1 sm:w-44 sm:flex-none">
                                        <option value="">Marka…</option>
                                        @foreach ($brands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                                    </select>
                                    <button type="button" wire:click="moveAsset({{ $asset->id }})" wire:confirm="Varlık taşınsın mı?" class="btn">Taşı</button>
                                </span>
                            @endif
                        </div>
                        @foreach ($bindings->get($asset->id, collect()) as $binding)
                            <div class="mt-2 flex items-center gap-2 rounded-lg bg-gray-50 py-1 pr-1 pl-3 text-[13px] text-gray-600 dark:bg-white/[0.03] dark:text-gray-300" wire:key="bind-{{ $binding->id }}">
                                <span class="min-w-0 flex-1 truncate">{{ $binding->externalResource?->display_name ?: $binding->externalResource?->external_id }}</span>
                                @if ($isAdmin)<button type="button" wire:click="unbind({{ $binding->id }})" wire:confirm="Bağ kaldırılsın mı?" class="btn-quiet h-7! px-2!">Bağı kaldır</button>@endif
                            </div>
                        @endforeach
                    </li>
                @empty
                    <li class="card-row text-[13px] text-gray-500">Varlık yok.</li>
                @endforelse
                @if ($isAdmin && $bindOptions !== [])
                    <li class="flex flex-wrap items-center gap-2 border-t border-gray-200 bg-gray-50/60 px-4 py-3 dark:border-gray-800 dark:bg-white/[0.02]">
                        <select wire:model="bindChoice" aria-label="Bağla" class="{{ $input }} min-w-0 flex-1 sm:max-w-sm">
                            <option value="">Hesap / site…</option>
                            @foreach ($bindOptions as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                        <button type="button" wire:click="bind" class="btn">Bağla</button>
                    </li>
                @endif
            </ul>
        </section>
    @endif
</div>
