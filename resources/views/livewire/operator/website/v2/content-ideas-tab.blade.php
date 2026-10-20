@php
    $card = 'min-w-0';
    $input = 'rounded-lg border border-gray-200 bg-white px-2.5 py-1 text-[13px] text-gray-700 shadow-card focus:border-gray-300 focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
    $select = 'h-8 rounded-lg border border-gray-200 bg-white py-0 pl-2.5 pr-8 text-[13px] text-gray-700 shadow-card focus:border-gray-300 focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $btn = 'btn h-7! px-2.5! text-xs!';
    $ghost = 'btn-quiet h-7! px-2! text-xs!';
    $stateBtn = fn (bool $on) => $on ? 'h-7 shrink-0 whitespace-nowrap rounded-md bg-gray-100 px-2.5 text-[13px] font-medium text-gray-900 dark:bg-white/10 dark:text-white' : 'h-7 shrink-0 whitespace-nowrap rounded-md px-2.5 text-[13px] text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white';
    $chip = 'rounded-full px-2 py-0.5 text-xs';
    $tone = ['technical' => 'bg-error-500', 'no_page' => 'bg-error-500', 'improve' => 'bg-warning-500', 'sufficient' => 'bg-success-500', 'unchecked' => 'bg-gray-400', 'excluded' => 'bg-gray-300'];
    $kinds = ['soru' => 'soru', 'bolum' => 'bölüm', 'yon' => 'yön', 'lokasyon' => 'lokasyon', 'ai_sorusu' => 'AI sorusu'];
    $types = \App\Models\ContentIdea::TYPE_LABELS;
    $num = fn ($v) => number_format((int) $v, 0, ',', '.');
