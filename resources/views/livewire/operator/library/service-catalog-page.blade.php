@php
    $input = 'h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
    $area = 'w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
    $label = 'mb-1 block text-[13px] text-gray-600 dark:text-gray-300';
@endphp

<div class="space-y-10">
    <header class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="min-w-0">
            <h1 class="page-title" title="Tüm markaların ortak hizmet listesi. Buradaki düzenlemeler bağlı markalara yansır.">Hizmetler</h1>
            <p class="page-sub">{{ $services->total() }} hizmet. Buradaki düzenleme bağlı bütün markalara yansır.</p>
        </div>
        <div class="flex w-full gap-2 sm:w-auto">
            <button type="button" wire:click="manageCategories" class="btn flex-1 sm:flex-none">Sektörleri yönet</button>
            <button type="button" wire:click="createService" class="btn-primary flex-1 sm:flex-none">Hizmet ekle</button>
        </div>
    </header>

    @if ($message !== '')
        <div role="status" aria-live="polite" class="tone-ok -mt-4 flex items-center justify-between gap-3 rounded-lg px-4 py-2.5 text-sm">
            <span>{{ $message }}</span>
            <button type="button" wire:click="$set('message', '')" aria-label="Bildirimi kapat" class="px-2 py-1">×</button>
        </div>
    @endif

    <section class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <div class="min-w-48 flex-1">
                <label for="service-search" class="sr-only">Hizmet ara</label>
                <input id="service-search" wire:model.live.debounce.350ms="search" type="search" placeholder="Hizmet adı veya eş ad ara" class="{{ $input }} h-8! text-[13px]!" />
            </div>
            <div class="w-full sm:w-auto">
                <label for="sector-filter" class="sr-only">Sektör</label>
                <select id="sector-filter" wire:model.live="sector" class="{{ $input }} h-8! text-[13px]! sm:w-56">
                    <option value="">Tüm sektörler</option>
                    <option value="__none">Kategorisiz</option>
                    @foreach ($sectorOptions as $value => $label2)<option value="{{ $value }}">{{ $label2 }}</option>@endforeach
                </select>
            </div>
            <div class="w-full sm:w-auto">
                <label for="service-status" class="sr-only">Durum</label>
                <select id="service-status" wire:model.live="status" class="{{ $input }} h-8! text-[13px]! sm:w-36">
                    <option value="all">Tümü</option><option value="active">Aktif</option><option value="archived">Arşiv</option><option value="deleted">Silinenler</option>
                </select>
            </div>
        </div>

        <div>
            <div class="section-head">
                <h2 class="section-title">Hizmet listesi <span class="section-count">{{ $services->total() }}</span></h2>
                <span class="ml-auto text-[13px] text-gray-500" wire:loading wire:target="search,sector,status,gotoPage,nextPage,previousPage">Liste güncelleniyor…</span>
            </div>
            <div class="card overflow-x-auto">
                <table class="data-table">
                    <caption class="sr-only">Global hizmetler ve kullanıldıkları marka ve sorgu sayıları</caption>
                    <thead>
                        <tr>
                            <th scope="col">Hizmet</th>
                            <th scope="col" class="hidden md:table-cell">Sektör</th>
                            <th scope="col" class="text-right!">Marka</th>
                            <th scope="col"><span class="sr-only">İşlemler</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($services as $service)
                            <tr wire:key="service-{{ $service->id }}" class="align-top">
                                <td>
                                    @if (! $service->trashed())
                                        <button type="button" wire:click="editService({{ $service->id }})" class="text-left font-medium text-gray-900 hover:underline dark:text-white">{{ $service->primaryName?->raw_label ?? 'İsimsiz hizmet' }}</button>
                                    @else
                                        <span class="font-medium text-gray-500 line-through dark:text-gray-400">{{ $service->primaryName?->raw_label ?? 'İsimsiz hizmet' }}</span>
                                    @endif
                                    @if ($service->status === 'archived')<x-ta.pill tone="info" class="ml-2">Arşiv</x-ta.pill>@endif
                                    @if ($service->description)<p class="mt-0.5 max-w-md text-[13px] text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Str::limit($service->description, 110) }}</p>@endif
                                    <p class="mt-0.5 text-xs text-gray-500"><span class="md:hidden">{{ $sectorOptions[$service->sector] ?? ($service->sector ?: 'Kategorisiz') }}, </span>{{ $service->matching_keywords_count }} eşleştirme ifadesi</p>
                                </td>
                                <td class="hidden text-[13px] text-gray-600 md:table-cell dark:text-gray-300">{{ $sectorOptions[$service->sector] ?? ($service->sector ?: 'Kategorisiz') }}</td>
                                <td class="text-right tabular-nums text-gray-700 dark:text-gray-300">{{ $service->brand_offerings_count }}</td>
                                <td class="whitespace-nowrap text-right">
                                    @if ($service->trashed())
                                        <button type="button" wire:click="restoreService({{ $service->id }})" wire:loading.attr="disabled" class="btn h-7! px-2.5! text-xs!">Geri al</button>
                                    @else
                                        <button type="button" wire:click="editService({{ $service->id }})" class="btn h-7! px-2.5! text-xs!">Düzenle</button>
                                        <button type="button" wire:click="deleteService({{ $service->id }})" wire:confirm="Bu hizmet {{ $service->brand_offerings_count }} marka dahil tüm güncel listelerden kaldırılacak. Silinenler filtresinden geri alınabilir. Silinsin mi?" wire:loading.attr="disabled" class="btn-quiet h-7! px-2! text-xs! hover:text-error-700">Sil</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="p-0!">
                                <x-ta.empty-state icon="none" size="sm" :title="$search !== '' || $sector !== '' ? 'Aramanızla eşleşen hizmet bulunamadı.' : 'Bu görünümde hizmet bulunmuyor.'" />
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="pt-3">{{ $services->links() }}</div>
        </div>
    </section>

    @if ($editorOpen)
        <div x-data x-init="$nextTick(() => $refs.serviceName.focus())" @keydown.escape.window="$wire.closeEditor()" class="fixed inset-0 z-[100000]">
            <button type="button" wire:click="closeEditor" aria-label="Düzenleme panelini kapat" class="absolute inset-0 bg-gray-950/40"></button>
            <section role="dialog" aria-modal="true" aria-labelledby="service-editor-title" x-trap.inert.noscroll="true" class="absolute inset-y-0 right-0 flex w-full max-w-lg flex-col border-l border-gray-200 bg-white shadow-theme-lg dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                    <h2 id="service-editor-title" class="section-title">{{ $editingId ? 'Hizmeti düzenle' : 'Hizmet ekle' }}</h2>
                    <button type="button" wire:click="closeEditor" aria-label="Kapat" class="btn-quiet text-base">×</button>
                </div>
                <div class="flex-1 overflow-y-auto p-5">
                    @if ($editing)
                        <p class="mb-5 text-[13px] text-gray-500 dark:text-gray-400">Bu hizmet {{ $editing->brand_offerings_count }} markada kullanılıyor. Değişiklik tüm bağlı markalara yansır.</p>
                    @endif
                    <form id="service-editor-form" wire:submit="saveService" class="space-y-5">
                        <div>
                            <label for="service-name" class="{{ $label }}">Hizmet adı <span aria-hidden="true">*</span></label>
                            <input x-ref="serviceName" id="service-name" wire:model="service_name" type="text" maxlength="255" required class="{{ $input }}" placeholder="Örn. İmplant tedavisi" />
                            @error('service_name')<p role="alert" class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="service-sector" class="{{ $label }}">Sektör</label>
                            <select id="service-sector" wire:model="service_sector" class="{{ $input }}">
                                <option value="">Kategorisiz</option>
                                @foreach ($sectorOptions as $value => $label2)<option value="{{ $value }}">{{ $label2 }}</option>@endforeach
                            </select>
                            @error('service_sector')<p role="alert" class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="service-description" class="{{ $label }}">Açıklama</label>
                            <textarea id="service-description" wire:model="service_description" rows="4" maxlength="2000" class="{{ $area }}" placeholder="Hizmetin kapsamını kısaca açıklayın."></textarea>
                            @error('service_description')<p role="alert" class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="matching-words" class="{{ $label }}">Sorgu eşleştirme kelimeleri</label>
                            <textarea id="matching-words" wire:model="matching_words" rows="5" placeholder="implant, tek diş, vidalı diş" class="{{ $area }}"></textarea>
                            <p class="mt-1 text-xs text-gray-500">Virgülle veya satır satır yazın. Bu ifadeleri içeren sorgular, içe aktarırken bu hizmet seçilmişse eşleştirilir.</p>
                            @error('matching_words')<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                        </div>
                    </form>
                    @if ($editing)
                        <div class="mt-7 border-t border-gray-200 pt-5 dark:border-gray-800">
                            <h3 class="text-sm font-medium text-gray-900 dark:text-white">Eş adlar</h3>
                            <p class="mt-1 text-xs text-gray-500">Aynı hizmet için kullanılan diğer adlar.</p>
                            <ul class="mt-3 divide-y divide-gray-200 dark:divide-gray-800">
                                @foreach ($editing->names->where('is_primary', false) as $name)
                                    <li wire:key="alias-{{ $name->id }}" class="flex items-center justify-between gap-2 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <span>{{ $name->raw_label }}</span>
                                        <button type="button" wire:click="removeAlias({{ $name->id }})" class="btn-quiet h-7! px-2! text-xs! hover:text-error-700" aria-label="{{ $name->raw_label }} eş adını kaldır">Kaldır</button>
                                    </li>
                                @endforeach
                            </ul>
                            <form wire:submit="addAlias" class="mt-3 flex gap-2">
                                <input wire:model="alias" type="text" aria-label="Yeni eş ad" placeholder="Alternatif ad" maxlength="255" class="{{ $input }} min-w-0 flex-1" />
                                <button type="submit" wire:loading.attr="disabled" class="btn h-9!">Ekle</button>
                            </form>
                            @error('alias')<p role="alert" class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                        </div>
                    @endif
                </div>
                <div class="flex items-center justify-between gap-3 border-t border-gray-200 px-5 py-4 dark:border-gray-800">
                    <div>@if ($editing)<button type="button" wire:click="toggleStatus({{ $editingId }})" class="btn-quiet">{{ $editing->status === 'active' ? 'Arşivle' : 'Etkinleştir' }}</button>@endif</div>
                    <div class="flex gap-2">
                        <button type="button" wire:click="closeEditor" class="btn">Vazgeç</button>
                        <button type="submit" form="service-editor-form" wire:loading.attr="disabled" wire:target="saveService" class="btn-primary">Kaydet</button>
                    </div>
                </div>
            </section>
        </div>
    @endif

    {{-- Kayıt türleri (2026-11-07): what kind of business a sales record is. The record is generic; the Tür picks the
         message tone, whether moximu.com's clinic prices apply, and the health rules. Claude edits this list too (MCP). --}}
    <section data-lead-kinds>
        <div class="section-head">
            <h2 class="section-title" title="Satış kayıtlarının türleri. Tür; mesajdaki hitabı, fiyat listesinin geçerli olup olmadığını ve sağlık kurallarını belirler. Claude analizlerinden sonra bu listeye ekleyip çıkarabilir.">Kayıt türleri <span class="section-count">{{ count($kinds) }}</span></h2>
            <button type="button" wire:click="$toggle('kindsOpen')" class="btn ml-auto" data-lead-kinds-toggle>{{ $kindsOpen ? 'Kapat' : 'Tür ekle / düzenle' }}</button>
        </div>
        @if ($kindsOpen)
            <form wire:submit="saveKind" class="card mb-3 grid gap-3 p-5 sm:grid-cols-2 lg:grid-cols-5" data-lead-kind-form>
                <label class="block"><span class="{{ $label }}">Ad</span><input wire:model="kind.label" type="text" maxlength="60" placeholder="Örn. Otel" class="{{ $input }}" />@error('label')<span class="text-xs text-error-600">{{ $message }}</span>@enderror</label>
                <label class="block"><span class="{{ $label }}">Hitap</span><select wire:model="kind.address" class="{{ $input }}">@foreach (\App\Support\Sales\LeadKinds::ADDRESS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
                <label class="block"><span class="{{ $label }}">Fiyat listesi</span><select wire:model="kind.prices" class="{{ $input }}">@foreach (\App\Support\Sales\LeadKinds::PRICES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
                <label class="block"><span class="{{ $label }}">Eşleşen kelimeler</span><input wire:model="kind.hint" type="text" maxlength="300" placeholder="otel, hotel, pansiyon" class="{{ $input }}" /></label>
                <div class="flex items-end gap-2">
                    <label class="inline-flex h-9 items-center gap-1.5 text-[13px]"><input type="checkbox" wire:model="kind.health" class="rounded border-gray-300" /> Sağlık kuralları</label>
                    <button type="submit" class="btn-primary h-9!" wire:loading.attr="disabled" data-lead-kind-save>{{ $kind['key'] !== '' ? 'Kaydet' : 'Ekle' }}</button>
                </div>
                @error('key')<p class="text-xs text-error-600 lg:col-span-5">{{ $message }}</p>@enderror
                @error('address')<p class="text-xs text-error-600 lg:col-span-5">{{ $message }}</p>@enderror
            </form>
        @endif
        <ul class="card">
            @foreach ($kinds as $key => $k)
                <li wire:key="kind-{{ $key }}" class="card-row" data-lead-kind="{{ $key }}">
                    <div class="min-w-0 flex-1 sm:flex sm:items-baseline sm:gap-4">
                        <p class="text-sm font-medium text-gray-900 sm:w-44 sm:shrink-0 dark:text-white">{{ $k['label'] }} <span class="text-[12px] font-normal text-gray-400">{{ (int) ($kindCounts[$key] ?? 0) }} kayıt</span></p>
                        <p class="mt-0.5 text-[13px] text-gray-500 sm:mt-0 dark:text-gray-400">{{ \App\Support\Sales\LeadKinds::ADDRESS[$k['address']] ?? $k['address'] }} · {{ \App\Support\Sales\LeadKinds::PRICES[$k['prices']] ?? $k['prices'] }}{{ $k['health'] ? ' · sağlık kuralları' : '' }}{{ ($k['by'] ?? '') === 'claude' ? ' · Claude ekledi' : '' }}{{ filled($k['note'] ?? null) ? ' · '.$k['note'] : '' }}</p>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" wire:click="editKind('{{ $key }}')" class="btn h-7! px-2.5! text-xs!">Düzenle</button>
                        @if ($key !== \App\Support\Sales\LeadKinds::FALLBACK)
                            <button type="button" wire:click="removeKind('{{ $key }}')" wire:confirm="Bu tür silinecek; kayıtları &quot;Diğer&quot; olur. Devam edilsin mi?" class="btn-quiet h-7! px-2! text-xs! hover:text-error-700">Sil</button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    @if ($categoriesOpen)
        <div x-data @keydown.escape.window="$wire.set('categoriesOpen', false)" class="fixed inset-0 z-[100000] flex items-center justify-center p-4">
            <button type="button" wire:click="$set('categoriesOpen', false)" aria-label="Sektör penceresini kapat" class="absolute inset-0 bg-gray-950/40"></button>
            <section role="dialog" aria-modal="true" aria-labelledby="categories-title" x-trap.inert.noscroll="true" class="relative flex max-h-[85vh] w-full max-w-xl flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-theme-lg dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between border-b border-gray-200 p-5 dark:border-gray-800">
                    <div><h2 id="categories-title" class="section-title">Sektörler</h2><p class="mt-0.5 text-[13px] text-gray-500">Hizmetleri gruplamak için sektör ekleyin veya düzenleyin.</p></div>
                    <button type="button" wire:click="$set('categoriesOpen', false)" aria-label="Kapat" class="btn-quiet text-base">×</button>
                </div>
                <div class="border-b border-gray-200 p-5 dark:border-gray-800">
                    <form wire:submit="saveCategory">
                        <label for="category-name" class="{{ $label }}">{{ $categoryId ? 'Sektörü düzenle' : 'Yeni sektör' }}</label>
                        <div class="flex gap-2">
                            <input id="category-name" wire:model="categoryName" maxlength="120" required class="{{ $input }} min-w-0 flex-1" placeholder="Örn. Ev ve yaşam" />
                            <button type="submit" wire:loading.attr="disabled" class="btn-primary h-9!">{{ $categoryId ? 'Kaydet' : 'Ekle' }}</button>
                            @if ($categoryId)<button type="button" wire:click="cancelCategoryEdit" class="btn-quiet h-9!">Vazgeç</button>@endif
                        </div>
                        @error('categoryName')<p role="alert" class="mt-2 text-xs text-error-600">{{ $message }}</p>@enderror
                    </form>
                </div>
                <ul class="flex-1 divide-y divide-gray-200 overflow-y-auto px-5 dark:divide-gray-800">
                    @forelse ($categories as $category)
                        <li wire:key="category-{{ $category->id }}" class="flex items-center justify-between gap-3 py-3">
                            <span class="text-sm text-gray-800 dark:text-gray-200">{{ $category->name }}</span>
                            <div class="flex gap-2">
                                <button type="button" wire:click="editCategory({{ $category->id }})" class="btn h-7! px-2.5! text-xs!">Düzenle</button>
                                <button type="button" wire:click="deleteCategory({{ $category->id }})" wire:confirm="Bu sektör silinecek. İçindeki hizmetler korunarak Kategorisiz alanına taşınacak. Devam edilsin mi?" wire:loading.attr="disabled" class="btn-quiet h-7! px-2! text-xs! hover:text-error-700">Sil</button>
                            </div>
                        </li>
                    @empty
                        <li class="py-6 text-sm text-gray-500">Henüz sektör yok. İlk sektörü yukarıdan ekleyebilirsiniz.</li>
                    @endforelse
                </ul>
            </section>
        </div>
    @endif
</div>
