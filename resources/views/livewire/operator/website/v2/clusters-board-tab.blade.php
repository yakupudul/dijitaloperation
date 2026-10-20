@php
    $select = 'h-8 rounded-lg border border-gray-200 bg-white py-0 pl-2.5 pr-8 text-[13px] text-gray-700 shadow-card focus:border-gray-300 focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $input = 'h-8 rounded-lg border border-gray-200 bg-white px-2.5 text-[13px] text-gray-700 shadow-card focus:border-gray-300 focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
    $pill = fn (bool $on) => $on ? 'h-7 rounded-md bg-gray-100 px-2.5 text-[13px] font-medium text-gray-900 dark:bg-white/10 dark:text-white' : 'h-7 rounded-md px-2.5 text-[13px] text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white';
    $segment = 'inline-flex shrink-0 rounded-lg border border-gray-200 bg-white p-0.5 shadow-card dark:border-gray-700 dark:bg-gray-900';
    $btn = 'btn';
    $link = 'link-neutral shrink-0 text-xs';
    $ghost = 'btn h-7! px-2.5! text-xs!';
    $num = fn ($v) => $v === null ? '—' : number_format((float) $v, 0, ',', '.');
    $dec = fn ($v) => $v === null ? '—' : number_format((float) $v, 1, ',', '.');
    $typeLabels = \App\Livewire\Operator\Website\V2\ClustersBoardTab::TYPE_LABELS;
    $runningRows = collect($statuses)->filter(fn ($line) => $line === 'çalışıyor…')->keys()->map(fn ($k) => \Illuminate\Support\Str::before($k, ':'))->flip()->all();
    $typeTone = [];
