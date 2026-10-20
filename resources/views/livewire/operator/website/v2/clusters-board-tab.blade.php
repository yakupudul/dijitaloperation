@php
    $input = 'h-9 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white';
    $pill = fn (bool $on) => $on ? 'h-8 rounded-md bg-gray-100 px-3 text-xs font-medium text-gray-900 dark:bg-white/10 dark:text-white' : 'h-8 rounded-md px-3 text-xs font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white';
    $btn = 'h-8 shrink-0 rounded-lg bg-brand-500 px-3 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50';
    $link = 'link-neutral shrink-0 text-xs';
    $ghost = 'h-8 shrink-0 rounded-lg px-3 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
    $num = fn ($v) => $v === null ? '—' : number_format((float) $v, 0, ',', '.');
    $dec = fn ($v) => $v === null ? '—' : number_format((float) $v, 1, ',', '.');
    $typeLabels = \App\Livewire\Operator\Website\V2\ClustersBoardTab::TYPE_LABELS;
    $runningRows = collect($statuses)->filter(fn ($line) => $line === 'çalışıyor…')->keys()->map(fn ($k) => \Illuminate\Support\Str::before($k, ':'))->flip()->all();
    $typeTone = [];
@endphp
<div class="space-y-10 text-sm" data-clusters-board @if ($polling) wire:poll.5s @endif>
    @if ($message !== '')
        <p role="status" class="flex items-center justify-between rounded-lg bg-gray-50 p-2 text-xs text-gray-800 dark:bg-gray-950 dark:text-gray-200">
            <span>{{ $message }}</span><button type="button" wire:click="$set('message', '')" aria-label="Kapat" class="px-2">×</button>
        </p>
    @endif

    <section class="flex flex-wrap items-end gap-6">
        <div class="min-w-0 flex-1">
            <h2 class="section-title" title="İnsanların aradığı her konu bir küme. Önce ana hizmetler; her hizmette önce ana küme, sonra alt kümeler. Her kümede önce olmazsa olmaz içerik.">Kümeler</h2>
            <p class="sr-only">İnsanların aradığı her konu bir küme. Önce ana hizmetler; her hizmette önce ana küme, sonra alt kümeler. Her kümede önce olmazsa olmaz içerik.</p>
        </div>
        <dl class="flex gap-8" data-cluster-totals>
            <div><dt class="text-xs text-gray-500">Küme</dt><dd class="text-xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $num($totals['all']) }}</dd></div>
            <div><dt class="text-xs text-gray-500">Eşlendi</dt><dd class="text-xl font-semibold tabular-nums text-success-700 dark:text-success-400">{{ $num($totals['matched']) }}</dd></div>
            <div><dt class="text-xs text-gray-500">Eşlenmedi</dt><dd class="text-xl font-semibold tabular-nums text-error-700 dark:text-error-400">{{ $num($totals['unmatched']) }}</dd></div>
        </dl>
    </section>

    <section class="flex flex-wrap items-center gap-3">
        <label class="flex items-center gap-2"><span class="text-xs text-gray-500">Hizmet</span>
            <select wire:model.live="service" class="{{ $input }}" aria-label="Hizmet">
                <option value="">Tüm hizmetler</option>
                @foreach ($serviceOptions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
        </label>
        <div class="flex gap-1.5" role="group" aria-label="Durum">
            <button type="button" wire:click="$set('match', '')" class="{{ $pill($match === '') }}">Tümü</button>
            @foreach (\App\Livewire\Operator\Website\V2\ClustersBoardTab::MATCH as $key => $label)
                <button type="button" wire:click="$set('match', '{{ $key }}')" class="{{ $pill($match === $key) }}" data-match-filter="{{ $key }}">{{ $label }}</button>
            @endforeach
        </div>
        <div class="flex gap-1.5" role="group" aria-label="İçerik türü">
            <button type="button" wire:click="$set('type', '')" class="{{ $pill($type === '') }}">Tüm türler</button>
            <button type="button" wire:click="$set('type', 'service')" class="{{ $pill($type === 'service') }}">Hizmet</button>
            <button type="button" wire:click="$set('type', 'guide')" class="{{ $pill($type === 'guide') }}">Blog</button>
        </div>
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Küme veya sorgu ara" aria-label="Ara" class="{{ $input }} w-52 sm:ml-auto">
        <div class="flex w-full flex-wrap items-center gap-2 text-xs text-gray-500">
            <button type="button" wire:click="matchAll" class="{{ $btn }}" data-match-all>Eşleştir</button>
            <x-operator.ai-prompt-info operation="site.cluster_match" />
            <span class="sr-only">Kümeleri sitenin sayfalarıyla eşleştirir (sisteme çekilmiş sayfa metinleri; AI yalnız tıklayınca).</span>
            @if ($auditStatus)<span class="font-medium text-gray-700 dark:text-gray-300">Eşleştir: {{ $auditStatus }}</span>@endif
            @if ($overlapCount > 0)
                <button type="button" wire:click="$parent.setTab('fikirler')" class="font-medium text-warning-700 hover:underline dark:text-warning-300" data-overlap-link title="Ayrıntılı listede">{{ $overlapCount }} çakışan sayfa</button>
            @endif
        </div>
    </section>

    @if ($pendingClusters->isNotEmpty())
        <details class="text-xs" data-pending-clusters>
            <summary class="cursor-pointer section-title"><span class="mr-2 inline-block h-1.5 w-1.5 rounded-full bg-warning-500 align-middle" aria-hidden="true"></span>Onay bekleyen {{ $pendingClusters->count() }} küme</summary>
            <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                <p class="text-gray-500">Markanın hizmetlerinde. Onaylanan küme bu panoya iner (kümeler ortak: aynı hizmetteki diğer markalara da iner).</p>
                <button type="button" wire:click="approveAllClusters" wire:confirm="{{ $pendingClusters->count() }} küme onaylansın mı?" class="{{ $btn }}" data-approve-all-clusters>Tümünü onayla</button>
            </div>
            <ul class="mt-2 divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                @foreach ($pendingClusters as $pending)
                    <li class="flex flex-wrap items-center gap-2 py-2.5" wire:key="pending-cluster-{{ $pending->id }}">
                        <span class="font-medium text-gray-900 dark:text-white">{{ $pending->name }}</span>
                        <span class="text-gray-600 dark:text-gray-300">{{ $pending->service?->primaryName?->raw_label ?? '—' }}, {{ $pending->cluster_queries_count }} sorgu</span>
                        <button type="button" wire:click="approveCluster({{ $pending->id }})" class="{{ $ghost }} ml-auto" data-approve-cluster="{{ $pending->id }}">Onayla</button>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    @forelse ($sections as $section)
        <section class="space-y-3" wire:key="service-{{ $section['id'] }}" data-service-section="{{ $section['id'] }}" data-open="{{ $section['open'] ? '1' : '0' }}">
            <header class="flex flex-wrap items-center gap-3 border-t border-gray-200 pt-4 dark:border-gray-800">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <h3 class="section-title">{{ $section['name'] }}</h3>
                        @if ($section['main'])<span class="text-xs text-gray-500">Ana hizmet</span>@endif
                    </div>
                    <p class="mt-0.5 text-xs text-gray-500">{{ count($section['cards']) }} küme, {{ $section['matched'] }} eşlendi</p>
                </div>
                @if ($section['page'])
                    <span class="ml-auto text-xs text-gray-600 dark:text-gray-400">Hizmet sayfası: <a href="{{ $section['page']->url }}" target="_blank" rel="noopener" class="link-neutral font-medium">{{ $section['page']->title ?: ($section['page']->path ?: '/') }}</a></span>
                @endif
                @if (! $section['open'])
                    <button type="button" wire:click="openSection({{ $section['id'] }})" wire:loading.attr="disabled" wire:target="openSection({{ $section['id'] }})" @class([$ghost, 'ml-auto' => ! $section['page']]) data-open-section="{{ $section['id'] }}">
                        <span wire:loading.remove wire:target="openSection({{ $section['id'] }})">Göster</span><span wire:loading wire:target="openSection({{ $section['id'] }})">Yükleniyor…</span>
                    </button>
                @elseif (! $loop->first && in_array($section['id'], $opened, true))
                    <button type="button" wire:click="closeSection({{ $section['id'] }})" @class([$ghost, 'ml-auto' => ! $section['page']]) data-close-section="{{ $section['id'] }}">Gizle</button>
                @endif
            </header>

            @if ($section['open'])
            <div class="grid grid-cols-1 gap-x-8 gap-y-10 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($section['shown'] as $index => $card)
                    @php
                        $cluster = $card['cluster'];
                        $ideaStatus = $ideaStatuses[$cluster->id] ?? null;
                        $level = $section['id'] !== 0 && $index === 0 ? 'Ana küme' : 'Alt küme';
                        // The card's one main action: a page for every idea that has none on the site (and that was looked for).
                        $producible = collect($card['ideas'])->filter(fn (array $idea): bool => $idea['model'] !== null && $idea['state'] === 'no_page'
                            && ($idea['model']->rediscovered_at !== null || ($idea['model']->audited_at ?? null) !== null)
                            && ! isset($runningRows[$idea['kind'].'-'.$idea['model']->id])
                            && ! isset($suggestions[$idea['kind'].'-'.$idea['model']->id.':content']))
                            ->map(fn (array $idea): array => ['kind' => (string) $idea['kind'], 'id' => (int) $idea['model']->id])->values()->all();
                    @endphp
                    <article wire:key="cluster-card-{{ $cluster->id }}" data-cluster-card="{{ $cluster->id }}" data-matched="{{ $card['matched'] ? '1' : '0' }}"
                             x-data="{ tab: 'ideas' }" class="flex min-w-0 flex-col border-t border-gray-200 dark:border-gray-800">
                        <div class="flex items-start gap-3 pb-3 pt-3">
                            <div class="min-w-0 flex-1">
                                <span @class(['text-xs', 'font-medium text-gray-900 dark:text-white' => $level === 'Ana küme', 'text-gray-500' => $level !== 'Ana küme'])>{{ $level }}</span>
                                <h4 class="mt-0.5 text-[15px] font-semibold leading-snug text-gray-900 dark:text-white">{{ $cluster->name }}</h4>
                            </div>
                            <x-ta.pill :tone="$card['matched'] ? 'ok' : 'critical'" class="shrink-0" data-match-badge>{{ $card['matched'] ? 'Eşlendi' : 'Eşlenmedi' }}</x-ta.pill>
                        </div>

                        <dl class="grid grid-cols-3 gap-4 pb-3">
                            <div><dt class="text-xs text-gray-500">Sektör talebi</dt><dd class="text-sm font-semibold tabular-nums">{{ $num($card['demand']) }}</dd></div>
                            <div><dt class="text-xs text-gray-500">Bizim gösterim</dt><dd class="text-sm font-semibold tabular-nums">{{ $num($card['impressions']) }}</dd></div>
                            <div><dt class="text-xs text-gray-500">Sıra</dt><dd class="text-sm font-semibold tabular-nums">{{ $dec($card['position']) }}</dd></div>
                        </dl>

                        @if ($card['warning'])
                            <p class="mb-3 text-xs text-warning-800 dark:text-warning-300">{{ \Illuminate\Support\Str::limit($card['warning'], 160) }}</p>
                        @endif

                        <div role="tablist" class="flex gap-5 border-b border-gray-200 dark:border-gray-800">
                            <button type="button" role="tab" @click="tab = 'ideas'" :aria-selected="(tab === 'ideas').toString()" :class="tab === 'ideas' ? 'border-gray-900 font-semibold text-gray-900 dark:border-white dark:text-white' : 'border-transparent text-gray-500'" class="-mb-px h-9 border-b-2 text-xs">İçerik fikirleri <span class="opacity-70">{{ count($card['ideas']) }}</span></button>
                            <button type="button" role="tab" @click="tab = 'queries'" :aria-selected="(tab === 'queries').toString()" :class="tab === 'queries' ? 'border-gray-900 font-semibold text-gray-900 dark:border-white dark:text-white' : 'border-transparent text-gray-500'" class="-mb-px h-9 border-b-2 text-xs" data-card-queries-tab>Sorgular <span class="opacity-70">{{ count($card['queries']) }}</span></button>
                        </div>

                        <div class="max-h-96 flex-1 overflow-y-auto">
                            <ul x-show="tab === 'ideas'" class="divide-y divide-gray-200 dark:divide-gray-800" data-card-ideas>
                                @foreach ($card['ideas'] as $idea)
                                    @php
                                        $model = $idea['model'];
                                        $key = $model !== null ? $idea['kind'].'-'.$model->id : 'idea-'.$idea['idea']->id;
                                        [$actKind, $actId] = $model !== null ? [$idea['kind'], (int) $model->id] : ['idea', (int) $idea['idea']->id];
                                        $page = $model?->page;
                                        $audited = $model !== null && ($model->rediscovered_at !== null || ($model->audited_at ?? null) !== null);
                                        $improveSuggestion = $model !== null ? ($suggestions[$idea['kind'].'-'.$model->id.':missing_topic'] ?? null) : null;
                                        $contentSuggestion = $model !== null ? ($suggestions[$idea['kind'].'-'.$model->id.':content'] ?? null) : null;
                                        $running = isset($runningRows[$key]);
                                    @endphp
                                    <li class="py-2.5" wire:key="card-idea-{{ $key }}" data-card-idea="{{ $key }}" data-state="{{ $idea['state'] }}">
                                        <div class="mb-1 flex items-center gap-1.5">
                                            <span class="text-xs text-gray-500">{{ $typeLabels[$idea['type']] ?? $idea['type'] }}</span>
                                            @if ($idea['must'])<span class="text-xs font-medium text-gray-900 dark:text-white">Olmazsa olmaz</span>@endif
                                        </div>
                                        <p class="font-medium leading-snug text-gray-900 dark:text-white">{{ $idea['title'] }}</p>
                                        <div class="mt-2 flex items-center justify-between gap-2 text-xs">
                                            @if ($page)
                                                <span class="min-w-0 truncate text-gray-600 dark:text-gray-400">Sitede: <a href="{{ $page->url }}" target="_blank" rel="noopener" class="link-neutral font-medium">{{ $page->title ?: ($page->path ?: '/') }}</a></span>
                                            @elseif ($idea['state'] === 'no_page')
                                                <span class="text-error-700 dark:text-error-400">Sitede yok</span>
                                            @else
                                                <span class="text-gray-500">Henüz aranmadı</span>
                                            @endif

                                            @if ($running)
                                                <span class="shrink-0 text-gray-500">çalışıyor…</span>
                                            @elseif ($improveSuggestion !== null && data_get($improveSuggestion->action, 'proposal') && $improveSuggestion->applied_at === null)
                                                <a href="{{ route('operator.website', ['assetId' => $assetId, 'tab' => 'yapilacaklar', 'oneri' => $improveSuggestion->id]) }}" wire:navigate class="{{ $link }}" data-preview>Önizle ve güncelle</a>
                                            @elseif ($contentSuggestion !== null)
                                                <button type="button" wire:click="$parent.setTab('icerik')" class="{{ $ghost }}" data-content-link>Taslağa git</button>
                                            @elseif ($idea['state'] === 'improve' && $page?->wp_post_id !== null)
                                                <button type="button" wire:click="improve('{{ $idea['kind'] }}', {{ $model->id }})" class="{{ $ghost }}" data-improve>Geliştir</button>
                                            @elseif ($idea['state'] === 'no_page' && $audited)
                                                <button type="button" wire:click="produce('{{ $idea['kind'] }}', {{ $model->id }})" class="{{ $link }}" data-produce>Yalnız bunu oluştur</button>
                                            @elseif ($idea['state'] === 'technical')
                                                <button type="button" wire:click="$parent.setTab('teknik')" class="{{ $ghost }}">Teknik sorun</button>
                                            @elseif ($idea['state'] === 'sufficient')
                                                <span class="shrink-0 text-success-700 dark:text-success-400">Karşılıyor</span>
                                            @elseif ($idea['state'] !== 'excluded')
                                                <button type="button" wire:click="rediscover('{{ $actKind }}', {{ $actId }})" class="{{ $ghost }}" data-rediscover>Sitede ara</button>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                            <div x-show="tab === 'queries'" x-cloak data-card-query-list>
                                <div class="grid grid-cols-[minmax(0,1fr)_4rem_4rem_3rem] gap-2 py-2 text-xs text-gray-500"><span>Sorgu</span><span class="text-right">Talep</span><span class="text-right">Gösterim</span><span class="text-right">Sıra</span></div>
                                @forelse ($card['queries'] as $query)
                                    <div class="grid grid-cols-[minmax(0,1fr)_4rem_4rem_3rem] gap-2 border-t border-gray-200 py-2 text-xs dark:border-gray-800">
                                        <span class="min-w-0 truncate" title="{{ $query['text'] }}">{{ $query['text'] }}</span>
                                        <span class="text-right tabular-nums text-gray-500">{{ $num($query['demand']) }}</span>
                                        <span class="text-right tabular-nums">{{ $num($query['impressions']) }}</span>
                                        <span class="text-right tabular-nums">{{ $dec($query['position']) }}</span>
                                    </div>
                                @empty
                                    <p class="py-3 text-xs text-gray-500">Bu kümede sorgu yok.</p>
                                @endforelse
                            </div>
                        </div>

                        <footer class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 py-2.5 text-xs dark:border-gray-800">
                            @if ($producible !== [])
                                <button type="button" wire:click="produceCard(@js($producible))" wire:confirm="{{ count($producible) }} fikir için sayfa yazılsın mı? Taslak olarak İçerik sekmesine düşer; siteye sen gönderirsin."
                                        class="{{ $ghost }} w-full sm:w-auto" data-produce-card="{{ $cluster->id }}">Oluştur ({{ count($producible) }})</button>
                            @endif
                            @if (($ideaStatus['status'] ?? null) === 'running')
                                <span class="text-gray-500">Fikirler üretiliyor…</span>
                            @else
                                <button type="button" wire:click="generateIdeas({{ $cluster->id }})" class="link-neutral h-8" data-generate-ideas="{{ $cluster->id }}">+ Yeni fikir üret</button>
                            @endif
                            <span class="text-gray-500">
                                @if (($ideaStatus['status'] ?? null) === 'done'){{ $ideaStatus['added'] }} fikir eklendi,
                                @elseif (in_array($ideaStatus['status'] ?? null, ['error', 'no_provider'], true))<span class="text-error-600">Fikir üretilemedi</span>,
                                @endif
                                {{ \App\Models\Cluster::PAGE_TYPE_LABELS[$cluster->page_type] ?? $cluster->page_type }} sayfası
                            </span>
                        </footer>
                    </article>
                @endforeach
            </div>

            @if (count($section['big']) > \App\Livewire\Operator\Website\V2\ClustersBoardTab::VISIBLE)
                <div class="flex justify-center">
                    <button type="button" wire:click="toggleAll({{ $section['id'] }})" class="{{ $ghost }}" data-more-clusters>{{ $section['all'] ? 'Daha az göster' : 'Tüm alt kümeler ('.(count($section['big']) - \App\Livewire\Operator\Website\V2\ClustersBoardTab::VISIBLE).' daha)' }}</button>
                </div>
            @endif
            @if ($section['small'] !== [])
                <details class="text-xs" data-small-clusters="{{ $section['id'] }}">
                    <summary class="cursor-pointer font-medium text-gray-600 dark:text-gray-300">Küçük kümeler ({{ count($section['small']) }}) <span class="font-normal text-gray-500">sektör talebi {{ \App\Livewire\Operator\Website\V2\ClustersBoardTab::SMALL_DEMAND }}’un altında, bizde gösterim yok</span></summary>
                    <ul class="mt-2 divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                        @foreach ($section['small'] as $card)
                            <li class="flex flex-wrap items-center gap-2 py-2.5" wire:key="small-cluster-{{ $card['cluster']->id }}" data-small-cluster="{{ $card['cluster']->id }}">
                                <span class="min-w-0 flex-1 truncate text-gray-800 dark:text-gray-200">{{ $card['cluster']->name }}</span>
                                <span class="tabular-nums text-gray-500">Sektör talebi {{ $num($card['demand']) }}, bizim gösterim 0</span>
                                <x-ta.pill :tone="$card['matched'] ? 'ok' : 'info'">{{ $card['matched'] ? 'Eşlendi' : 'Eşlenmedi' }}</x-ta.pill>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
            @endif
        </section>
    @empty
        <div class="py-6 text-center text-xs text-gray-500">
            <x-operator.cluster-readiness :asset-id="$this->assetId" what="Kümeler" />
            <p>Bu filtrede küme yok.</p>
            @if ($service !== '' || $match !== '' || $type !== '' || trim($search) !== '')
                <button type="button" wire:click="clearFilters" class="{{ $ghost }} mt-3" data-empty-action>Filtreleri temizle</button>
            @endif
        </div>
    @endforelse
</div>
