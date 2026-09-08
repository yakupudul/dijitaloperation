<div class="space-y-5">
    <header class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Kütüphane</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Hizmetler</h1>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Tüm markaların ortak hizmet listesi. Buradaki düzenlemeler bağlı markalara yansır.</p>
        </div>
        <div class="flex gap-2">
            <button type="button" wire:click="manageCategories" class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">Sektörleri yönet</button>
            <button type="button" wire:click="$set('bulkOpen', true)" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm dark:border-gray-700 dark:text-gray-200">Toplu ekle</button>
            <button type="button" wire:click="createService" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600">+ Hizmet ekle</button>
        </div>
    </header>

    @if ($message !== '')
        <div role="status" aria-live="polite" class="flex items-center justify-between gap-3 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200">
            <span>{{ $message }}</span>
            <button type="button" wire:click="$set('message', '')" aria-label="Bildirimi kapat" class="px-2 py-1">×</button>
        </div>
    @endif

    <section class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <div class="flex flex-wrap items-end gap-3 border-b border-gray-200 p-4 dark:border-gray-800">
            <div class="min-w-48 flex-1">
                <label for="service-search" class="mb-1.5 block text-xs font-medium text-gray-600 dark:text-gray-300">Hizmet ara</label>
                <input id="service-search" wire:model.live.debounce.350ms="search" type="search" placeholder="Hizmet adı veya eş ad" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white" />
            </div>
            <div>
                <label for="sector-filter" class="mb-1.5 block text-xs font-medium text-gray-600 dark:text-gray-300">Sektör</label>
                <select id="sector-filter" wire:model.live="sector" class="w-full rounded-lg border-gray-300 text-sm sm:w-56 dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                    <option value="">Tüm sektörler</option>
                    <option value="__none">Kategorisiz</option>
                    @foreach ($sectorOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="service-status" class="mb-1.5 block text-xs font-medium text-gray-600 dark:text-gray-300">Durum</label>
                <select id="service-status" wire:model.live="status" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                    <option value="all">Tümü</option><option value="active">Aktif</option><option value="archived">Arşiv</option><option value="deleted">Silinenler</option>
                </select>
            </div>
        </div>
        <div class="flex items-center justify-between px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
            <span>{{ $services->total() }} hizmet</span>
            <span wire:loading wire:target="search,sector,status,gotoPage,nextPage,previousPage">Liste güncelleniyor…</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Global hizmetler ve kullanıldıkları marka ve sorgu sayıları</caption>
                <thead class="border-y border-gray-100 bg-gray-50 text-xs font-medium text-gray-500 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-400">
                    <tr>
                        <th scope="col" class="px-5 py-3 font-medium">Hizmet</th>
                        <th scope="col" class="px-5 py-3 font-medium">Sektör</th>
                        <th scope="col" class="px-5 py-3 text-right font-medium">Marka</th>
                        <th scope="col" class="px-5 py-3 text-right font-medium">Sorgu</th>
                        <th scope="col" class="px-5 py-3 text-right font-medium">İşlemler</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($services as $service)
                        <tr wire:key="service-{{ $service->id }}" class="align-top hover:bg-gray-50 dark:hover:bg-gray-800/40">
                            <td class="px-5 py-4">
                                @if (! $service->trashed())
                                    <button type="button" wire:click="editService({{ $service->id }})" class="text-left font-semibold text-gray-900 hover:text-brand-600 dark:text-white">{{ $service->primaryName?->raw_label ?? 'İsimsiz hizmet' }}</button>
                                @else
                                    <span class="font-semibold text-gray-700 dark:text-gray-300">{{ $service->primaryName?->raw_label ?? 'İsimsiz hizmet' }}</span>
                                @endif
                                @if ($service->status === 'archived')<span class="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-500 dark:bg-gray-800">Arşiv</span>@endif
                                @if ($service->description)<p class="mt-1 max-w-md text-xs leading-5 text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Str::limit($service->description, 110) }}</p>@endif
                                <p class="mt-1 text-xs text-gray-500">{{ $service->matching_keywords_count }} eşleştirme ifadesi</p>
                            </td>
                            <td class="px-5 py-4 text-gray-600 dark:text-gray-300">{{ $sectorOptions[$service->sector] ?? ($service->sector ?: 'Kategorisiz') }}</td>
                            <td class="px-5 py-4 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ $service->brand_offerings_count }}</td>
                            <td class="px-5 py-4 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ $service->search_queries_count }}</td>
                            <td class="whitespace-nowrap px-5 py-3 text-right">
                                @if ($service->trashed())
                                    <button type="button" wire:click="restoreService({{ $service->id }})" wire:loading.attr="disabled" class="rounded-lg px-3 py-2 text-xs font-medium text-brand-600 hover:bg-brand-50 disabled:opacity-50">Geri al</button>
                                @else
                                    <button type="button" wire:click="editService({{ $service->id }})" class="rounded-lg px-3 py-2 text-xs font-medium text-brand-600 hover:bg-brand-50 dark:hover:bg-brand-500/10">Düzenle</button>
                                    <button type="button" wire:click="deleteService({{ $service->id }})" wire:confirm="Bu hizmet {{ $service->brand_offerings_count }} marka dahil tüm güncel listelerden kaldırılacak. Silinenler filtresinden geri alınabilir. Silinsin mi?" wire:loading.attr="disabled" class="rounded-lg px-3 py-2 text-xs text-gray-500 hover:bg-red-50 hover:text-red-700 disabled:opacity-50">Sil</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-14 text-center">
                            <p class="font-medium text-gray-700 dark:text-gray-200">{{ $search !== '' || $sector !== '' ? 'Aramanızla eşleşen hizmet bulunamadı.' : 'Bu görünümde hizmet bulunmuyor.' }}</p>
                            <p class="mt-2 text-sm text-gray-500">Filtreleri değiştirebilir veya yeni bir hizmet ekleyebilirsiniz.</p>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3 dark:border-gray-800">{{ $services->links() }}</div>
    </section>

    @if ($bulkImports->isNotEmpty())
        <details @if($bulkImports->whereIn('status', ['queued','running'])->isNotEmpty()) wire:poll.5s @endif class="rounded-xl border border-gray-200 p-4 text-sm dark:border-gray-800 dark:text-gray-200">
            <summary class="cursor-pointer">Son toplu eklemeler</summary>
            @foreach ($bulkImports as $import)
                <p class="mt-2">#{{ $import->id }} · {{ ['queued'=>'Sırada','running'=>'İşleniyor','completed'=>'Tamamlandı','partial'=>'Kısmen tamamlandı','failed'=>'Başarısız'][$import->status] ?? $import->status }} · {{ $import->accepted_rows }} yeni · {{ $import->skipped_rows }} mevcut · {{ $import->failed_rows }} hata</p>
                @if ($import->error_summary)<p class="mt-1 whitespace-pre-line text-xs text-red-600">{{ $import->error_summary }}</p>@endif
            @endforeach
        </details>
    @endif
    @if ($bulkOpen)
        <div x-data @keydown.escape.window="$wire.set('bulkOpen', false)" class="fixed inset-0 z-[100000] flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-gray-950/40"></div>
            <section role="dialog" aria-modal="true" aria-labelledby="bulk-services-title" x-trap.inert.noscroll="true" class="relative w-full max-w-xl rounded-xl bg-white p-6 dark:bg-gray-900 dark:text-white">
                <h2 id="bulk-services-title" class="text-lg font-semibold">Toplu hizmet ekle</h2>
                <form wire:submit="queueBulk" class="mt-4 space-y-4">
                    <label class="block text-sm">Sektör *
                        <select wire:model="bulk_sector" required class="mt-2 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-950"><option value="">Sektör seçin</option>@foreach ($sectorOptions as $code=>$label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select>
                    </label>
                    <label class="block text-sm">Her satıra bir hizmet
                        <textarea wire:model="bulk_text" rows="9" required class="mt-2 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-950" placeholder="İmplant tedavisi | implant, tek diş, vidalı diş&#10;Diş beyazlatma | beyazlatma, bleaching"></textarea>
                    </label>
                    <p class="text-xs text-gray-500">Eşleştirme kelimelerini isteğe bağlı olarak | işaretinden sonra yazın. Mevcut hizmetler çoğaltılmaz; farklı sektördeki hizmetler hata olarak gösterilir. En fazla 2.000 satır.</p>
                    @if ($errors->any())<p class="text-sm text-red-600">{{ $errors->first() }}</p>@endif
                    <div class="flex justify-end gap-2"><button type="button" wire:click="$set('bulkOpen', false)" class="px-4 py-2 text-sm">Vazgeç</button><button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Hizmetleri ekle</button></div>
                </form>
            </section>
        </div>
    @endif

    @if ($editorOpen)
        <div x-data x-init="$nextTick(() => $refs.serviceName.focus())" @keydown.escape.window="$wire.closeEditor()" class="fixed inset-0 z-[100000]">
            <button type="button" wire:click="closeEditor" aria-label="Düzenleme panelini kapat" class="absolute inset-0 bg-gray-950/40"></button>
            <section role="dialog" aria-modal="true" aria-labelledby="service-editor-title" x-trap.inert.noscroll="true" class="absolute inset-y-0 right-0 flex w-full max-w-lg flex-col bg-white dark:bg-gray-900">
                <div class="flex items-center justify-between border-b border-gray-200 p-5 dark:border-gray-800">
                    <h2 id="service-editor-title" class="text-lg font-semibold text-gray-900 dark:text-white">{{ $editingId ? 'Hizmeti düzenle' : 'Hizmet ekle' }}</h2>
                    <button type="button" wire:click="closeEditor" aria-label="Kapat" class="rounded-lg px-3 py-2 text-gray-500 hover:bg-gray-100">×</button>
                </div>
                <div class="flex-1 overflow-y-auto p-5">
                    @if ($editing)
                        <p class="mb-5 text-sm leading-6 text-gray-500 dark:text-gray-400">Bu hizmet {{ $editing->brand_offerings_count }} markada ve {{ $editing->search_queries_count }} sorguda kullanılıyor. Değişiklik tüm bağlı markalara yansır.</p>
                    @endif
                    <form id="service-editor-form" wire:submit="saveService" class="space-y-5">
                        <div>
                            <label for="service-name" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200">Hizmet adı <span aria-hidden="true">*</span></label>
                            <input x-ref="serviceName" id="service-name" wire:model="service_name" type="text" maxlength="255" required class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white" placeholder="Örn. İmplant tedavisi" />
                            @error('service_name')<p role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="service-sector" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200">Sektör</label>
                            <select id="service-sector" wire:model="service_sector" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                                <option value="">Kategorisiz</option>
                                @foreach ($sectorOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </select>
                            @error('service_sector')<p role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="service-description" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200">Açıklama</label>
                            <textarea id="service-description" wire:model="service_description" rows="4" maxlength="2000" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white" placeholder="Hizmetin kapsamını kısaca açıklayın."></textarea>
                            @error('service_description')<p role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="matching-words" class="mb-2 block text-sm font-medium dark:text-gray-200">Sorgu eşleştirme kelimeleri</label>
                            <textarea id="matching-words" wire:model="matching_words" rows="5" placeholder="implant, tek diş, vidalı diş" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white"></textarea>
                            <p class="mt-1 text-xs text-gray-500">Virgülle veya satır satır yazın. Bu ifadeleri içeren sorgular, içe aktarırken bu hizmet seçilmişse eşleştirilir.</p>
                            @error('matching_words')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </form>
                    @if ($editing)
                        <div class="mt-7 border-t border-gray-200 pt-5 dark:border-gray-800">
                            <h3 class="text-sm font-medium text-gray-800 dark:text-gray-200">Eş adlar</h3>
                            <p class="mt-1 text-xs text-gray-500">Aynı hizmet için kullanılan diğer adlar.</p>
                            <ul class="mt-3 space-y-1">
                                @foreach ($editing->names->where('is_primary', false) as $name)
                                    <li wire:key="alias-{{ $name->id }}" class="flex items-center justify-between gap-2 py-1 text-sm text-gray-600 dark:text-gray-300">
                                        <span>{{ $name->raw_label }}</span>
                                        <button type="button" wire:click="removeAlias({{ $name->id }})" class="px-2 py-1 text-xs text-gray-500 hover:text-red-600" aria-label="{{ $name->raw_label }} eş adını kaldır">Kaldır</button>
                                    </li>
                                @endforeach
                            </ul>
                            <form wire:submit="addAlias" class="mt-3 flex gap-2">
                                <input wire:model="alias" type="text" aria-label="Yeni eş ad" placeholder="Alternatif ad" maxlength="255" class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white" />
                                <button type="submit" wire:loading.attr="disabled" class="rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:text-gray-200">Ekle</button>
                            </form>
                            @error('alias')<p role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                    @endif
                </div>
                <div class="flex items-center justify-between gap-3 border-t border-gray-200 p-5 dark:border-gray-800">
                    <div>@if ($editing)<button type="button" wire:click="toggleStatus({{ $editingId }})" class="text-xs text-gray-500">{{ $editing->status === 'active' ? 'Arşivle' : 'Etkinleştir' }}</button>@endif</div>
                    <div class="flex gap-2">
                        <button type="button" wire:click="closeEditor" class="rounded-lg px-4 py-2.5 text-sm text-gray-600 dark:text-gray-300">Vazgeç</button>
                        <button type="submit" form="service-editor-form" wire:loading.attr="disabled" wire:target="saveService" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">Kaydet</button>
                    </div>
                </div>
            </section>
        </div>
    @endif

    @if ($categoriesOpen)
        <div x-data @keydown.escape.window="$wire.set('categoriesOpen', false)" class="fixed inset-0 z-[100000] flex items-center justify-center p-4">
            <button type="button" wire:click="$set('categoriesOpen', false)" aria-label="Sektör penceresini kapat" class="absolute inset-0 bg-gray-950/40"></button>
            <section role="dialog" aria-modal="true" aria-labelledby="categories-title" x-trap.inert.noscroll="true" class="relative flex max-h-[85vh] w-full max-w-xl flex-col rounded-xl bg-white dark:bg-gray-900">
                <div class="flex items-center justify-between border-b border-gray-200 p-5 dark:border-gray-800">
                    <div><h2 id="categories-title" class="text-lg font-semibold text-gray-900 dark:text-white">Sektörler</h2><p class="mt-1 text-xs text-gray-500">Hizmetleri gruplamak için sektör ekleyin veya düzenleyin.</p></div>
                    <button type="button" wire:click="$set('categoriesOpen', false)" aria-label="Kapat" class="px-3 py-2 text-gray-500">×</button>
                </div>
                <div class="border-b border-gray-200 p-5 dark:border-gray-800">
                    <form wire:submit="saveCategory">
                        <label for="category-name" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200">{{ $categoryId ? 'Sektörü düzenle' : 'Yeni sektör' }}</label>
                        <div class="flex gap-2">
                            <input id="category-name" wire:model="categoryName" maxlength="120" required class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white" placeholder="Örn. Ev ve yaşam" />
                            <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{{ $categoryId ? 'Kaydet' : 'Ekle' }}</button>
                            @if ($categoryId)<button type="button" wire:click="cancelCategoryEdit" class="px-2 text-xs text-gray-500">Vazgeç</button>@endif
                        </div>
                        @error('categoryName')<p role="alert" class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
                    </form>
                </div>
                <ul class="flex-1 divide-y divide-gray-100 overflow-y-auto px-5 dark:divide-gray-800">
                    @forelse ($categories as $category)
                        <li wire:key="category-{{ $category->id }}" class="flex items-center justify-between gap-3 py-3">
                            <span class="text-sm text-gray-800 dark:text-gray-200">{{ $category->name }}</span>
                            <div class="flex gap-2">
                                <button type="button" wire:click="editCategory({{ $category->id }})" class="rounded-lg px-2 py-1.5 text-xs text-brand-600">Düzenle</button>
                                <button type="button" wire:click="deleteCategory({{ $category->id }})" wire:confirm="Bu sektör silinecek. İçindeki hizmetler korunarak Kategorisiz alanına taşınacak. Devam edilsin mi?" wire:loading.attr="disabled" class="rounded-lg px-2 py-1.5 text-xs text-gray-500 hover:text-red-600">Sil</button>
                            </div>
                        </li>
                    @empty
                        <li class="py-8 text-center text-sm text-gray-500">Henüz sektör yok. İlk sektörü yukarıdan ekleyebilirsiniz.</li>
                    @endforelse
                </ul>
            </section>
        </div>
    @endif
</div>
