@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-white';
    $btn = 'rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50';
    $ghost = 'rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
    $chip = 'rounded-full px-2 py-0.5 text-xs';
    $stateTone = ['sufficient' => 'bg-success-50 text-success-700', 'no_page' => 'bg-rose-50 text-rose-700', 'thin_coverage' => 'bg-amber-50 text-amber-700', 'weak_performance' => 'bg-amber-50 text-amber-700', 'possible_conflict' => 'bg-purple-50 text-purple-700', 'wrong_page' => 'bg-rose-50 text-rose-700', 'insufficient_data' => 'bg-gray-100 text-gray-600'];
    $num = fn ($value) => $value === null ? '—' : number_format((float) $value, 0, ',', '.');
@endphp
<div class="space-y-4" data-clusters-pages @if (in_array('çalışıyor…', $statuses, true)) wire:poll.5s @endif>
    @if ($message !== '')
        <p role="status" class="flex items-center justify-between rounded-lg bg-blue-50 p-2 text-blue-800 dark:bg-blue-950 dark:text-blue-200">
            <span>{{ $message }}</span><button type="button" wire:click="$set('message', '')" aria-label="Kapat" class="px-2">×</button>
        </p>
    @endif

    <section class="{{ $card }} flex flex-wrap items-center gap-2" data-audit>
        <button type="button" wire:click="run('cluster_audit')" class="{{ $btn }}" data-run-audit>Kümeleri içerikle karşılaştır</button>
        <x-operator.ai-prompt-info operation="site.cluster_match" />
        <x-operator.ai-prompt-info operation="site.cluster_gaps" />
        <x-operator.ai-prompt-info operation="queries.ai_queries" />
        <span class="text-xs text-gray-500">Markanın hizmetlerinin kümeleri sitedeki sayfaların içeriğiyle karşılaştırılır: hangi sayfa hangi kümeyi karşılıyor, neler eksik (sorular, yönler, AI soruları, gerekiyorsa hizmet bölgeleri).</span>
    </section>

    <section class="{{ $card }} flex flex-wrap items-center gap-2" data-steps>
        <button type="button" wire:click="run('categorize')" class="{{ $ghost }}">Sayfaları sınıflandır</button>
        <x-operator.ai-prompt-info operation="site.page_categories" />
        <button type="button" wire:click="run('service_pages')" class="{{ $btn }}">AI adım 1 · Hizmet ↔ sayfa</button>
        <x-operator.ai-prompt-info operation="site.service_pages" />
        <button type="button" wire:click="run('cluster_pages')" class="{{ $btn }}">AI adım 2 · Küme ↔ sayfa</button>
        <x-operator.ai-prompt-info operation="site.cluster_pages" />
        @foreach ($statuses as $op => $line)
            <span class="text-xs text-gray-500">{{ \App\Services\Site\SiteOperations::LABELS[$op] }}: {{ $line }}</span>
        @endforeach
    </section>

    {{-- Kümeler --}}
    <section class="{{ $card }}" data-section="clusters">
        <div class="mb-2 flex flex-wrap items-center gap-2">
            <h2 class="text-sm font-semibold">Kümeler</h2>
            <input type="search" wire:model.live.debounce.400ms="pageSearch" placeholder="URL ara" aria-label="URL ara" class="{{ $input }} w-48">
        </div>
        @forelse ($services as $service => $rows)
            <h3 class="mt-3 text-xs font-semibold uppercase text-gray-500">{{ $service }}</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="text-gray-500"><tr><th class="py-1">Küme</th><th>Durum</th><th>Hedef URL · ek URL</th><th>Ana sorgu · hedef sorgu</th><th class="text-right">Tık 28g</th><th class="text-right">Poz.</th><th></th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($rows as $row)
                            <tr wire:key="bcp-{{ $row->id }}" data-cluster-row="{{ $row->cluster_id }}">
                                <td class="py-1 font-medium">{{ $row->cluster?->name }}@if ($row->language)<span class="{{ $chip }} ml-1 bg-gray-100 text-gray-600">{{ $row->language }}</span>@endif @if ($row->locked)<span class="{{ $chip }} ml-1 bg-gray-100 text-gray-600">elle</span>@endif @if ($row->excluded)<span class="{{ $chip }} ml-1 bg-rose-50 text-rose-700">hariç</span>@endif</td>
                                <td>
                                    <span class="{{ $chip }} {{ $stateTone[$row->state] ?? '' }}">{{ $row->stateLabel() }}</span>
                                    @if ($row->coverage)<span class="{{ $chip }} ml-1 {{ ['full' => 'bg-success-50 text-success-700', 'partial' => 'bg-amber-50 text-amber-700', 'none' => 'bg-rose-50 text-rose-700'][$row->coverage] ?? '' }}" data-coverage="{{ $row->coverage }}">içerik: {{ \App\Models\BrandClusterPage::COVERAGE_LABELS[$row->coverage] ?? $row->coverage }}</span>@endif
                                    <p class="text-gray-500">{{ $row->reason }}</p>
                                    @php $gaps = (array) $row->gaps; $questions = $brand !== null && $row->cluster !== null ? \App\Services\Site\ClusterAudit::aiQuestions($row->cluster, $brand) : []; @endphp
                                    @if ($gaps !== [] || $questions !== [])
                                        <details class="mt-1" data-gaps>
                                            <summary class="cursor-pointer font-medium text-brand-600">Eksikleri gör ({{ count($gaps) }})</summary>
                                            <ul class="mt-1 list-disc space-y-0.5 pl-4">@foreach ($gaps as $gap)<li>{{ $gap['text'] ?? '' }} <span class="text-gray-400">· {{ ['soru' => 'soru', 'bolum' => 'bölüm', 'yon' => 'yön', 'lokasyon' => 'lokasyon', 'ai_sorusu' => 'AI sorusu'][$gap['kind'] ?? ''] ?? '' }}</span></li>@endforeach</ul>
                                            @if ($questions !== [])<p class="mt-1 font-medium text-gray-600">AI asistanına sorulanlar</p><ul class="list-disc pl-4 text-gray-500">@foreach ($questions as $question)<li>{{ $question }}</li>@endforeach</ul>@endif
                                        </details>
                                    @endif
                                    <div class="mt-1 flex gap-1">
                                        @if ($row->page_id !== null && $gaps !== [])<button type="button" wire:click="fixGaps({{ $row->id }})" class="{{ $btn }}" data-fix-gaps>Eksikleri gider</button>@endif
                                        @if ($row->page_id === null && ! $row->excluded)<button type="button" wire:click="clusterTopic({{ $row->id }})" class="{{ $btn }}" data-cluster-topic>Konu üret</button>@endif
                                    </div>
                                </td>
                                <td>
                                    <select wire:model="edit.{{ $row->id }}.page" aria-label="Hedef URL" class="{{ $input }} max-w-[14rem]">
                                        <option value="">— sayfa yok</option>
                                        @foreach ($pageOptions as $id => $path)<option value="{{ $id }}" @selected((int) $row->page_id === (int) $id)>{{ $path }}</option>@endforeach
                                    </select>
                                    <select multiple wire:model="edit.{{ $row->id }}.extra" aria-label="Ek URL" class="{{ $input }} mt-1 block h-14 max-w-[14rem]">
                                        @foreach ($pageOptions as $id => $path)<option value="{{ $id }}">{{ $path }}</option>@endforeach
                                    </select>
                                </td>
                                <td>{{ $row->cluster?->mainQuery?->text ?? '—' }}<input type="text" wire:model="edit.{{ $row->id }}.target" placeholder="{{ $row->target_query }}" aria-label="Hedef sorgu" class="{{ $input }} mt-1 block w-48"></td>
                                <td class="text-right tabular-nums">{{ $num($row->clicks_28d) }}</td>
                                <td class="text-right tabular-nums">{{ $row->position_28d !== null ? number_format($row->position_28d, 1, ',', '.') : '—' }}</td>
                                <td class="whitespace-nowrap text-right">
                                    <select wire:model="edit.{{ $row->id }}.state" aria-label="Durum" class="{{ $input }}">
                                        @foreach (\App\Models\BrandClusterPage::STATE_LABELS as $key => $label)<option value="{{ $key }}" @selected($row->state === $key)>{{ $label }}</option>@endforeach
                                    </select>
                                    <label class="text-gray-500"><input type="checkbox" wire:model="edit.{{ $row->id }}.excluded"> hariç</label>
                                    <button type="button" wire:click="saveCluster({{ $row->id }})" class="{{ $ghost }}">Kaydet</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <p class="text-gray-500">Küme eşleşmesi yok · "AI adım 2".</p>
        @endforelse
        <div class="mt-2">{{ $clusterRows->links() }}</div>
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
