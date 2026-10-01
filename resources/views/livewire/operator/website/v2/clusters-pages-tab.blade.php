@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-white';
    $btn = 'rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50';
    $ghost = 'rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
    $chip = 'rounded-full px-2 py-0.5 text-xs';
    $num = fn ($value) => $value === null ? '—' : number_format((float) $value, 0, ',', '.');
@endphp
<div class="space-y-4" data-clusters-pages @if (in_array('çalışıyor…', $statuses, true)) wire:poll.5s @endif>
    @if ($message !== '')
        <p role="status" class="flex items-center justify-between rounded-lg bg-blue-50 p-2 text-blue-800 dark:bg-blue-950 dark:text-blue-200">
            <span>{{ $message }}</span><button type="button" wire:click="$set('message', '')" aria-label="Kapat" class="px-2">×</button>
        </p>
    @endif

    <section class="{{ $card }} flex flex-wrap items-center gap-2" data-steps>
        <button type="button" wire:click="run('categorize')" class="{{ $ghost }}">Sayfaları sınıflandır</button>
        <x-operator.ai-prompt-info operation="site.page_categories" />
        <button type="button" wire:click="run('service_pages')" class="{{ $btn }}">Hizmet ↔ sayfa</button>
        <x-operator.ai-prompt-info operation="site.service_pages" />
        <span class="text-xs text-gray-500">Küme ↔ sayfa eşlemesi ve durumları: İçerik fikirleri.</span>
        @foreach ($statuses as $op => $line)
            <span class="text-xs text-gray-500">{{ \App\Services\Site\SiteOperations::LABELS[$op] }}: {{ $line }}</span>
        @endforeach
    </section>

    {{-- Sayfalar --}}
    <section class="{{ $card }}" data-section="pages">
        <div class="mb-2 flex flex-wrap items-center gap-2">
            <h2 class="text-sm font-semibold">Sayfalar</h2>
            <select wire:model.live="category" aria-label="Kategori" class="{{ $input }}">
                <option value="">Tüm kategoriler</option>
                <option value="__none">Sınıflanmamış</option>
                @foreach (\App\Models\Page::CATEGORY_LABELS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Ara" aria-label="Ara" class="{{ $input }} w-48">
            <span class="text-xs text-gray-500">{{ $pages->total() }} sayfa · seçili {{ count($selected) }}</span>
            <button type="button" wire:click="analyzeSelected" @disabled($selected === []) class="{{ $btn }} ml-auto">Seçilenleri analiz et</button>
            <x-operator.ai-prompt-info operation="site.url_analysis" />
        </div>
        @error('selected')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="text-gray-500"><tr><th class="w-6 py-1"></th><th>URL</th><th>Kategori</th><th>Hizmet</th><th class="text-right">Tık 28g</th><th></th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($pages as $page)
                        @php $link = $links[$page->id] ?? null; $total = $totals[\App\Services\SeoTasks\SeoText::urlKey((string) $page->url)] ?? null; @endphp
                        <tr wire:key="page-{{ $page->id }}" data-page-row="{{ $page->id }}">
                            <td class="py-1"><input type="checkbox" wire:model.live="selected" value="{{ $page->id }}" aria-label="Seç"></td>
                            <td><a href="{{ $page->url }}" target="_blank" rel="noopener" class="font-medium hover:underline">{{ $page->path }}</a><p class="text-gray-500">{{ $page->title }}</p></td>
                            <td>
                                <select wire:change="setCategory({{ $page->id }}, $event.target.value)" aria-label="Kategori" class="{{ $input }}">
                                    @if ($page->category === null)<option value="">—</option>@endif
                                    @foreach (\App\Models\Page::CATEGORY_LABELS as $key => $label)<option value="{{ $key }}" @selected($page->category === $key)>{{ $label }}</option>@endforeach
                                </select>
                                <span class="text-gray-400">{{ $page->category_locked ? 'elle' : ($page->category_source === 'ai' ? 'AI' : '') }}</span>
                            </td>
                            <td>
                                @if (in_array($page->category, ['hizmet', 'lokasyon'], true))
                                    <select wire:change="setOffering({{ $page->id }}, $event.target.value)" aria-label="Hizmet" class="{{ $input }}">
                                        <option value="">—</option>
                                        @foreach ($offerings as $id => $name)<option value="{{ $id }}" @selected(($link['offering_id'] ?? null) === $id)>{{ $name }}</option>@endforeach
                                    </select>
                                    <span class="text-gray-400">{{ ($link['locked'] ?? false) ? 'elle' : (($link['source'] ?? null) === 'ai' ? 'AI' : '') }}</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="text-right tabular-nums">{{ $total !== null ? $num($total['clicks']) : '—' }}</td>
                            <td class="text-right"><button type="button" wire:click="analyze({{ $page->id }})" class="{{ $ghost }}">Analiz et</button> <x-operator.ai-prompt-info operation="site.url_analysis" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-3 text-gray-500">Sayfa yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-2">{{ $pages->links() }}</div>
    </section>
</div>