@endphp
<div class="min-w-0 space-y-8 text-sm" data-content-ideas-tab @if ($polling) wire:poll.5s @endif>
    @if ($message !== '')
        <p role="status" class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-800 shadow-card dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-200">
            <span>{{ $message }}</span><button type="button" wire:click="$set('message', '')" aria-label="Kapat" class="btn-quiet h-7! px-2!">×</button>
        </p>
    @endif

    {{-- 1. Where the site's content work stands, step by step. --}}
    @if ($flow !== [])
        <section class="{{ $card }}" data-site-flow>
            <div class="section-head justify-between">
                <h2 class="section-title" title="WordPress bağlıyken Claude'a devredilmişse kendiliğinden, değilse «Akışı ilerlet» ile ilerler">Site akışı</h2>
                <button type="button" wire:click="advanceFlow" class="btn" data-advance-flow>Akışı şimdi ilerlet</button>
            </div>
            <ol class="card grid sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($flow as $step)
                    <li class="flex items-start gap-2.5 border-t border-gray-200 px-4 py-3 first:border-t-0 sm:border-t-0 dark:border-gray-800" data-flow-step="{{ $step['key'] }}" data-done="{{ $step['done'] ? '1' : '0' }}">
                        <span @class(['mt-1.5 inline-block h-2 w-2 shrink-0 rounded-full', 'bg-success-500' => $step['done'], 'bg-gray-300 dark:bg-gray-600' => ! $step['done']])></span>
                        <span class="min-w-0"><span class="block text-[13px] font-medium text-gray-900 dark:text-white">{{ $step['label'] }}</span> <span class="block text-xs text-gray-500">{{ $step['detail'] }}</span></span>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    {{-- 2. Every cluster's ideas: the matching run, filters and the list. --}}
    <section class="{{ $card }}">
        <div class="section-head justify-between">
            <div class="flex min-w-0 flex-wrap items-baseline gap-x-3">
                <h2 class="section-title">İçerik fikirleri</h2>
                <span class="section-count">{{ $num(array_sum($counts)) }}</span>
                @if ($auditStatus)<span class="text-xs text-gray-500">Eşleştir: {{ $auditStatus }}</span>@endif
            </div>
            <span class="flex w-full items-center gap-1.5 sm:w-auto">
                <button type="button" wire:click="matchAll" class="btn-primary flex-1 sm:flex-none" data-match-all>Eşleştir</button>
                <x-operator.ai-prompt-info operation="site.cluster_match" />
                <x-operator.ai-prompt-info operation="site.cluster_gaps" />
            </span>
        </div>
        <div class="mb-3 flex min-w-0 flex-wrap items-center gap-2">
            <select wire:model.live="service" aria-label="Hizmet" class="{{ $select }} w-full sm:w-52">
                <option value="">Tüm hizmetler</option>
                @foreach ($services as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
            <select wire:model.live="type" aria-label="Tür" class="{{ $select }} w-full sm:w-44">
                <option value="">Tüm türler</option>
                @foreach ($types as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
            <label class="flex items-center gap-2 text-[13px] text-gray-500 sm:ml-auto">Yeni fikir sayısı
                <select wire:model="ideaCount" aria-label="Fikir sayısı" class="{{ $select }}">
                    @foreach (range(1, \App\Services\Site\ContentIdeaPool::MAX_COUNT) as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach
                </select>
            </label>
        </div>
        <div class="no-scrollbar mb-3 flex max-w-full gap-0.5 overflow-x-auto rounded-lg border border-gray-200 bg-white p-0.5 shadow-card sm:w-fit dark:border-gray-700 dark:bg-gray-900" role="group" aria-label="Durum" data-state-filters>
            @php $allCount = array_sum($counts); @endphp
            <button type="button" wire:click="$set('state', '')" class="{{ $stateBtn($state === '') }}">Tümü <span class="text-xs font-normal text-gray-400">{{ $num($allCount) }}</span></button>
            @foreach (\App\Services\Site\ContentIdeaState::LABELS as $key => $label)
                @if (($counts[$key] ?? 0) > 0 || $state === $key)
                    <button type="button" wire:click="$set('state', '{{ $key }}')" data-state-filter="{{ $key }}" class="{{ $stateBtn($state === $key) }}">{{ $label }} <span class="text-xs font-normal text-gray-400">{{ $num($counts[$key] ?? 0) }}</span></button>
                @endif
            @endforeach
        </div>
        <p class="sr-only">Her küme bir ana fikirdir (kalın satır); altındaki ↳ satırlar aynı kümenin ek fikirleri. Durum etiketine tıklayınca neden, içerik önerisi, eksikler ve çakışan sayfalar açılır.</p>
        <details class="mb-4 text-[13px] text-gray-500" data-button-help>
            <summary class="cursor-pointer list-none text-xs font-medium text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Butonlar ne yapar?</summary>
            <ul class="card mt-2 space-y-1 px-4 py-3">
                <li><span class="font-medium text-gray-900 dark:text-white">Yeniden keşfet:</span> yalnız bu fikir için sitedeki sayfaları yeniden okur, uygun sayfa var mı bakar (AI).</li>
                <li><span class="font-medium text-gray-900 dark:text-white">SEO analizi:</span> sayfa için adım adım düzeltme reçetesi hazırlar (AI). Hiçbir şey yazmaz.</li>
                <li><span class="font-medium text-gray-900 dark:text-white">AI ile geliştir:</span> WordPress'teki sayfanın eksiklerini tamamlayan yeni sürümü hazırlar. «Önizle ve güncelle»de onaylayınca WordPress'te güncellenir (geri alınabilir).</li>
                <li><span class="font-medium text-gray-900 dark:text-white">AI ile taslak yaz:</span> sitede sayfa yoksa makaleyi yazar ve İçerik planı'na koyar. WordPress'e kendiliğinden gitmez; orada inceleyip «WordPress taslağı gönder» dersen yazı WordPress'te taslak olarak açılır, yayına almak sizde.</li>
                <li><span class="font-medium text-gray-900 dark:text-white">+ Fikir:</span> bu küme için seçili sayıda yeni içerik fikri üretir (AI); ↳ satırlar olarak eklenir.</li>
            </ul>
        </details>

        @if ($pendingClusters->isNotEmpty())
            <details class="group card mb-4" data-pending-clusters @if ($groups->total() === 0) open @endif>
                <summary class="flex min-w-0 cursor-pointer list-none flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3.5 hover:bg-gray-50/70 dark:hover:bg-white/[0.02]">
                    <svg class="h-4 w-4 shrink-0 text-gray-400 transition group-open:rotate-90" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.17 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
                    <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span>
                    <span class="text-sm font-medium text-gray-900 dark:text-white">Onay bekleyen kümeler ({{ $pendingClusters->count() }})</span>
                    <span class="min-w-0 text-[13px] text-gray-500">Onaylanan küme bu listeye iner; aynı hizmetteki diğer markalara da iner.</span>
                </summary>
                <div class="flex justify-end border-t border-gray-200 px-4 py-2.5 dark:border-gray-800">
                    <button type="button" wire:click="approveAllClusters" wire:confirm="{{ $pendingClusters->count() }} küme onaylansın mı?" class="btn w-full sm:w-auto" data-approve-all-clusters>Tümünü onayla</button>
                </div>
                <ul>
                    @foreach ($pendingClusters as $pending)
                        <li class="card-row flex-wrap sm:flex-nowrap" wire:key="pending-cluster-{{ $pending->id }}">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $pending->name }}</p>
                                <p class="mt-0.5 text-[13px] text-gray-500">{{ $pending->service?->primaryName?->raw_label ?? '—' }}, {{ \App\Models\Cluster::PAGE_TYPE_LABELS[$pending->page_type] ?? $pending->page_type }}, {{ $pending->cluster_queries_count }} sorgu @if ($pending->mainQuery) «{{ $pending->mainQuery->text }}»@endif</p>
                            </div>
                            <button type="button" wire:click="approveCluster({{ $pending->id }})" class="{{ $btn }}" data-approve-cluster="{{ $pending->id }}">Onayla</button>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif

        <div class="card overflow-x-auto">
            <table class="data-table [&_td]:border-b-0 [&_td]:px-3 [&_td:first-child]:pl-4 [&_td:last-child]:pr-4 [&_th]:px-3 [&_th:first-child]:pl-4 [&_th:last-child]:pr-4">
                <thead><tr><th>Fikir</th><th>Tür</th><th class="text-right!" title="Kümenin sorgularının sektördeki toplam gösterimi">Talep</th><th>Sitedeki sayfa</th><th class="text-right!" title="Search Console puanı (1–100)">Puan</th><th>Durum</th><th class="text-right!">İşlem</th></tr></thead>
                @forelse ($groups as $g)
                    @php $cluster = $g['cluster']; $ideaStatus = $ideaStatuses[$cluster->id] ?? null; @endphp
                    @foreach ($g['mains'] as $r)
                        @include('livewire.operator.website.v2.partials.content-idea-row', ['r' => $r, 'cluster' => $cluster, 'demand' => $g['demand'], 'extra' => false])
                    @endforeach
                    @foreach ($g['extras'] as $r)
                        @include('livewire.operator.website.v2.partials.content-idea-row', ['r' => $r, 'cluster' => $cluster, 'demand' => null, 'extra' => true])
                    @endforeach
                    @if (($ideaStatus['status'] ?? null) === 'done' || in_array($ideaStatus['status'] ?? null, ['error', 'no_provider'], true) || ! empty($ideaStatus['rejected']))
                        <tbody wire:key="idea-status-{{ $cluster->id }}"><tr><td colspan="7" class="py-2! pl-8! text-xs">
                            @if (($ideaStatus['status'] ?? null) === 'done')<span class="text-gray-500">{{ $ideaStatus['added'] }} yeni fikir eklendi.</span>
                            @elseif (in_array($ideaStatus['status'] ?? null, ['error', 'no_provider'], true))<span class="text-error-600">Fikir üretilemedi.</span>@endif
                            @foreach ((array) ($ideaStatus['rejected'] ?? []) as $rej)<span class="text-warning-700">Eklenmedi: {{ $rej['title'] }} ({{ $rej['reason'] }})</span>@endforeach
                        </td></tr></tbody>
                    @endif
                @empty
                    <tbody><tr><td colspan="7" class="p-0!"><x-operator.cluster-readiness :asset-id="$this->assetId" what="İçerik fikirleri" class="border-b border-gray-200 px-4 py-3.5 dark:border-gray-800" /><x-ta.empty-state size="sm" title="Filtreye uyan satır yok." /></td></tr></tbody>
                @endforelse
            </table>
        </div>
        @if ($groups->hasPages())<div class="mt-3">{{ $groups->links() }}</div>@endif
    </section>
</div>
