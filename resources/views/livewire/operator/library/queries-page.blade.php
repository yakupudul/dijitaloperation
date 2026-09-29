@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white';
    $btn = 'rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50';
    $ghost = 'rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
    $chip = 'rounded-full px-2 py-0.5 text-xs';
    $num = fn ($value) => number_format((float) $value, 0, ',', '.');
@endphp
<div class="space-y-4 text-sm dark:text-gray-200" data-queries-page @if ($polling) wire:poll.3s @endif>
    <header class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Sorgular</h1>
        <nav class="flex flex-wrap gap-1" aria-label="Sekmeler">
            @foreach (\App\Livewire\Operator\Library\QueriesPage::TABS as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')" data-tab="{{ $key }}" @class(['rounded-lg px-3 py-1.5 text-xs font-semibold', 'bg-brand-500 text-white' => $tab === $key, 'text-gray-600 ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700' => $tab !== $key])>{{ $label }}</button>
            @endforeach
        </nav>
    </header>

    @if ($message !== '')
        <p role="status" class="flex items-center justify-between rounded-lg bg-blue-50 p-2 text-blue-800 dark:bg-blue-950 dark:text-blue-200">
            <span>{{ $message }}</span><button type="button" wire:click="$set('message', '')" aria-label="Kapat" class="px-2">×</button>
        </p>
    @endif

    {{-- Filtreler --}}
    <section class="{{ $card }} flex flex-wrap items-end gap-2" data-filters>
        <select wire:model.live="sector" aria-label="Sektör" class="{{ $input }}">
            <option value="">Tüm sektörler</option>
            @foreach ($sectors as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
        </select>
        @if ($tab !== 'filters' && $tab !== 'keywords')
            <select wire:model.live="service" aria-label="Hizmet" class="{{ $input }}">
                <option value="">Tüm hizmetler</option>
                @if ($tab === 'queries')<option value="__none">Atanmamış</option>@endif
                @foreach ($services as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
        @endif
        @if ($tab === 'queries')
            <select wire:model.live="cluster" aria-label="Küme" class="{{ $input }}">
                <option value="">Tüm kümeler</option>
                <option value="__none">Kümesiz</option>
                @foreach ($clusterOptions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Ara" aria-label="Ara" class="{{ $input }} w-48">
        @endif
        @if ($tab === 'queries' || $tab === 'clusters')
            <button type="button" wire:click="clusterService" @disabled(! ctype_digit($service)) class="{{ $btn }} ml-auto" title="Hizmet seçin">AI ile kümele</button>
            @if (($clusterStatus['status'] ?? null) === 'running')<span class="text-xs text-gray-500">kümeleniyor…</span>
            @elseif (($clusterStatus['status'] ?? null) === 'ready')<span class="text-xs text-gray-500">{{ $clusterStatus['clusters'] }} küme · {{ $clusterStatus['suggested'] }} önerilen sorgu</span>
            @elseif ($clusterStatus !== null)<span class="text-xs text-rose-600">Kümeleme: {{ ['no_queries' => 'sorgu yok', 'no_sector' => 'hizmetin sektörü yok', 'no_provider' => 'AI bağlı değil', 'error' => 'hata'][$clusterStatus['status']] ?? $clusterStatus['status'] }}</span>@endif
        @endif
    </section>

    {{-- Sorgular --}}
    @if ($tab === 'queries')
        <section class="{{ $card }}" data-section="queries">
            <div class="mb-2 flex flex-wrap items-center gap-2">
                <span class="text-xs text-gray-500">{{ $num($queries->total()) }} sorgu · seçili {{ count($selected) }}</span>
                <select wire:model="bulkService" aria-label="Atanacak hizmet" class="{{ $input }} ml-auto py-1 text-xs">
                    <option value="">Hizmet…</option>
                    @foreach ($services as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
                <button type="button" wire:click="assignSelected" @disabled($selected === []) class="{{ $ghost }}">Hizmete ata</button>
                <button type="button" wire:click="hideSelected" wire:confirm="Seçili sorgular gizlensin mi?" @disabled($selected === []) class="{{ $ghost }}">Sil</button>
                <button type="button" wire:click="proposeRules" @disabled($selected === []) class="{{ $btn }}">AI ile kural üret</button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="text-gray-500"><tr>
                        <th class="w-6 py-1"></th><th>Sorgu</th><th>Hizmet</th><th>Küme</th>
                        <th class="text-right">Gösterim</th><th class="text-right">Tıklama</th><th class="text-right">Ads maliyet</th><th>Kaynaklar</th><th>Durum</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($queries as $query)
                            <tr wire:key="q-{{ $query->id }}">
                                <td class="py-1"><input type="checkbox" wire:model.live="selected" value="{{ $query->id }}" aria-label="Seç"></td>
                                <td class="font-medium">{{ $query->text }}</td>
                                <td>{{ $query->service?->primaryName?->raw_label ?? '—' }}@if ($query->locked)<span class="ml-1 text-gray-400">· elle</span>@endif</td>
                                <td>{{ $query->clusterLink?->cluster?->name ?? '—' }}</td>
                                @if ($query->is_suggested)
                                    <td class="text-right text-gray-400">—</td><td class="text-right text-gray-400">—</td><td class="text-right text-gray-400">—</td><td>—</td>
                                @else
                                    <td class="text-right tabular-nums">{{ $num($query->impressions) }}</td>
                                    <td class="text-right tabular-nums">{{ $num($query->clicks) }}</td>
                                    <td class="text-right tabular-nums">{{ $query->ads_cost !== null ? number_format($query->ads_cost, 2, ',', '.') : '—' }}</td>
                                    <td>{{ collect(explode(',', (string) $query->sources))->filter()->map(fn ($s) => \App\Livewire\Operator\Library\QueriesPage::SOURCE_LABELS[$s] ?? $s)->implode(' · ') ?: '—' }}</td>
                                @endif
                                <td>
                                    @if ($query->is_suggested)<span class="{{ $chip }} bg-purple-50 text-purple-700 dark:bg-purple-500/10">önerilen</span>
                                    @elseif ($query->service_id === null)<span class="{{ $chip }} bg-amber-50 text-amber-700 dark:bg-amber-500/10">atanmamış</span>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="py-3 text-gray-500">Sorgu yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-2">{{ $queries->links() }}</div>
        </section>
    @endif

    {{-- Kümeler --}}
    @if ($tab === 'clusters')
        <section class="{{ $card }}" data-section="clusters">
            @if ($clusters === null)
                <p class="text-gray-500">Hizmet seçin.</p>
            @else
                <table class="w-full text-left text-xs">
                    <thead class="text-gray-500"><tr><th class="py-1">Küme</th><th>Ana sorgu</th><th>Niyet</th><th>Sayfa tipi</th><th class="text-right">Sorgu</th><th></th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($clusters as $row)
                            <tr wire:key="c-{{ $row->id }}">
                                <td class="py-1 font-medium">
                                    <button type="button" wire:click="openCluster({{ $row->id }})" class="text-left hover:underline">{{ $row->name }}</button>
                                    @if ($row->approved)<span class="{{ $chip }} ml-1 bg-success-50 text-success-700 dark:bg-success-500/10">onaylı</span>@endif
                                    @if ($row->locked)<span class="{{ $chip }} ml-1 bg-gray-100 text-gray-600 dark:bg-gray-800">kilitli</span>@endif
                                </td>
                                <td>{{ $row->mainQuery?->text ?? '—' }}</td>
                                <td>{{ \App\Models\Cluster::INTENT_LABELS[$row->intent] ?? $row->intent }}</td>
                                <td>{{ \App\Models\Cluster::PAGE_TYPE_LABELS[$row->page_type] ?? $row->page_type }}</td>
                                <td class="text-right tabular-nums">{{ $row->cluster_queries_count }}</td>
                                <td class="text-right"><button type="button" wire:click="openCluster({{ $row->id }})" class="{{ $ghost }}">Aç</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-3 text-gray-500">Küme yok · "AI ile kümele".</td></tr>
                        @endforelse
                    </tbody>
                </table>
            @endif
        </section>

        @if ($openCluster)
            <aside class="fixed inset-y-0 right-0 z-40 w-full max-w-xl overflow-y-auto bg-white p-4 shadow-xl ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-cluster-drawer role="dialog" aria-label="Küme">
                <div class="flex items-center gap-2">
                    <input type="text" wire:model="clusterName" aria-label="Küme adı" class="{{ $input }} flex-1">
                    <button type="button" wire:click="renameCluster" class="{{ $ghost }}">Kaydet</button>
                    <button type="button" wire:click="closeCluster" aria-label="Kapat" class="px-2 text-lg">×</button>
                </div>
                @error('clusterName')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                <p class="mt-2 text-xs text-gray-500">
                    {{ \App\Models\Cluster::INTENT_LABELS[$openCluster->intent] ?? $openCluster->intent }} · {{ \App\Models\Cluster::PAGE_TYPE_LABELS[$openCluster->page_type] ?? $openCluster->page_type }}
                    · ana sorgu: {{ $openCluster->mainQuery?->text ?? '—' }}
                </p>
                @if ($openCluster->reasoning)<p class="mt-1 text-xs text-gray-600 dark:text-gray-400">{{ $openCluster->reasoning }}</p>@endif
                @if ($openCluster->subtopics)<p class="mt-1 text-xs"><span class="text-gray-500">Alt konular:</span> {{ implode(' · ', $openCluster->subtopics) }}</p>@endif
                <div class="mt-3 flex flex-wrap gap-2">
                    @unless ($openCluster->approved)<button type="button" wire:click="approveCluster" class="{{ $btn }}">Onayla</button>@endunless
                    <button type="button" wire:click="deleteCluster" wire:confirm="Küme silinsin mi?" class="{{ $ghost }}">Sil</button>
                </div>

                <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($openCluster->clusterQueries->sortByDesc(fn ($link) => $link->searchQuery?->impressions ?? 0) as $link)
                        <li wire:key="cq-{{ $link->id }}" class="flex items-center gap-2 py-1 text-xs">
                            <input type="checkbox" wire:model="selectedClusterQueries" value="{{ $link->query_id }}" aria-label="Seç">
                            <span class="flex-1">{{ $link->searchQuery?->text }}@if ($link->query_id === $openCluster->main_query_id)<span class="ml-1 text-gray-500">ana</span>@endif</span>
                            @if ($link->is_suggested)<span class="{{ $chip }} bg-purple-50 text-purple-700 dark:bg-purple-500/10">önerilen</span>
                            @else<span class="tabular-nums text-gray-500">{{ $num($link->searchQuery?->impressions ?? 0) }}</span>@endif
                        </li>
                    @endforeach
                </ul>
                @error('selectedClusterQueries')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror

                <div class="mt-3 space-y-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                    <div class="flex flex-wrap items-center gap-2">
                        <select wire:model="moveTarget" aria-label="Hedef küme" class="{{ $input }} py-1 text-xs">
                            <option value="">Hedef küme…</option>
                            @foreach ($clusterOptions as $id => $name)@if ($id !== $openCluster->id)<option value="{{ $id }}">{{ $name }}</option>@endif @endforeach
                        </select>
                        <button type="button" wire:click="moveQueries" class="{{ $ghost }}">Seçilenleri taşı</button>
                        @error('moveTarget')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <input type="text" wire:model="splitName" placeholder="Yeni küme adı" aria-label="Yeni küme adı" class="{{ $input }} py-1 text-xs">
                        <button type="button" wire:click="splitCluster" class="{{ $ghost }}">Seçilenlerle ayır</button>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <select wire:model="mergeIds" multiple aria-label="Birleştirilecek kümeler" class="{{ $input }} py-1 text-xs">
                            @foreach ($clusterOptions as $id => $name)@if ($id !== $openCluster->id)<option value="{{ $id }}">{{ $name }}</option>@endif @endforeach
                        </select>
                        <button type="button" wire:click="mergeClusters" class="{{ $ghost }}">Bu kümeye birleştir</button>
                    </div>
                </div>
            </aside>
        @endif
    @endif

    {{-- Filtre sepeti --}}
    @if ($tab === 'filters')
        <section class="{{ $card }}" data-section="filters">
            <div class="flex flex-wrap items-center gap-2">
                <input type="text" wire:model="termText" wire:keydown.enter="addTerm" placeholder="Kelime / ifade" aria-label="Terim" class="{{ $input }} w-60">
                <select wire:model="termSector" aria-label="Kapsam" class="{{ $input }}">
                    <option value="">Genel</option>
                    @foreach ($sectors as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
                <button type="button" wire:click="addTerm" class="{{ $btn }}">Ekle</button>
                @error('termText')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror
                <span class="ml-auto text-xs text-gray-500">{{ $num($terms->total()) }} terim</span>
            </div>
            <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($terms as $term)
                    <li wire:key="t-{{ $term->id }}" class="flex items-center gap-2 py-1.5">
                        <span class="flex-1 font-medium">{{ $term->term }}</span>
                        <span class="text-xs text-gray-500">{{ $term->sector?->name ?? 'Genel' }}@if ($term->source === 'ai') · AI @endif</span>
                        <button type="button" wire:click="deleteTerm({{ $term->id }})" wire:confirm="Terim silinsin mi?" class="{{ $ghost }}">Sil</button>
                    </li>
                @empty
                    <li class="py-2 text-gray-500">Sepet boş.</li>
                @endforelse
            </ul>
            <div class="mt-2">{{ $terms->links() }}</div>
        </section>
    @endif

    {{-- Eşleme kelimeleri --}}
    @if ($tab === 'keywords')
        <section class="{{ $card }}" data-section="keywords">
            @if ($keywordServices === null)
                <p class="text-gray-500">Sektör seçin.</p>
            @else
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($keywordServices as $item)
                        <li wire:key="ks-{{ $item->id }}" class="py-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="w-48 font-medium">{{ $item->primaryName->raw_label }}</span>
                                @foreach ($item->matchingKeywords as $keyword)
                                    <span wire:key="kw-{{ $keyword->id }}" class="{{ $chip }} inline-flex items-center gap-1 bg-gray-100 dark:bg-gray-800">{{ $keyword->label }}<button type="button" wire:click="deleteKeyword({{ $keyword->id }})" aria-label="Sil" class="text-gray-500">×</button></span>
                                @endforeach
                                <input type="text" wire:model="newKeyword.{{ $item->id }}" wire:keydown.enter="addKeyword({{ $item->id }})" placeholder="Kelime" aria-label="Yeni kelime" class="{{ $input }} w-40 py-1 text-xs">
                                <button type="button" wire:click="addKeyword({{ $item->id }})" class="{{ $ghost }}">Ekle</button>
                            </div>
                            @error('newKeyword.'.$item->id)<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                        </li>
                    @empty
                        <li class="py-2 text-gray-500">Bu sektörde hizmet yok.</li>
                    @endforelse
                </ul>
            @endif
        </section>
    @endif

    {{-- AI ile kural üret --}}
    @if ($rulesOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" data-rules-modal role="dialog" aria-label="AI kural önerisi">
            <div class="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-4 dark:bg-gray-900">
                <div class="flex items-center justify-between">
                    <h2 class="font-semibold">AI kural önerisi</h2>
                    <button type="button" wire:click="closeRules" aria-label="Kapat" class="px-2 text-lg">×</button>
                </div>
                @php $status = $proposal['status'] ?? 'running'; @endphp
                @if ($status === 'running')
                    <p class="mt-3 text-gray-500">Hazırlanıyor…</p>
                @elseif ($status !== 'ready')
                    <p class="mt-3 text-rose-600">{{ ['no_provider' => 'AI bağlı değil.', 'no_queries' => 'Sorgu bulunamadı.'][$status] ?? 'Öneri alınamadı.' }}</p>
                @else
                    <h3 class="mt-3 text-xs font-semibold uppercase text-gray-500">Filtre sepeti · {{ count($proposal['terms']) }}</h3>
                    <ul class="mt-1 space-y-1">
                        @forelse ($proposal['terms'] as $i => $row)
                            <li class="flex items-start gap-2 text-xs"><input type="checkbox" wire:model="pickTerms.{{ $i }}" aria-label="Seç">
                                <span><span class="font-medium">{{ $row['term'] }}</span> <span class="text-gray-500">· {{ $row['sector'] ?? 'Genel' }} · {{ $row['reason'] }}</span></span></li>
                        @empty
                            <li class="text-xs text-gray-500">Öneri yok.</li>
                        @endforelse
                    </ul>
                    <h3 class="mt-3 text-xs font-semibold uppercase text-gray-500">Eşleme kelimeleri · {{ count($proposal['keywords']) }}</h3>
                    <ul class="mt-1 space-y-1">
                        @forelse ($proposal['keywords'] as $i => $row)
                            <li class="flex items-start gap-2 text-xs"><input type="checkbox" wire:model="pickKeywords.{{ $i }}" aria-label="Seç">
                                <span><span class="font-medium">{{ $row['keyword'] }}</span> → {{ $row['service'] }} <span class="text-gray-500">· {{ $row['reason'] }}</span></span></li>
                        @empty
                            <li class="text-xs text-gray-500">Öneri yok.</li>
                        @endforelse
                    </ul>
                    <div class="mt-4 flex justify-end gap-2">
                        <button type="button" wire:click="closeRules" class="{{ $ghost }}">Vazgeç</button>
                        <button type="button" wire:click="approveRules" class="{{ $btn }}">Seçilenleri kaydet ve yeniden işle</button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
