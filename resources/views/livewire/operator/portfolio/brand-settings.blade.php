@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white';
    $btn = 'rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600';
    $ghost = 'rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-gray-800';
@endphp
<div class="space-y-4 text-sm dark:text-gray-200" data-brand-settings="{{ $part }}">
    @if ($message !== '')<p role="status" class="rounded-lg bg-blue-50 p-2 text-blue-800 dark:bg-blue-950 dark:text-blue-200">{{ $message }}</p>@endif

    @if ($part === 'info')
        <div class="grid gap-4 lg:grid-cols-2">
            {{-- Sektör --}}
            <section class="{{ $card }}" data-section="sector">
                <h2 class="font-semibold text-gray-900 dark:text-white">Sektör</h2>
                <p class="text-xs text-gray-500">Markanın tek sektörü; varlıklar bunu kullanır. Sorgu kütüphanesi ve kümeler sektöre göre okunur.</p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <select wire:model="sectorId" aria-label="Sektör" class="{{ $input }} min-w-0 flex-1 sm:flex-none">
                        <option value="">—</option>
                        @foreach ($sectors as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    <button type="button" wire:click="saveSector" class="{{ $btn }}">Kaydet</button>
                </div>
                @error('sectorId')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            </section>

            {{-- Bölgeler --}}
            <section class="{{ $card }}" data-section="areas">
                <h2 class="font-semibold text-gray-900 dark:text-white">Hizmet bölgeleri · {{ $areas->count() }}</h2>
                <p class="text-xs text-gray-500">Fiziksel yeri olan yerel işletmede en az bir il / ilçe gir ve şubeyi işaretle; yalnız ülke yerel aramaları ayıramaz.</p>
                <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($areas as $area)
                        <li wire:key="area-{{ $area->id }}" class="flex flex-wrap items-center gap-2 py-1.5">
                            <span class="min-w-0 flex-1 font-medium text-gray-900 dark:text-white">{{ $area->displayName() }}@if ($area->name)<span class="ml-1 text-xs font-normal text-gray-500">{{ $area->label() }}</span>@endif</span>
                            <button type="button" wire:click="togglePhysical({{ $area->id }})" @class(['rounded-full px-2 py-0.5 text-xs', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $area->physical_branch, 'bg-gray-100 text-gray-500 dark:bg-gray-800' => ! $area->physical_branch])>{{ $area->physical_branch ? 'Fiziksel şube var' : 'Şube yok' }}</button>
                            <button type="button" wire:click="removeArea({{ $area->id }})" wire:confirm="Bölge silinsin mi?" class="{{ $ghost }}">Sil</button>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-2 grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center">
                    <input type="text" wire:model="areaName" placeholder="Ad (ops.)" aria-label="Bölge adı" class="{{ $input }} col-span-2 sm:w-36">
                    <input type="text" wire:model="areaCity" placeholder="İl" aria-label="İl" class="{{ $input }} sm:w-32">
                    <input type="text" wire:model="areaDistrict" placeholder="İlçe" aria-label="İlçe" class="{{ $input }} sm:w-32">
                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" wire:model="areaPhysical"> Fiziksel şube</label>
                    <button type="button" wire:click="addArea" class="{{ $btn }}">Ekle</button>
                </div>
                @error('areaCity')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            </section>
        </div>

        {{-- Hizmetler: the one place they are edited --}}
        <section class="{{ $card }}" data-section="services">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 class="font-semibold text-gray-900 dark:text-white">Hizmetler · {{ $offerings->count() }}</h2>
                    <p class="text-xs text-gray-500">★ = ana hizmet (SEO planı, Harita ve Ads önce buna bakar). Eşleştirme ifadeleri içe aktarılan sorguları hizmete atar; ana sayfa, hizmete eşlenen en genel sayfadır.</p>
                </div>
                <span class="flex flex-wrap items-center gap-2">
                    <button type="button" wire:click="extractServices" wire:loading.attr="disabled" class="{{ $btn }}">Sayfalardan hizmet çıkar</button>
                    <x-operator.ai-prompt-info operation="brand.services" />
                </span>
            </div>
            <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($offerings as $offering)
                    @php
                        $pages = $offeringPages[$offering->id] ?? [];
                        $keywords = $offering->catalogItem?->matchingKeywords ?? collect();
                    @endphp
                    <li wire:key="off-{{ $offering->id }}" class="flex items-start gap-2 py-2" data-offering="{{ $offering->id }}">
                        <button type="button" wire:click="togglePriority({{ $offering->id }})" title="{{ $offering->isMain() ? 'Ana hizmet · ikincil yap' : 'İkincil · ana hizmet yap' }}" aria-pressed="{{ $offering->isMain() ? 'true' : 'false' }}" data-priority="{{ $offering->isMain() ? 'main' : 'secondary' }}" @class(['text-lg leading-none', 'text-amber-500' => $offering->isMain(), 'text-gray-300 hover:text-amber-400 dark:text-gray-600' => ! $offering->isMain()])>★</button>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-gray-900 dark:text-white">{{ $offering->displayName() }}@if ($offering->locked)<span class="ml-1 text-xs font-normal text-gray-500">onaylı</span>@endif</p>
                            <p class="text-xs text-gray-500">
                                @if ($pages !== [])
                                    Ana sayfa: <a href="{{ route('operator.website', ['assetId' => $pages[0]['website_asset_id'], 'tab' => 'eslestirme']) }}" wire:navigate class="text-brand-600 hover:underline dark:text-brand-400" title="{{ $pages[0]['url'] }} · eşleşmeyi değiştir" data-hub>{{ $pages[0]['path'] }}</a>@if (count($pages) > 1) <span>+{{ count($pages) - 1 }} sayfa</span>@endif
                                @else
                                    <span class="text-amber-700 dark:text-amber-400">Sayfa eşlenmedi</span>
                                @endif
                                · @if ($keywords->isNotEmpty()){{ $keywords->count() }} eşleştirme ifadesi @else<span class="text-amber-700 dark:text-amber-400">eşleştirme ifadesi yok</span>@endif
                                @if ($offering->service_catalog_item_id)
                                    · <a href="{{ route('operator.library.services', ['q' => $offering->displayName()]) }}" wire:navigate class="text-brand-600 hover:underline dark:text-brand-400">ifadeleri düzenle</a>
                                @endif
                            </p>
                        </div>
                        <button type="button" wire:click="removeOffering({{ $offering->id }})" wire:confirm="Hizmet kaldırılsın mı?" class="{{ $ghost }} shrink-0">Kaldır</button>
                    </li>
                @empty
                    <li class="py-2 text-gray-500">Hizmet yok. "Sayfalardan hizmet çıkar" siteden önerir ya da aşağıdan ekle.</li>
                @endforelse
            </ul>
            <form wire:submit="addService" class="mt-2 flex flex-wrap items-center gap-2" data-add-service>
                <input type="text" wire:model="newService" placeholder="Hizmet adı (ör. Zirkonyum Kaplama)" aria-label="Yeni hizmet" maxlength="255" class="{{ $input }} min-w-0 flex-1 sm:max-w-sm">
                <button type="submit" class="{{ $btn }}">Hizmet ekle</button>
            </form>
            @error('newService')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror

            @if ($proposals->isNotEmpty())
                <div class="mt-3 border-t border-gray-100 pt-3 dark:border-gray-800" data-service-proposals>
                    <div class="flex items-center justify-between">
                        <h3 class="text-xs font-semibold uppercase text-gray-500">Öneriler · {{ $proposals->count() }}</h3>
                        <button type="button" wire:click="mergeProposals" class="{{ $ghost }}">Birleştir</button>
                    </div>
                    <div class="mt-1 overflow-x-auto rounded-xl">
                        <table class="w-full min-w-[560px] text-left text-xs">
                            <thead class="text-gray-500"><tr><th class="py-1"></th><th>Ad</th><th>Katalog</th><th>Sayfa</th><th>Öncelik</th><th></th></tr></thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($proposals as $proposal)
                                    <tr wire:key="prop-{{ $proposal->id }}">
                                        <td class="py-1"><input type="checkbox" wire:model="mergePick.{{ $proposal->id }}" aria-label="Seç"></td>
                                        <td><input type="text" wire:model="proposalNames.{{ $proposal->id }}" value="{{ $proposalNames[$proposal->id] ?? $proposal->name }}" aria-label="Ad" class="{{ $input }} w-48 py-1 text-xs"></td>
                                        <td>{{ $proposal->catalogItem?->primaryName?->raw_label ?? 'Yeni katalog' }}</td>
                                        <td>{{ count((array) $proposal->page_ids) }}</td>
                                        <td>
                                            <select wire:model="proposalPriority.{{ $proposal->id }}" aria-label="Öncelik" class="{{ $input }} py-1 text-xs">
                                                @foreach (['main' => '★ Ana', 'secondary' => 'İkincil'] as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                                            </select>
                                        </td>
                                        <td class="whitespace-nowrap text-right">
                                            <button type="button" wire:click="approveProposal({{ $proposal->id }})" class="{{ $btn }}">Onayla</button>
                                            <button type="button" wire:click="skipProposal({{ $proposal->id }})" class="{{ $ghost }}">Atla</button>
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
        <section class="{{ $card }}" data-section="forbidden">
            <h2 class="font-semibold text-gray-900 dark:text-white">Markaya özel yasaklı ifadeler</h2>
            <p class="text-xs text-gray-500">Her satıra bir ifade. Yalnız bu markanın içeriklerinde geçerli; sektörün yasaklı ifadelerine (Sorgular › Yasaklı ifadeler) eklenir.</p>
            <textarea wire:model="forbidden" rows="3" aria-label="Markaya özel yasaklı ifadeler" class="{{ $input }} mt-2 w-full"></textarea>
            <button type="button" wire:click="saveForbidden" class="{{ $btn }} mt-2">Kaydet</button>
        </section>
    @else
        {{-- Bağlantılar: bind / unbind / move --}}
        <section class="{{ $card }}" data-section="assets">
            <h2 class="font-semibold text-gray-900 dark:text-white">Bağlantılar · {{ $assets->count() }} varlık</h2>
            <p class="text-xs text-gray-500">Her varlığa bağlı hesaplar. Bağı kaldırma, taşıma ve elle bağlama yalnız Admin içindir.</p>
            <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($assets as $asset)
                    <li wire:key="asset-{{ $asset->id }}" class="py-2" data-asset-row="{{ $asset->id }}">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span class="w-full text-xs text-gray-500 sm:w-28">{{ \App\Services\Portfolio\BrandCandidateBuilder::typeLabel((string) $asset->type) }}</span>
                            <a href="{{ route('operator.asset.sources', ['assetId' => $asset->id]) }}" wire:navigate class="min-w-0 flex-1 truncate font-medium text-gray-900 hover:text-brand-600 dark:text-white">{{ $asset->name }}</a>
                            @if ($isAdmin)
                                <span class="flex w-full items-center gap-2 sm:w-auto">
                                    <select wire:model="moveTo.{{ $asset->id }}" aria-label="Taşı" class="{{ $input }} min-w-0 flex-1 py-1 text-xs sm:w-40 sm:flex-none">
                                        <option value="">Marka…</option>
                                        @foreach ($brands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                                    </select>
                                    <button type="button" wire:click="moveAsset({{ $asset->id }})" wire:confirm="Varlık taşınsın mı?" class="{{ $ghost }}">Taşı</button>
                                </span>
                            @endif
                        </div>
                        @foreach ($bindings->get($asset->id, collect()) as $binding)
                            <div class="mt-1 flex items-center gap-2 text-xs text-gray-500 sm:ml-28 sm:pl-2" wire:key="bind-{{ $binding->id }}">
                                <span class="min-w-0 flex-1 truncate">● {{ $binding->externalResource?->display_name ?: $binding->externalResource?->external_id }}</span>
                                @if ($isAdmin)<button type="button" wire:click="unbind({{ $binding->id }})" wire:confirm="Bağ kaldırılsın mı?" class="{{ $ghost }}">Bağı kaldır</button>@endif
                            </div>
                        @endforeach
                    </li>
                @empty
                    <li class="py-2 text-gray-500">Varlık yok.</li>
                @endforelse
            </ul>
            @if ($isAdmin && $bindOptions !== [])
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <select wire:model="bindChoice" aria-label="Bağla" class="{{ $input }} min-w-0 flex-1 sm:flex-none">
                        <option value="">Hesap / site…</option>
                        @foreach ($bindOptions as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </select>
                    <button type="button" wire:click="bind" class="{{ $btn }}">Bağla</button>
                </div>
            @endif
        </section>
    @endif
</div>
