<div class="space-y-5 dark:text-gray-200">
    <header class="flex flex-wrap items-center justify-between gap-4">
        <div><p class="text-xs text-gray-500">Kütüphane</p><h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Sorgular</h1><p class="mt-2 text-sm text-gray-500">Tüm kaynaklardaki sorguları bir araya getirin, sektör ve hizmetlere bağlayın.</p></div>
        <div class="flex flex-wrap items-center gap-2">
        <a href="{{ $exportUrl }}" wire:loading.class="pointer-events-none opacity-50" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium dark:border-gray-700">{{ __('query-list.download') }} ({{ $queries->total() }})</a>
        <button type="button" wire:click="$set('importOpen', true)" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">+ Sorgu ekle / içe aktar</button></div>
    </header>
    @if ($message)<div role="status" class="rounded-lg bg-blue-50 p-3 text-sm text-blue-800">{{ $message }} @if($undoQueryId)<button type="button" wire:click="restoreQuery({{ $undoQueryId }})" wire:loading.attr="disabled" class="ml-3 font-semibold underline">{{ __('query-list.undo') }}</button>@endif</div>@endif
    @if ($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif
    <livewire:operator.integrations.resource-automations :queries-only="true" />
    <livewire:operator.library.query-exclusions />
    @if($status === 'deleted' || $undoQueryId)
        <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300"><input type="checkbox" wire:model="protectRestoredQueries" class="rounded border-gray-300 text-brand-500" />{{ __('query-exclusions.protect_restored') }}</label>
    @endif
    <p class="text-xs text-gray-500">{{ __('query-list.scope_note') }}</p>
    <section class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <div class="flex flex-wrap items-center gap-3 border-b border-gray-200 p-4 dark:border-gray-800">
            <input wire:model.live.debounce.350ms="search" type="search" aria-label="Sorgu ara" placeholder="Sorgu ara…" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 min-w-48 flex-1" />
            <select wire:model.live="sectorFilter" aria-label="Sektör filtresi" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Tüm sektörler</option>@foreach ($sectorOptions as $code=>$label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select>
            <select wire:model.live="service" aria-label="{{ __('query-list.service_filter') }}" class="max-w-xs rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">{{ __('query-list.all_services') }}</option>@foreach ($serviceOptions as $id=>$label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>
            <select wire:model.live="source" aria-label="Kaynak filtresi" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Tüm kaynaklar</option>@foreach ($sourceOptions as $code=>$label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select>
            <select wire:model.live="status" aria-label="Durum" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="active">Aktif</option><option value="all">Tümü</option><option value="excluded">Hariç tutulan</option><option value="candidate">{{ __('query-list.status_candidate') }}</option><option value="archived">Arşiv</option><option value="deleted">{{ __('query-list.status_deleted') }}</option></select>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="unassigned" class="rounded border-gray-300 text-brand-500" /> Atanmayanlar</label>
            <button type="button" wire:click="clearFilters" class="text-xs font-medium text-brand-600">{{ __('query-list.clear_filters') }}</button>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-xs text-gray-500">
            <span>{{ $queries->total() }} sorgu · {{ count($selectedQueryIds) }} seçili</span>
            <div class="flex flex-wrap items-center gap-3">
                <select wire:model.live="sort" aria-label="{{ __('query-list.sort') }}" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950"><option value="newest">{{ __('query-list.newest') }}</option><option value="az">A → Z</option><option value="za">Z → A</option></select>
                <select wire:model.live="perPage" aria-label="{{ __('query-list.per_page') }}" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950"><option value="25">25</option><option value="50">50</option><option value="100">100</option></select>
                <button type="button" wire:click="selectPage" class="text-brand-600">Bu sayfayı seç</button><button type="button" wire:click="$set('selectedQueryIds', [])">Seçimi kaldır</button></div>
        </div>
        @if (count($selectedQueryIds))
            <div class="flex flex-wrap gap-3 border-t border-gray-100 px-4 py-3 text-xs dark:border-gray-800">
                @if($status === 'deleted')
                    <button type="button" wire:click="updateSelectedQueries('restore')" wire:loading.attr="disabled" class="font-medium text-brand-600">{{ __('query-list.restore_selected') }}</button>
                @else
                    <button type="button" wire:click="updateSelectedQueries('active')" wire:loading.attr="disabled" class="text-brand-600">{{ __('query-list.activate_selected') }}</button>
                    <button type="button" wire:click="updateSelectedQueries('excluded')" wire:loading.attr="disabled">{{ __('query-list.exclude_selected') }}</button>
                    <button type="button" wire:click="updateSelectedQueries('remove')" wire:loading.attr="disabled" class="font-medium text-red-600">{{ __('query-list.remove_selected') }}</button>
                @endif
            </div>
            @if($status !== 'deleted')
            <div class="border-y border-brand-100 bg-brand-50/40 p-4 dark:border-gray-700">
                <div class="grid gap-3 md:grid-cols-3">
                    <label class="text-xs font-medium">Sektör *
                        <select wire:model.live="assignmentSector" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 mt-2 w-full"><option value="">Sektör seçin</option>@foreach ($sectorOptions as $code=>$label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select>
                    </label>
                    <div><span class="text-xs font-medium">Hizmetler (isteğe bağlı)</span><div class="mt-2 max-h-32 space-y-2 overflow-y-auto">
                        @forelse ($assignmentServices as $item)<label class="flex items-center gap-2 text-sm"><input wire:model="assignmentServiceIds" value="{{ $item->id }}" type="checkbox" class="rounded border-gray-300 text-brand-500" />{{ $item->primaryName?->raw_label }}</label>@empty<p class="text-xs text-gray-500">Önce sektör seçin veya hizmet oluşturun.</p>@endforelse
                    </div></div>
                    <div class="flex items-start justify-end"><button type="button" wire:click="assignSelected" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">Seçilenlere ata</button></div>
                </div>
                @include('livewire.operator.library.query-inline-create', ['target' => 'assignment'])
            </div>
            @endif
        @endif
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-y border-gray-100 bg-gray-50 text-xs text-gray-500 dark:border-gray-800 dark:bg-gray-950"><tr><th class="w-10 px-4 py-3"><span class="sr-only">Seç</span></th><th class="px-4 py-3">Sorgu</th><th class="px-4 py-3">Sektör</th><th class="px-4 py-3">Hizmetler</th><th class="px-4 py-3">Kaynak</th><th class="px-4 py-3 text-right">İşlem</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($queries as $query)
                        <tr wire:key="query-{{ $query->id }}" class="align-top hover:bg-gray-50 dark:hover:bg-gray-800/40">
                            <td class="px-4 py-4"><input type="checkbox" wire:model.live="selectedQueryIds" value="{{ $query->id }}" aria-label="{{ $query->canonical_text }} seç" class="rounded border-gray-300 text-brand-500" /></td>
                            <td class="group max-w-md px-4 py-4 font-medium text-gray-900 dark:text-white">
                                @if($editingId === $query->id)
                                    <form wire:submit="saveQueryEdit" class="min-w-64 space-y-2">
                                        <input wire:model="editingText" x-data x-init="$nextTick(() => { $el.focus(); $el.select(); })" wire:keydown.escape="cancelQueryEdit" aria-label="{{ __('query-list.edit') }}" type="text" maxlength="1000" class="w-full rounded-lg border-brand-300 text-sm dark:bg-gray-950" />
                                        <div class="flex gap-3 text-xs"><button type="submit" wire:loading.attr="disabled" class="font-semibold text-brand-600">{{ __('query-list.save') }}</button><button type="button" wire:click="cancelQueryEdit">{{ __('query-list.cancel') }}</button></div>
                                        @error('editingText')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                                    </form>
                                @else
                                    <div class="flex items-start gap-2"><span class="break-words">{{ $query->canonical_text }}</span>
                                        @unless($query->trashed())
                                            <button type="button" wire:click="editQuery({{ $query->id }})" aria-label="{{ __('query-list.edit') }}" title="{{ __('query-list.edit') }}" class="shrink-0 rounded p-1 text-gray-400 hover:bg-brand-50 hover:text-brand-600 focus:opacity-100 sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100">
                                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m16 3 5 5M3 21l5-1L21 7a2 2 0 0 0-5-5L3 15v6Z"/></svg>
                                            </button>
                                        @endunless
                                    </div>
                                @endif
                                <span class="mt-2 inline-block rounded bg-gray-100 px-2 py-0.5 text-xs font-normal text-gray-500 dark:bg-gray-800">{{ __('query-list.status_'.($query->trashed() ? 'deleted' : $query->status)) }}</span>
                            </td>
                            <td class="px-4 py-4 text-xs">{{ $query->sectors->pluck('name')->implode(', ') ?: ($sectorOptions[$query->sector] ?? '—') }}</td>
                            <td class="px-4 py-4"><div class="flex max-w-sm flex-wrap gap-1">@forelse($query->services as $item)<span class="rounded-md bg-gray-100 px-2 py-1 text-xs dark:bg-gray-800">{{ $item->primaryName?->raw_label }} @unless($query->trashed())<button type="button" wire:click="removeServiceAssignment({{ $query->id }}, {{ $item->id }})" wire:confirm="{{ __('resource-auto.remove_service_confirm') }}" aria-label="{{ __('resource-auto.remove_service') }}" class="ml-1 text-gray-500 hover:text-red-600">×</button>@endunless</span>@empty<span class="text-xs text-amber-700">Atanmadı</span>@endforelse</div>@if(in_array($query->id, $blockedQueryIds) && !$query->trashed())<button type="button" wire:click="allowAutomaticMatching({{ $query->id }})" class="mt-2 text-xs text-brand-600 underline">{{ __('resource-auto.allow_matching') }}</button>@endif</td>
                            <td class="px-4 py-4 text-xs"><button type="button" wire:click="showSources({{ $query->id }})" class="text-brand-600">{{ $query->source_records_count }} kaynak kaydı</button></td>
                            <td class="px-4 py-4 text-right text-xs">@if($query->trashed())<button type="button" wire:click="restoreQuery({{ $query->id }})" wire:loading.attr="disabled" class="text-brand-600">{{ __('query-list.restore') }}</button>@else @if($query->status === 'active')<button type="button" wire:click="setQueryStatus({{ $query->id }}, 'excluded')" class="text-gray-500">Hariç tut</button>@else<button type="button" wire:click="setQueryStatus({{ $query->id }}, 'active')" class="text-brand-600">Etkinleştir</button>@endif <button type="button" wire:click="removeQuery({{ $query->id }})" wire:loading.attr="disabled" class="ml-3 text-red-600">{{ __('query-list.remove') }}</button>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-14 text-center"><p class="font-medium">Bu görünümde sorgu yok.</p><p class="mt-2 text-xs text-gray-500">Filtreleri değiştirin veya sorgu ekleyin.</p><button type="button" wire:click="clearFilters" class="mt-3 text-sm text-brand-600">{{ __('query-list.clear_filters') }}</button></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 p-4 dark:border-gray-800">{{ $queries->links() }}</div>
    </section>
    <details @if($imports->whereIn('status', ['queued','running'])->isNotEmpty()) wire:poll.5s @endif class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
        <summary class="cursor-pointer text-sm font-medium">İçe aktarma geçmişi</summary>
        @forelse($imports as $import)
            <div class="mt-3 border-t border-gray-100 pt-3 text-xs dark:border-gray-800">
                <div class="flex flex-wrap justify-between gap-2"><span>#{{ $import->id }} · {{ $import->source_type === 'assignment' ? 'Toplu atama' : ($sourceOptions[$import->source_type] ?? $import->source_type) }} · {{ $import->created_at?->format('d.m.Y H:i') }}</span><strong>{{ ['queued'=>'Sırada','running'=>'İşleniyor','processing'=>'İşleniyor','completed'=>'Tamamlandı','partial'=>'Kısmen tamamlandı','failed'=>'Başarısız'][$import->status] ?? $import->status }}</strong></div>
                <p class="mt-2 text-gray-500">{{ $import->accepted_rows }} {{ $import->source_type === 'assignment' ? 'atanan' : 'yeni' }} sorgu · {{ $import->skipped_rows }} mevcut / tekrar / boş · {{ $import->failed_rows }} hata · {{ $import->excluded_rows }} {{ __('query-exclusions.eliminated') }} · {{ $import->total_rows }} satır</p>
                @if($import->error_summary)<p class="mt-2 whitespace-pre-line text-red-600">{{ $import->error_summary }}</p>@endif
            </div>
        @empty<p class="mt-3 text-xs text-gray-500">Henüz içe aktarma yok.</p>@endforelse
    </details>
    <details class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><summary class="cursor-pointer text-sm font-medium">AI ile sorgu önerileri</summary><div class="mt-4">@include('livewire.operator.library.query-ai-panel')</div></details>
    @if($sourceItemId)
        <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <div class="flex justify-between"><h2 class="text-sm font-semibold">Özgün sorgular · son 50 kaynak kaydı</h2><button type="button" wire:click="closeSources" class="text-xs">Kapat</button></div>
            @foreach($sourceDetails as $record)<div class="mt-3 border-t border-gray-100 pt-3 text-sm dark:border-gray-800"><p>{{ $record->observed_text }}</p>@if(data_get($record->raw_payload, 'action') === 'rename')<p class="mt-1 text-xs text-gray-500">{{ __('query-list.previous_text') }}: {{ data_get($record->raw_payload, 'previous_text') }}</p>@endif<p class="mt-1 text-xs text-gray-500">{{ $sourceOptions[$record->source_type] ?? $record->source_type }} · Çıkarılan lokasyonlar: {{ implode(', ', (array) data_get($record->raw_payload, 'removed_locations', [])) ?: 'Yok' }}</p></div>@endforeach
        </section>
    @endif
    @if ($importOpen)
        <div x-data @keydown.escape.window="$wire.set('importOpen', false)" class="fixed inset-0 z-[100000]">
            <button type="button" wire:click="$set('importOpen', false)" aria-label="İçe aktarmayı kapat" class="absolute inset-0 bg-gray-950/40"></button>
            <section role="dialog" aria-modal="true" aria-labelledby="query-import-title" x-trap.inert.noscroll="true" class="absolute inset-y-0 right-0 flex w-full max-w-2xl flex-col bg-white dark:bg-gray-900">
                <header class="flex items-center justify-between border-b border-gray-200 p-5 dark:border-gray-800"><h2 id="query-import-title" class="text-lg font-semibold">Sorgu ekle / içe aktar</h2><button type="button" wire:click="$set('importOpen', false)" aria-label="Kapat" class="px-3 py-2">×</button></header>
                <div class="flex-1 space-y-5 overflow-y-auto p-5">
                    <label class="block text-sm font-medium">Kaynak
                        <select wire:model.live="importSource" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 mt-2 w-full"><option value="paste">Manuel / toplu metin</option><option value="xlsx">Excel / CSV dosyası</option><option value="google_ads">Google Ads · kayıtlı arama terimleri</option><option value="search_console">GSC · kayıtlı sorgular</option></select>
                    </label>
                    <label class="block text-sm font-medium">Sektör *
                        <select wire:model.live="importSector" required class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 mt-2 w-full"><option value="">Sektör seçin</option>@foreach($sectorOptions as $code=>$label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select>
                    </label>
                    <div><h3 class="text-sm font-medium">Eşleştirilecek hizmetler</h3><p class="mt-1 text-xs text-gray-500">İsteğe bağlı. Seçtiğiniz hizmetlerin eşleştirme kelimeleri kullanılır; eşleşmeyen sorgular atanmamış kalır.</p><div class="mt-3 grid max-h-44 gap-2 overflow-y-auto sm:grid-cols-2">
                        @forelse($importServices as $item)<label class="flex items-center gap-2 text-sm"><input wire:model="importServiceIds" value="{{ $item->id }}" type="checkbox" class="rounded border-gray-300 text-brand-500" />{{ $item->primaryName?->raw_label }}</label>@empty<p class="text-xs text-gray-500">Sektör seçin veya yeni hizmet oluşturun.</p>@endforelse
                    </div>@include('livewire.operator.library.query-inline-create', ['target' => 'import'])</div>
                    @if($importSource === 'paste')
                        <label class="block text-sm font-medium">Sorgular<textarea wire:model="paste_text" rows="10" placeholder="Her satıra bir sorgu yazın veya yapıştırın." class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 mt-2 w-full"></textarea></label>
                        <p class="text-xs text-gray-500">Tek sorgu veya en fazla 10.000 satır.</p>
                    @elseif(in_array($importSource, ['csv','xlsx']))
                        <label class="block rounded-xl border border-dashed border-gray-300 p-5 text-sm">Excel veya metin dosyası<input type="file" wire:model="import_file" accept=".xlsx,.csv,.tsv,.txt" class="mt-3 block w-full text-sm" /></label>
                        <p class="text-xs text-gray-500">XLSX, CSV, TSV veya TXT · En fazla 10 MB / 10.000 satır. İlk sütuna sorguları yazabilirsiniz; “sorgu”, “query”, “keyword” ve “search term” başlıkları desteklenir. XLSX ilk sayfayı okur.</p>
                    @else
                        <div><h3 class="text-sm font-medium">Hesaplar *</h3><div class="mt-3 max-h-56 space-y-2 overflow-y-auto">
                            @forelse($resources as $resource)<label class="flex items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700"><input wire:model="resourceIds" value="{{ $resource->id }}" type="checkbox" class="mt-1 rounded border-gray-300 text-brand-500" /><span>{{ $resource->display_name }}<span class="block text-xs text-gray-500">{{ $resource->external_id }}</span></span></label>@empty<p class="rounded-lg bg-gray-50 p-3 text-sm text-gray-500 dark:bg-gray-950">Sorgu verisi bulunan bağlı hesap yok. Entegrasyonlar ekranından hesap verilerini toplayın.</p>@endforelse
                        </div></div>
                        <div class="grid grid-cols-2 gap-3"><label class="text-sm">Başlangıç<input wire:model="dateFrom" type="date" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 mt-2 w-full" /></label><label class="text-sm">Bitiş<input wire:model="dateTo" type="date" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 mt-2 w-full" /></label></div>
                        <p class="text-xs text-gray-500">Sistemdeki kayıtlı terimler okunur. Bir işlemde en fazla 20 hesap / 10.000 farklı terim.</p>
                    @endif
                    <p class="text-xs text-gray-500">{{ __('query-exclusions.import_help') }}</p>
                    <div class="rounded-lg bg-gray-50 p-3 text-xs leading-5 text-gray-600 dark:bg-gray-950 dark:text-gray-400">Ülke, il ve ilçe adları tam kelime eşleşmesiyle çıkarılır. “Of”, “Kale” gibi başka anlamı da olan yer adları buna dahildir. Özgün sorgu kaynak kaydında korunur. Tekrarlar yeni sorgu oluşturmaz. Yalnızca lokasyondan oluşan satırlar hata listesine alınır.</div>
                    @if($errors->any())<p role="alert" class="text-sm text-red-600">{{ $errors->first() }}</p>@endif
                </div>
                <footer class="flex justify-end gap-2 border-t border-gray-200 p-5 dark:border-gray-800"><button type="button" wire:click="$set('importOpen', false)" class="px-4 py-2 text-sm">Vazgeç</button><button type="button" wire:click="startImport" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50"><span wire:loading.remove wire:target="startImport">İçe aktar</span><span wire:loading wire:target="startImport">Sıraya alınıyor…</span></button></footer>
            </section>
        </div>
    @endif
</div>