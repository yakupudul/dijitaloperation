<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-600">Kütüphane</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Hizmetler</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">Ajans genelinde tekrar kullanılan hizmet adları ve eş adları. Markaya eklenen hizmetler bu kalıcı kimliklere bağlanır.</p>
        </div>
        <a href="{{ route('operator.library.search-queries') }}" wire:navigate class="rounded-lg px-4 py-2.5 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:ring-brand-500/30">Sorgu kütüphanesi →</a>
    </div>

    @if ($message !== '')
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">{{ $message }}</div>
    @endif

    <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Yeni hizmet</h2>
        <form wire:submit="createService" class="mt-4 grid gap-3 lg:grid-cols-[1.2fr_1fr_2fr_auto]">
            <div>
                <input wire:model="service_name" type="text" placeholder="Örn. İmplant tedavisi" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
                @error('service_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <select wire:model="service_sector" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
                <option value="">Sektör (isteğe bağlı)</option>
                @foreach ($sectorOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
            <input wire:model="service_description" type="text" placeholder="Kısa açıklama (isteğe bağlı)" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
            <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">Ekle</button>
        </form>
    </section>

    <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="grid gap-3 border-b border-gray-100 p-4 md:grid-cols-3 dark:border-gray-800">
            <input wire:model.live.debounce.350ms="search" type="search" placeholder="Hizmet veya eş ad ara" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
            <select wire:model.live="sector" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Tüm sektörler</option>@foreach ($sectorOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
            <select wire:model.live="status" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">@foreach ($statusOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
        </div>

        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($services as $service)
                <article class="p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-semibold text-gray-900 dark:text-white">{{ $service->primaryName?->raw_label ?? 'İsimsiz hizmet' }}</h3>
                                <span class="rounded-full px-2 py-0.5 text-[11px] {{ $service->status === 'active' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' }}">{{ $service->status === 'active' ? 'Aktif' : 'Arşiv' }}</span>
                                @if ($service->sector)<span class="rounded bg-gray-100 px-2 py-0.5 text-[11px] text-gray-500 dark:bg-gray-800">{{ $sectorOptions[$service->sector] ?? $service->sector }}</span>@endif
                            </div>
                            @if ($service->description)<p class="mt-1 text-sm text-gray-500">{{ $service->description }}</p>@endif
                            <p class="mt-2 text-xs text-gray-400">{{ $service->brand_offerings_count }} marka · {{ $service->search_queries_count }} sorgu</p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach ($service->names->where('is_primary', false) as $name)
                                    <span class="rounded-md bg-brand-50 px-2 py-1 text-xs text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">{{ $name->raw_label }}</span>
                                @endforeach
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" wire:click="beginAlias({{ $service->id }})" class="rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Eş ad ekle</button>
                            <button type="button" wire:click="toggleStatus({{ $service->id }})" class="rounded-lg px-3 py-2 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700">{{ $service->status === 'active' ? 'Arşivle' : 'Etkinleştir' }}</button>
                        </div>
                    </div>
                    @if ($alias_service_id === $service->id)
                        <form wire:submit="addAlias" class="mt-4 flex max-w-xl gap-2">
                            <input wire:model="alias" type="text" placeholder="Alternatif hizmet adı" class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
                            <button type="submit" class="rounded-lg bg-brand-500 px-3 py-2 text-xs font-semibold text-white">Kaydet</button>
                            <button type="button" wire:click="$set('alias_service_id', null)" class="rounded-lg px-3 py-2 text-xs ring-1 ring-inset ring-gray-300">Vazgeç</button>
                        </form>
                        @error('alias') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @endif
                </article>
            @empty
                <div class="p-10 text-center text-sm text-gray-500">Bu filtrelerle eşleşen hizmet yok.</div>
            @endforelse
        </div>
    </section>
</div>
