@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950';
    $btn = 'rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600';
    $ghost = 'rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
@endphp
<div class="space-y-4 text-sm dark:text-gray-200" data-brand-settings>
    @if ($message !== '')<p role="status" class="rounded-lg bg-blue-50 p-2 text-blue-800 dark:bg-blue-950 dark:text-blue-200">{{ $message }}</p>@endif

    {{-- Sektör --}}
    <section class="{{ $card }}" data-section="sector">
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="w-24 font-semibold">Sektör</h2>
            <select wire:model="sectorId" aria-label="Sektör" class="{{ $input }}">
                <option value="">—</option>
                @foreach ($sectors as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
            <button type="button" wire:click="saveSector" class="{{ $btn }}">Kaydet</button>
            @error('sectorId')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror
        </div>
    </section>

    {{-- Bölgeler --}}
    <section class="{{ $card }}" data-section="areas">
        <h2 class="font-semibold">Hizmet bölgeleri · {{ $areas->count() }}</h2>
        <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-800">
            @foreach ($areas as $area)
                <li wire:key="area-{{ $area->id }}" class="flex flex-wrap items-center gap-2 py-1.5">
                    <span class="min-w-0 flex-1 font-medium">{{ $area->displayName() }}@if ($area->name)<span class="ml-1 text-xs text-gray-500">{{ $area->label() }}</span>@endif</span>
                    <button type="button" wire:click="togglePhysical({{ $area->id }})" @class(['rounded-full px-2 py-0.5 text-xs', 'bg-success-50 text-success-700 dark:bg-success-500/10' => $area->physical_branch, 'bg-gray-100 text-gray-500 dark:bg-gray-800' => ! $area->physical_branch])>{{ $area->physical_branch ? 'Fiziksel şube var' : 'Şube yok' }}</button>
                    <button type="button" wire:click="removeArea({{ $area->id }})" wire:confirm="Bölge silinsin mi?" class="{{ $ghost }}">Sil</button>
                </li>
            @endforeach
        </ul>
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <input type="text" wire:model="areaName" placeholder="Ad (ops.)" aria-label="Bölge adı" class="{{ $input }} w-36">
            <input type="text" wire:model="areaCity" placeholder="İl" aria-label="İl" class="{{ $input }} w-32">
            <input type="text" wire:model="areaDistrict" placeholder="İlçe" aria-label="İlçe" class="{{ $input }} w-32">
            <label class="flex items-center gap-1 text-xs"><input type="checkbox" wire:model="areaPhysical"> Fiziksel şube</label>
            <button type="button" wire:click="addArea" class="{{ $btn }}">Ekle</button>
            @error('areaCity')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror
        </div>
    </section>

    {{-- Hizmetler --}}
    <section class="{{ $card }}" data-section="services">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold">Hizmetler · {{ $offerings->count() }}</h2>
            <button type="button" wire:click="extractServices" wire:loading.attr="disabled" class="{{ $btn }}">Sayfalardan hizmet çıkar</button>
        </div>
        <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($offerings as $offering)
                <li wire:key="off-{{ $offering->id }}" class="flex flex-wrap items-center gap-2 py-1.5">
                    <span class="min-w-0 flex-1 font-medium">{{ $offering->displayName() }}@if ($offering->locked)<span class="ml-1 text-xs text-gray-500">onaylı</span>@endif</span>
                    <select wire:change="setPriority({{ $offering->id }}, $event.target.value)" aria-label="Öncelik" class="{{ $input }} py-1 text-xs">
                        @foreach (\App\Models\BrandOffering::PRIORITIES as $code => $label)<option value="{{ $code }}" @selected(($offering->priority ?? 'secondary') === $code)>{{ $label }}</option>@endforeach
                    </select>
                    <button type="button" wire:click="removeOffering({{ $offering->id }})" wire:confirm="Hizmet kaldırılsın mı?" class="{{ $ghost }}">Kaldır</button>
                </li>
            @empty
                <li class="py-2 text-gray-500">Hizmet yok.</li>
            @endforelse
        </ul>

        @if ($proposals->isNotEmpty())
            <div class="mt-3 border-t border-gray-100 pt-3 dark:border-gray-800" data-service-proposals>
                <div class="flex items-center justify-between">
                    <h3 class="text-xs font-semibold uppercase text-gray-500">Öneriler · {{ $proposals->count() }}</h3>
                    <button type="button" wire:click="mergeProposals" class="{{ $ghost }}">Birleştir</button>
                </div>
                <table class="mt-1 w-full text-left text-xs">
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
                                        @foreach (\App\Models\BrandOffering::PRIORITIES as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
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
        @endif
    </section>

    {{-- Notlar --}}
    <section class="{{ $card }}" data-section="notes">
        <h2 class="font-semibold">Notlar</h2>
        <div class="mt-2 grid gap-2 md:grid-cols-2">
            <label class="text-xs text-gray-500">Hedefler<textarea wire:model="goals" rows="3" class="{{ $input }} mt-1 w-full"></textarea></label>
            <label class="text-xs text-gray-500">Kısıtlar<textarea wire:model="constraints" rows="3" class="{{ $input }} mt-1 w-full"></textarea></label>
        </div>
        <button type="button" wire:click="saveNotes" class="{{ $btn }} mt-2">Kaydet</button>
    </section>

    {{-- Varlıklar --}}
    <section class="{{ $card }}" data-section="assets">
        <h2 class="font-semibold">Varlıklar · {{ $assets->count() }}</h2>
        <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($assets as $asset)
                <li wire:key="asset-{{ $asset->id }}" class="py-1.5">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="w-28 text-xs text-gray-500">{{ \App\Services\Portfolio\BrandCandidateBuilder::typeLabel((string) $asset->type) }}</span>
                        <a href="{{ route('operator.asset.sources', ['assetId' => $asset->id]) }}" wire:navigate class="min-w-0 flex-1 truncate font-medium hover:text-brand-600">{{ $asset->name }}</a>
                        @if ($isAdmin)
                            <select wire:model="moveTo.{{ $asset->id }}" aria-label="Taşı" class="{{ $input }} py-1 text-xs">
                                <option value="">Marka…</option>
                                @foreach ($brands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </select>
                            <button type="button" wire:click="moveAsset({{ $asset->id }})" wire:confirm="Varlık taşınsın mı?" class="{{ $ghost }}">Taşı</button>
                        @endif
                    </div>
                    @foreach ($bindings->get($asset->id, collect()) as $binding)
                        <div class="ml-28 flex items-center gap-2 text-xs text-gray-500" wire:key="bind-{{ $binding->id }}">
                            <span class="min-w-0 flex-1 truncate">{{ $binding->externalResource?->display_name ?: $binding->externalResource?->external_id }}</span>
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
                <select wire:model="bindChoice" aria-label="Bağla" class="{{ $input }}">
                    <option value="">Hesap / site…</option>
                    @foreach ($bindOptions as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
                <button type="button" wire:click="bind" class="{{ $btn }}">Bağla</button>
            </div>
        @endif
    </section>
</div>
