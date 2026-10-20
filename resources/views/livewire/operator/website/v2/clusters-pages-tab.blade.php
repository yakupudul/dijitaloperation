@php
    $card = 'min-w-0';
    $input = 'h-8 rounded-lg border border-gray-200 bg-white px-2.5 text-[13px] text-gray-700 shadow-card focus:border-gray-300 focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
    $select = 'h-8 rounded-lg border border-gray-200 bg-white py-0 pl-2.5 pr-8 text-[13px] text-gray-700 shadow-card focus:border-gray-300 focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $btn = 'btn-primary';
    $ghost = 'btn';
    $num = fn ($value) => $value === null ? '—' : number_format((float) $value, 0, ',', '.');
@endphp
<div class="min-w-0 space-y-6 text-sm" data-clusters-pages @if (in_array('çalışıyor…', $statuses, true)) wire:poll.5s @endif>
    @if ($message !== '')
        <p role="status" class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-800 shadow-card dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-200">
            <span>{{ $message }}</span><button type="button" wire:click="$set('message', '')" aria-label="Kapat" class="btn-quiet h-7! px-2!">×</button>
        </p>
    @endif

    {{-- Is every page in the right category and tied to the right service? The rules (and AI on click) fill both; a hand change locks it. --}}
    <section class="{{ $card }}" data-section="pages">
        <div class="section-head justify-between">
            <div class="flex min-w-0 flex-wrap items-baseline gap-x-3">
                <h2 class="section-title">Sayfalar</h2>
                <span class="section-count">{{ $pages->total() }}</span>
                <span class="text-[13px] text-gray-500">kategori ve hizmet eşleşmesi</span>
            </div>
            <div class="flex flex-wrap items-center gap-1.5" data-steps>
                <span class="flex items-center gap-1"><button type="button" wire:click="run('categorize')" class="{{ $ghost }}">Sayfaları sınıflandır</button><x-operator.ai-prompt-info operation="site.page_categories" /></span>
                <span class="flex items-center gap-1"><button type="button" wire:click="run('service_pages')" class="{{ $ghost }}">Hizmetlerle eşleştir</button><x-operator.ai-prompt-info operation="site.service_pages" /></span>
            </div>
        </div>
        @if ($statuses !== [])
            <p class="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                @foreach ($statuses as $op => $line)<span>{{ \App\Services\Site\SiteOperations::LABELS[$op] }}: {{ $line }}</span>@endforeach
            </p>
        @endif

        <div class="mb-3 flex min-w-0 flex-wrap items-center gap-2">
            <select wire:model.live="category" aria-label="Kategori" class="{{ $select }} w-full sm:w-48">
                <option value="">Tüm kategoriler</option>
                <option value="__none">Sınıflanmamış</option>
                @foreach (\App\Models\Page::CATEGORY_LABELS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Ara" aria-label="Ara" class="{{ $input }} w-full sm:w-56">
            <span class="flex w-full items-center gap-2 sm:ml-auto sm:w-auto">
                <span class="text-xs text-gray-500">seçili {{ count($selected) }}</span>
                <button type="button" wire:click="analyzeSelected" @disabled($selected === []) class="{{ $btn }} flex-1 sm:flex-none">Seçilenleri analiz et</button>
                <x-operator.ai-prompt-info operation="site.url_analysis" />
            </span>
        </div>
        @error('selected')<p class="mb-2 text-xs text-error-600">{{ $message }}</p>@enderror

        <div class="card overflow-x-auto">
            <table class="data-table">
                <thead><tr><th class="w-6"></th><th>URL</th><th>Kategori</th><th>Hizmet</th><th class="text-right!">Tık 28g</th><th></th></tr></thead>
                <tbody>
                    @forelse ($pages as $page)
                        @php $link = $links[$page->id] ?? null; $total = $totals[\App\Services\SeoTasks\SeoText::urlKey((string) $page->url)] ?? null; @endphp
                        <tr wire:key="page-{{ $page->id }}" data-page-row="{{ $page->id }}">
                            <td><input type="checkbox" wire:model.live="selected" value="{{ $page->id }}" aria-label="Seç" class="rounded border-gray-300 dark:border-gray-700"></td>
                            <td class="min-w-[14rem]"><a href="{{ $page->url }}" target="_blank" rel="noopener" class="font-medium text-gray-900 hover:underline dark:text-white">{{ $page->path }}</a><p class="text-xs text-gray-500">{{ $page->title }}</p></td>
                            <td class="whitespace-nowrap">
                                <select wire:change="setCategory({{ $page->id }}, $event.target.value)" aria-label="Kategori" class="{{ $select }}">
                                    @if ($page->category === null)<option value="">—</option>@endif
                                    @foreach (\App\Models\Page::CATEGORY_LABELS as $key => $label)<option value="{{ $key }}" @selected($page->category === $key)>{{ $label }}</option>@endforeach
                                </select>
                                <span class="ml-1 text-xs text-gray-400">{{ $page->category_locked ? 'elle' : ($page->category_source === 'ai' ? 'AI' : '') }}</span>
                            </td>
                            <td class="whitespace-nowrap">
                                @if (in_array($page->category, ['hizmet', 'lokasyon'], true))
                                    <select wire:change="setOffering({{ $page->id }}, $event.target.value)" aria-label="Hizmet" class="{{ $select }} max-w-[14rem]">
                                        <option value="">—</option>
                                        @foreach ($offerings as $id => $name)<option value="{{ $id }}" @selected(($link['offering_id'] ?? null) === $id)>{{ $name }}</option>@endforeach
                                    </select>
                                    <span class="ml-1 text-xs text-gray-400">{{ ($link['locked'] ?? false) ? 'elle' : (($link['source'] ?? null) === 'ai' ? 'AI' : '') }}</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="text-right tabular-nums">{{ $total !== null ? $num($total['clicks']) : '—' }}</td>
                            <td class="whitespace-nowrap text-right"><button type="button" wire:click="analyze({{ $page->id }})" class="btn-quiet h-7! px-2! text-xs!">Analiz et</button> <x-operator.ai-prompt-info operation="site.url_analysis" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-[13px] text-gray-500">Sayfa yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($pages->hasPages())<div class="mt-3">{{ $pages->links() }}</div>@endif
    </section>
</div>