@endphp
<div class="min-w-0 space-y-10 text-sm" data-clusters-board @if ($polling) wire:poll.5s @endif>
    @if ($message !== '')
        <p role="status" class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-800 shadow-card dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-200">
            <span>{{ $message }}</span><button type="button" wire:click="$set('message', '')" aria-label="Kapat" class="btn-quiet h-7! px-2!">×</button>
        </p>
    @endif

    {{-- 1. The answer: how many searched topics the site covers, and the one action that checks them against the pages. --}}
    <section class="min-w-0">
        <div class="section-head justify-between">
            <div class="flex min-w-0 flex-[1_1_20rem] flex-wrap items-baseline gap-x-3 gap-y-0.5">
                <h2 class="section-title">Kümeler</h2>
                <span class="text-[13px] text-gray-500 dark:text-gray-400">İnsanların aradığı her konu bir küme; eşlenmeyen kümenin sitede sayfası yok.</span>
            </div>
            <div class="flex w-full flex-wrap items-center gap-x-3 gap-y-1.5 sm:w-auto">
                @if ($auditStatus)<span class="text-xs text-gray-500">Eşleştir: {{ $auditStatus }}</span>@endif
                <span class="flex w-full items-center gap-1.5 sm:w-auto">
                    <button type="button" wire:click="matchAll" class="btn-primary flex-1 sm:flex-none" data-match-all>Eşleştir</button>
                    <x-operator.ai-prompt-info operation="site.cluster_match" />
                </span>
            </div>
        </div>
        <p class="sr-only">İnsanların aradığı her konu bir küme. Önce ana hizmetler; her hizmette önce ana küme, sonra alt kümeler. Her kümede önce olmazsa olmaz içerik. «Eşleştir» kümeleri sitenin sayfalarıyla eşleştirir (sisteme çekilmiş sayfa metinleri; AI yalnız tıklayınca).</p>
        <div class="metric-strip grid-cols-3" data-cluster-totals>
            <x-ta.metric-card label="Küme" :value="$num($totals['all'])" />
            <x-ta.metric-card label="Eşlendi" :value="$num($totals['matched'])" hint="sayfası var" />
            <x-ta.metric-card label="Eşlenmedi" :value="$num($totals['unmatched'])" hint="sayfası yok" />
        </div>
        @if ($overlapCount > 0)
            <p class="mt-2.5 text-[13px] text-gray-600 dark:text-gray-300">
                <span class="mr-1.5 inline-block h-1.5 w-1.5 rounded-full bg-warning-500 align-middle" aria-hidden="true"></span>Aynı konuyu birden çok sayfa anlatıyor.
                <button type="button" wire:click="$parent.setTab('fikirler')" class="link-primary" data-overlap-link title="Ayrıntılı listede">{{ $overlapCount }} çakışan sayfa</button>
            </p>
        @endif
    </section>

    {{-- 2. Filters. --}}
    <section class="-mt-4 flex min-w-0 flex-wrap items-center gap-2">
        <select wire:model.live="service" class="{{ $select }} w-full sm:w-52" aria-label="Hizmet">
            <option value="">Tüm hizmetler</option>
            @foreach ($serviceOptions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
        </select>
        <div class="{{ $segment }}" role="group" aria-label="Durum">
            <button type="button" wire:click="$set('match', '')" class="{{ $pill($match === '') }}">Tümü</button>
            @foreach (\App\Livewire\Operator\Website\V2\ClustersBoardTab::MATCH as $key => $label)
                <button type="button" wire:click="$set('match', '{{ $key }}')" class="{{ $pill($match === $key) }}" data-match-filter="{{ $key }}">{{ $label }}</button>
            @endforeach
        </div>
        <div class="{{ $segment }}" role="group" aria-label="İçerik türü">
            <button type="button" wire:click="$set('type', '')" class="{{ $pill($type === '') }}">Tüm türler</button>
            <button type="button" wire:click="$set('type', 'service')" class="{{ $pill($type === 'service') }}">Hizmet</button>
            <button type="button" wire:click="$set('type', 'guide')" class="{{ $pill($type === 'guide') }}">Blog</button>
        </div>
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Küme veya sorgu ara" aria-label="Ara" class="{{ $input }} w-full sm:ml-auto sm:w-56">
    </section>

    @if ($pendingClusters->isNotEmpty())
        <details class="group card" data-pending-clusters>
            <summary class="flex min-w-0 cursor-pointer list-none flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3.5 hover:bg-gray-50/70 dark:hover:bg-white/[0.02]">
                <svg class="h-4 w-4 shrink-0 text-gray-400 transition group-open:rotate-90" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.17 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
                <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span>
                <span class="text-sm font-medium text-gray-900 dark:text-white">Onay bekleyen {{ $pendingClusters->count() }} küme</span>
                <span class="min-w-0 text-[13px] text-gray-500">Onaylanan küme bu panoya iner; aynı hizmetteki diğer markalara da iner.</span>
            </summary>
            <div class="flex justify-end border-t border-gray-200 px-4 py-2.5 dark:border-gray-800">
                <button type="button" wire:click="approveAllClusters" wire:confirm="{{ $pendingClusters->count() }} küme onaylansın mı?" class="btn w-full sm:w-auto" data-approve-all-clusters>Tümünü onayla</button>
            </div>
            <ul>
                @foreach ($pendingClusters as $pending)
                    <li class="card-row flex-wrap sm:flex-nowrap" wire:key="pending-cluster-{{ $pending->id }}">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $pending->name }}</p>
                            <p class="mt-0.5 text-[13px] text-gray-500">{{ $pending->service?->primaryName?->raw_label ?? '—' }}, {{ $pending->cluster_queries_count }} sorgu</p>
                        </div>
                        <button type="button" wire:click="approveCluster({{ $pending->id }})" class="{{ $ghost }}" data-approve-cluster="{{ $pending->id }}">Onayla</button>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    {{-- 3. One section per service: main cluster first, then the sub-clusters. --}}
    @forelse ($sections as $section)
        <section class="min-w-0" wire:key="service-{{ $section['id'] }}" data-service-section="{{ $section['id'] }}" data-open="{{ $section['open'] ? '1' : '0' }}">
            <header @class(['flex min-w-0 flex-wrap items-center gap-x-4 gap-y-2', 'mb-3' => $section['open'], 'card px-4 py-3.5' => ! $section['open']])>
                <div class="min-w-0 flex-1">
                    <div class="flex min-w-0 flex-wrap items-baseline gap-x-2.5">
                        <h3 class="section-title">{{ $section['name'] }}</h3>
                        @if ($section['main'])<span class="text-xs text-gray-500">Ana hizmet</span>@endif
                        <span class="text-[13px] text-gray-500">{{ count($section['cards']) }} küme, {{ $section['matched'] }} eşlendi</span>
                    </div>
                    @if ($section['page'])
                        <p class="mt-0.5 min-w-0 truncate text-xs text-gray-500">Hizmet sayfası: <a href="{{ $section['page']->url }}" target="_blank" rel="noopener" class="link-neutral">{{ $section['page']->title ?: ($section['page']->path ?: '/') }}</a></p>
                    @endif
                </div>
                @if (! $section['open'])
                    <button type="button" wire:click="openSection({{ $section['id'] }})" wire:loading.attr="disabled" wire:target="openSection({{ $section['id'] }})" class="btn w-full sm:w-auto" data-open-section="{{ $section['id'] }}">
                        <span wire:loading.remove wire:target="openSection({{ $section['id'] }})">Göster</span><span wire:loading wire:target="openSection({{ $section['id'] }})">Yükleniyor…</span>
                    </button>
                @elseif (! $loop->first && in_array($section['id'], $opened, true))
                    <button type="button" wire:click="closeSection({{ $section['id'] }})" class="btn-quiet" data-close-section="{{ $section['id'] }}">Gizle</button>
                @endif
            </header>

            @if ($section['open'])
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
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
                             x-data="{ tab: 'ideas' }" class="card flex min-w-0 flex-col">
                        <div class="flex items-start gap-3 px-4 pt-3.5">
                            <div class="min-w-0 flex-1">
                                <span @class(['text-xs', 'font-medium text-gray-900 dark:text-white' => $level === 'Ana küme', 'text-gray-500' => $level !== 'Ana küme'])>{{ $level }}</span>
                                <h4 class="mt-0.5 break-words text-[15px] font-semibold leading-snug text-gray-900 dark:text-white">{{ $cluster->name }}</h4>
                            </div>
                            <x-ta.pill :tone="$card['matched'] ? 'ok' : 'critical'" class="shrink-0" data-match-badge>{{ $card['matched'] ? 'Eşlendi' : 'Eşlenmedi' }}</x-ta.pill>
                        </div>

                        <dl class="grid grid-cols-3 gap-3 px-4 py-3">
                            <div class="min-w-0"><dt class="truncate text-xs text-gray-500">Sektör talebi</dt><dd class="text-sm font-semibold tabular-nums text-gray-900 dark:text-white">{{ $num($card['demand']) }}</dd></div>
                            <div class="min-w-0"><dt class="truncate text-xs text-gray-500">Bizim gösterim</dt><dd class="text-sm font-semibold tabular-nums text-gray-900 dark:text-white">{{ $num($card['impressions']) }}</dd></div>
                            <div class="min-w-0"><dt class="truncate text-xs text-gray-500">Sıra</dt><dd class="text-sm font-semibold tabular-nums text-gray-900 dark:text-white">{{ $dec($card['position']) }}</dd></div>
                        </dl>

                        @if ($card['warning'])
                            <p class="px-4 pb-3 text-xs text-warning-800 dark:text-warning-300">{{ \Illuminate\Support\Str::limit($card['warning'], 160) }}</p>
                        @endif

                        <div role="tablist" class="flex border-b border-gray-200 px-2 dark:border-gray-800">
                            <button type="button" role="tab" @click="tab = 'ideas'" :aria-selected="(tab === 'ideas').toString()" :class="{ 'tab-active': tab === 'ideas' }" class="tab text-[13px]!">İçerik fikirleri <span class="text-xs text-gray-400">{{ count($card['ideas']) }}</span></button>
                            <button type="button" role="tab" @click="tab = 'queries'" :aria-selected="(tab === 'queries').toString()" :class="{ 'tab-active': tab === 'queries' }" class="tab text-[13px]!" data-card-queries-tab>Sorgular <span class="text-xs text-gray-400">{{ count($card['queries']) }}</span></button>
                        </div>

                        <div class="max-h-96 flex-1 overflow-y-auto">
                            <ul x-show="tab === 'ideas'" data-card-ideas>
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
                                    <li class="border-t border-gray-200 px-4 py-3 first:border-t-0 dark:border-gray-800" wire:key="card-idea-{{ $key }}" data-card-idea="{{ $key }}" data-state="{{ $idea['state'] }}">
                                        <p class="flex items-center gap-1.5 text-xs">
                                            <span class="text-gray-500">{{ $typeLabels[$idea['type']] ?? $idea['type'] }}</span>
                                            @if ($idea['must'])<span class="font-medium text-gray-900 dark:text-white">Olmazsa olmaz</span>@endif
                                        </p>
                                        <p class="mt-0.5 break-words text-sm font-medium leading-snug text-gray-900 dark:text-white">{{ $idea['title'] }}</p>
                                        <div class="mt-1.5 flex min-w-0 items-center justify-between gap-2 text-xs">
                                            @if ($page)
                                                <span class="min-w-0 truncate text-gray-500 dark:text-gray-400">Sitede: <a href="{{ $page->url }}" target="_blank" rel="noopener" class="link-neutral">{{ $page->title ?: ($page->path ?: '/') }}</a></span>
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
                                <div class="grid grid-cols-[minmax(0,1fr)_4rem_4rem_3rem] gap-2 bg-gray-50 px-4 py-2 text-xs text-gray-500 dark:bg-white/[0.02]"><span>Sorgu</span><span class="text-right">Talep</span><span class="text-right">Gösterim</span><span class="text-right">Sıra</span></div>
                                @forelse ($card['queries'] as $query)
                                    <div class="grid grid-cols-[minmax(0,1fr)_4rem_4rem_3rem] gap-2 border-t border-gray-200 px-4 py-2 text-xs dark:border-gray-800">
                                        <span class="min-w-0 truncate text-gray-900 dark:text-white" title="{{ $query['text'] }}">{{ $query['text'] }}</span>
                                        <span class="text-right tabular-nums text-gray-500">{{ $num($query['demand']) }}</span>
                                        <span class="text-right tabular-nums">{{ $num($query['impressions']) }}</span>
                                        <span class="text-right tabular-nums">{{ $dec($query['position']) }}</span>
                                    </div>
                                @empty
                                    <p class="px-4 py-3 text-xs text-gray-500">Bu kümede sorgu yok.</p>
                                @endforelse
                            </div>
                        </div>

                        <footer class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 px-4 py-2.5 text-xs dark:border-gray-800">
                            @if ($producible !== [])
                                <button type="button" wire:click="produceCard(@js($producible))" wire:confirm="{{ count($producible) }} fikir için sayfa yazılsın mı? Taslak olarak İçerik sekmesine düşer; siteye sen gönderirsin."
                                        class="{{ $ghost }} w-full sm:w-auto" data-produce-card="{{ $cluster->id }}">Oluştur ({{ count($producible) }})</button>
                            @endif
                            @if (($ideaStatus['status'] ?? null) === 'running')
                                <span class="text-gray-500">Fikirler üretiliyor…</span>
                            @else
                                <button type="button" wire:click="generateIdeas({{ $cluster->id }})" class="link-neutral" data-generate-ideas="{{ $cluster->id }}">+ Yeni fikir üret</button>
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
                <div class="mt-3 flex justify-center">
                    <button type="button" wire:click="toggleAll({{ $section['id'] }})" class="btn-quiet" data-more-clusters>{{ $section['all'] ? 'Daha az göster' : 'Tüm alt kümeler ('.(count($section['big']) - \App\Livewire\Operator\Website\V2\ClustersBoardTab::VISIBLE).' daha)' }}</button>
                </div>
            @endif
            @if ($section['small'] !== [])
                <details class="group card mt-4" data-small-clusters="{{ $section['id'] }}">
                    <summary class="flex min-w-0 cursor-pointer list-none flex-wrap items-center gap-x-3 gap-y-0.5 px-4 py-3 hover:bg-gray-50/70 dark:hover:bg-white/[0.02]">
                        <svg class="h-4 w-4 shrink-0 text-gray-400 transition group-open:rotate-90" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.17 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
                        <span class="text-[13px] font-medium text-gray-700 dark:text-gray-300">Küçük kümeler ({{ count($section['small']) }})</span>
                        <span class="text-xs text-gray-500">sektör talebi {{ \App\Livewire\Operator\Website\V2\ClustersBoardTab::SMALL_DEMAND }}’un altında, bizde gösterim yok</span>
                    </summary>
                    <ul class="border-t border-gray-200 dark:border-gray-800">
                        @foreach ($section['small'] as $card)
                            <li class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 border-t border-gray-200 px-4 py-2.5 text-[13px] first:border-t-0 dark:border-gray-800" wire:key="small-cluster-{{ $card['cluster']->id }}" data-small-cluster="{{ $card['cluster']->id }}">
                                <span class="min-w-0 flex-1 truncate text-gray-900 dark:text-white">{{ $card['cluster']->name }}</span>
                                <span class="text-xs tabular-nums text-gray-500">Sektör talebi {{ $num($card['demand']) }}, bizim gösterim 0</span>
                                <x-ta.pill :tone="$card['matched'] ? 'ok' : 'info'">{{ $card['matched'] ? 'Eşlendi' : 'Eşlenmedi' }}</x-ta.pill>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
            @endif
        </section>
    @empty
        <div class="card">
            <x-operator.cluster-readiness :asset-id="$this->assetId" what="Kümeler" class="border-b border-gray-200 px-4 py-3.5 dark:border-gray-800" />
            <x-ta.empty-state size="sm" title="Bu filtrede küme yok.">
                @if ($service !== '' || $match !== '' || $type !== '' || trim($search) !== '')
                    <button type="button" wire:click="clearFilters" class="btn" data-empty-action>Filtreleri temizle</button>
                @endif
            </x-ta.empty-state>
        </div>
    @endforelse
</div>
