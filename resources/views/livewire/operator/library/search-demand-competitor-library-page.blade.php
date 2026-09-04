@php
    $statusLabels = ['pending' => 'Rakip adayı', 'approved' => 'Onaylı rakip', 'rejected' => 'Reddedildi'];
    $entityLabels = [
        'unknown' => 'Türü belirlenmedi', 'business' => 'İşletme', 'directory' => 'Dizin',
        'platform' => 'Platform', 'authority' => 'Otorite sitesi',
    ];
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-600">Arama talebi · Faz 9</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Rakip Kütüphanesi ve Rakip Keşfi</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">Rakip domainlerini hizmet, bölge, küme, sorgu ve URL kanıtıyla saklar. DataForSEO gözlemleri yalnız adaydır; ticari rakip sınıfını operatör belirler.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('operator.library.search-demand-enrichment', ['website' => $selectedWebsiteId]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">SERP zenginleştirme</a>
            <a href="{{ route('operator.library.search-demand-ownership', ['website' => $selectedWebsiteId]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">URL sahipliği</a>
        </div>
    </div>

    @if($message !== '')<div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $message }}</div>@endif

    <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="grid gap-3 lg:grid-cols-5">
            <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Marka</span><select wire:model.live="selectedBrandId" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Marka seçin</option>@foreach($brands as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach</select></label>
            <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Durum</span><select wire:model.live="statusFilter" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="all">Tüm durumlar</option><option value="pending">Rakip adayları</option><option value="approved">Onaylı rakipler</option><option value="rejected">Reddedilenler</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Rol / varlık türü</span><select wire:model.live="roleFilter" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="all">Tümü</option><option value="commercial">Ticari rakip</option><option value="serp">SERP rakibi</option><option value="content">İçerik rakibi</option>@foreach($entityLabels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
            <label class="block lg:col-span-2"><span class="mb-1 block text-xs font-medium text-gray-500">Ara</span><input wire:model.live.debounce.300ms="search" type="search" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" placeholder="Domain veya rakip adı" /></label>
        </div>
    </section>

    @if($brand)
        <div class="grid gap-6 xl:grid-cols-2">
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">Saklı DataForSEO gözlemlerinden aday al</h2>
                <p class="mt-1 text-sm text-gray-500">Yalnız daha önce kaydedilmiş SERP sonuçlarını ve domain kesişim kayıtlarını okur. Yeni provider çağrısı veya ücret oluşturmaz.</p>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Website</span><select wire:model.live="selectedWebsiteId" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Website seçin</option>@foreach($websites as $website)<option value="{{ $website->id }}">{{ $website->name }}</option>@endforeach</select></label>
                    <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Küme filtresi</span><select wire:model="selectedClusterId" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Tüm kümeler</option>@foreach($clusters as $cluster)<option value="{{ $cluster->id }}">{{ $cluster->name }}</option>@endforeach</select></label>
                </div>
                <button wire:click="importStored" wire:loading.attr="disabled" type="button" class="mt-4 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">Kayıtlı adayları içe al</button>
                @error('selectedWebsiteId')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
            </section>

            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">Manuel onaylı rakip ekle</h2>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Domain</span><input wire:model="manualDomain" type="text" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" placeholder="rakip.example" /></label>
                    <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Görünen ad</span><input wire:model="manualName" type="text" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" /></label>
                    <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Varlık türü</span><select wire:model="manualEntityKind" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">@foreach($entityLabels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                    <div><span class="mb-1 block text-xs font-medium text-gray-500">Rakip rolleri</span><div class="flex flex-wrap gap-3 text-sm"><label><input wire:model="manualCommercial" type="checkbox" class="rounded border-gray-300 text-brand-500" /> Ticari</label><label><input wire:model="manualSerp" type="checkbox" class="rounded border-gray-300 text-brand-500" /> SERP</label><label><input wire:model="manualContent" type="checkbox" class="rounded border-gray-300 text-brand-500" /> İçerik</label></div></div>
                    <label class="block sm:col-span-2"><span class="mb-1 block text-xs font-medium text-gray-500">Rakip URL’leri · satır başına bir URL</span><textarea wire:model="manualUrls" rows="2" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"></textarea></label>
                    <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Hizmetler</span><select wire:model="manualServices" multiple size="3" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">@foreach($services as $service)<option value="{{ $service->id }}">{{ $service->primaryName?->raw_label ?: '#'.$service->id }}</option>@endforeach</select></label>
                    <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Bölgeler</span><select wire:model="manualAreas" multiple size="3" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">@foreach($areas as $area)<option value="{{ $area->id }}">{{ $area->label() }}</option>@endforeach</select></label>
                    <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Kümeler</span><select wire:model="manualClusters" multiple size="3" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">@foreach($clusters as $cluster)<option value="{{ $cluster->id }}">{{ $cluster->name }}</option>@endforeach</select></label>
                    <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Not</span><textarea wire:model="manualNotes" rows="3" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"></textarea></label>
                </div>
                <button wire:click="addManual" type="button" class="mt-4 rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white dark:bg-white dark:text-gray-900">Onaylı rakip ekle</button>
                @error('manualDomain')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror @error('competitorRoles')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror @error('manualUrls')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
            </section>
        </div>

        @if($editingCompetitorId)
            <section class="rounded-xl border border-brand-200 bg-brand-50/40 p-5 dark:border-brand-800 dark:bg-brand-950/20">
                <div class="flex items-center justify-between"><h2 class="font-semibold text-gray-900 dark:text-white">Rakip sınıflandırmasını düzenle</h2><button wire:click="cancelEdit" type="button" class="text-sm text-gray-500">Kapat</button></div>
                <div class="mt-4 grid gap-3 lg:grid-cols-4">
                    <label class="block"><span class="mb-1 block text-xs text-gray-500">Ad</span><input wire:model="editName" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" /></label>
                    <label class="block"><span class="mb-1 block text-xs text-gray-500">Varlık türü</span><select wire:model="editEntityKind" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">@foreach($entityLabels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                    <div class="lg:col-span-2"><span class="mb-1 block text-xs text-gray-500">Roller</span><div class="flex flex-wrap gap-4 text-sm"><label><input wire:model="editCommercial" type="checkbox" class="rounded border-gray-300 text-brand-500" /> Ticari rakip</label><label><input wire:model="editSerp" type="checkbox" class="rounded border-gray-300 text-brand-500" /> SERP rakibi</label><label><input wire:model="editContent" type="checkbox" class="rounded border-gray-300 text-brand-500" /> İçerik rakibi</label></div></div>
                    <label class="block"><span class="mb-1 block text-xs text-gray-500">Hizmetler</span><select wire:model="editServices" multiple size="4" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">@foreach($services as $service)<option value="{{ $service->id }}">{{ $service->primaryName?->raw_label ?: '#'.$service->id }}</option>@endforeach</select></label>
                    <label class="block"><span class="mb-1 block text-xs text-gray-500">Bölgeler</span><select wire:model="editAreas" multiple size="4" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">@foreach($areas as $area)<option value="{{ $area->id }}">{{ $area->label() }}</option>@endforeach</select></label>
                    <label class="block"><span class="mb-1 block text-xs text-gray-500">Kümeler</span><select wire:model="editClusters" multiple size="4" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">@foreach($clusters as $cluster)<option value="{{ $cluster->id }}">{{ $cluster->name }}</option>@endforeach</select></label>
                    <label class="block"><span class="mb-1 block text-xs text-gray-500">Not</span><textarea wire:model="editNotes" rows="4" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"></textarea></label>
                </div>
                <button wire:click="saveCompetitor" type="button" class="mt-4 rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white">Sınıflandırmayı kaydet</button>
                @error('competitorRoles')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror @error('relations')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
            </section>
        @endif

        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800"><div><h2 class="font-semibold text-gray-900 dark:text-white">Rakip kayıtları</h2><p class="mt-1 text-xs text-gray-500">En fazla 200 kayıt gösterilir. Reddedilen adayların provenance kaydı korunur.</p></div><div class="flex gap-2"><button wire:click="reviewSelected('approved')" type="button" class="rounded bg-emerald-600 px-3 py-2 text-xs font-semibold text-white">Seçilenleri onayla</button><button wire:click="reviewSelected('rejected')" type="button" class="rounded bg-red-600 px-3 py-2 text-xs font-semibold text-white">Seçilenleri reddet</button></div></div>
            @error('selectedCompetitorIds')<p class="border-b border-red-100 bg-red-50 px-5 py-2 text-xs text-red-700">{{ $message }}</p>@enderror
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse($competitors as $competitor)
                    <article class="p-5" wire:key="competitor-{{ $competitor->id }}">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="flex min-w-0 items-start gap-3">
                                @if($competitor->status === 'pending')<input wire:model="selectedCompetitorIds" value="{{ $competitor->id }}" type="checkbox" class="mt-1 rounded border-gray-300 text-brand-500" />@endif
                                <div class="min-w-0"><h3 class="font-semibold text-gray-900 dark:text-white">{{ $competitor->display_name }}</h3><p class="break-all text-sm text-gray-500">{{ $competitor->normalized_domain }}</p></div>
                            </div>
                            <div class="flex flex-wrap gap-2 text-[11px]"><span class="rounded bg-gray-100 px-2 py-1 dark:bg-gray-800">{{ $statusLabels[$competitor->status] ?? $competitor->status }}</span><span class="rounded bg-sky-50 px-2 py-1 text-sky-700">{{ $entityLabels[$competitor->entity_kind] ?? $competitor->entity_kind }}</span>@if($competitor->is_commercial_competitor)<span class="rounded bg-emerald-50 px-2 py-1 text-emerald-700">Ticari</span>@endif @if($competitor->is_serp_competitor)<span class="rounded bg-violet-50 px-2 py-1 text-violet-700">SERP</span>@endif @if($competitor->is_content_competitor)<span class="rounded bg-amber-50 px-2 py-1 text-amber-700">İçerik</span>@endif</div>
                        </div>
                        <div class="mt-4 grid gap-4 text-xs lg:grid-cols-4">
                            <div><p class="font-semibold text-gray-700 dark:text-gray-200">Hizmetler</p><p class="mt-1 text-gray-500">{{ $competitor->services->map(fn($service) => $service->primaryName?->raw_label)->filter()->join(' · ') ?: 'İlişki yok' }}</p></div>
                            <div><p class="font-semibold text-gray-700 dark:text-gray-200">Bölgeler</p><p class="mt-1 text-gray-500">{{ $competitor->serviceAreas->map(fn($area) => $area->label())->join(' · ') ?: 'İlişki yok' }}</p></div>
                            <div><p class="font-semibold text-gray-700 dark:text-gray-200">Kümeler</p><p class="mt-1 text-gray-500">{{ $competitor->clusters->pluck('name')->join(' · ') ?: 'İlişki yok' }}</p></div>
                            <div><p class="font-semibold text-gray-700 dark:text-gray-200">Kanıt kapsamı</p><p class="mt-1 text-gray-500">{{ $competitor->queries_count }} sorgu · {{ $competitor->urls_count }} URL · {{ $competitor->sources_count }} kaynak</p></div>
                        </div>
                        <p class="mt-3 text-[11px] text-gray-400">Kaynaklar: {{ $competitor->sources->map(fn($source) => $source->provider ? $source->provider.' · '.$source->source_type : $source->source_type)->unique()->join(', ') ?: '—' }}</p>
                        @if($competitor->queries->isNotEmpty())<div class="mt-3 flex flex-wrap gap-1">@foreach($competitor->queries->take(8) as $query)<span class="rounded bg-gray-50 px-2 py-1 text-[10px] text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ $query->effectiveQueryText() }}{{ $query->pivot->best_observed_rank ? ' · #'.$query->pivot->best_observed_rank : '' }}</span>@endforeach</div>@endif
                        @if($competitor->urls->isNotEmpty())<div class="mt-3 space-y-1">@foreach($competitor->urls->sortByDesc('last_observed_at')->take(5) as $url)<p class="break-all text-[11px] text-gray-500">{{ $url->url }}</p>@endforeach</div>@endif
                        @if($competitor->notes)<p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ $competitor->notes }}</p>@endif
                        <div class="mt-3 flex flex-wrap items-center gap-2"><button wire:click="editCompetitor({{ $competitor->id }})" type="button" class="rounded px-3 py-1.5 text-xs font-semibold text-brand-600 ring-1 ring-inset ring-brand-200">Sınıflandır / ilişkilendir</button>@if($competitor->status === 'pending')<button wire:click="reviewCompetitor({{ $competitor->id }}, 'approved')" type="button" class="rounded bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white">Onayla</button><button wire:click="reviewCompetitor({{ $competitor->id }}, 'rejected')" type="button" class="rounded bg-red-600 px-3 py-1.5 text-xs font-semibold text-white">Reddet</button><span class="text-[10px] text-amber-700">DataForSEO bu kaydı otomatik ticari rakip yapmaz.</span>@endif</div>
                    </article>
                @empty
                    <div class="px-5 py-10 text-center text-sm text-gray-500">Bu filtrelerde rakip kaydı yok.</div>
                @endforelse
            </div>
        </section>
    @endif
</div>
