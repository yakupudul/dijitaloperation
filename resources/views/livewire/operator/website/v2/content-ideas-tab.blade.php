@php
    $card = 'min-w-0';
    $input = 'rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-white';
    $btn = 'rounded-lg bg-brand-500 px-2 py-1 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50';
    $ghost = 'rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
    $chip = 'rounded-full px-2 py-0.5 text-xs';
    $tone = ['technical' => 'bg-error-500', 'no_page' => 'bg-error-500', 'improve' => 'bg-warning-500', 'sufficient' => 'bg-success-500', 'unchecked' => 'bg-gray-400', 'excluded' => 'bg-gray-300'];
    $kinds = ['soru' => 'soru', 'bolum' => 'bölüm', 'yon' => 'yön', 'lokasyon' => 'lokasyon', 'ai_sorusu' => 'AI sorusu'];
    $types = \App\Models\ContentIdea::TYPE_LABELS;
    $num = fn ($v) => number_format((int) $v, 0, ',', '.');
@endphp
<div class="space-y-10 text-sm" data-content-ideas-tab @if ($polling) wire:poll.5s @endif>
    @if ($message !== '')
        <p role="status" class="flex items-center justify-between rounded-lg bg-gray-50 p-2 text-gray-800 dark:bg-gray-950 dark:text-gray-200">
            <span>{{ $message }}</span><button type="button" wire:click="$set('message', '')" aria-label="Kapat" class="px-2">×</button>
        </p>
    @endif

    @if ($flow !== [])
        <section class="{{ $card }}" data-site-flow>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="section-title" title="WordPress bağlıyken Claude'a devredilmişse kendiliğinden, değilse «Akışı ilerlet» ile ilerler">Site akışı</h2>
                <button type="button" wire:click="advanceFlow" class="{{ $ghost }}" data-advance-flow>Akışı şimdi ilerlet</button>
            </div>
            <ol class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($flow as $step)
                    <li class="flex items-start gap-1.5" data-flow-step="{{ $step['key'] }}" data-done="{{ $step['done'] ? '1' : '0' }}">
                        <span @class(['mt-0.5 inline-block h-2 w-2 shrink-0 rounded-full', 'bg-success-500' => $step['done'], 'bg-gray-300' => ! $step['done']])></span>
                        <span><span class="font-medium">{{ $step['label'] }}</span> <span class="block text-gray-500">{{ $step['detail'] }}</span></span>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    <section class="{{ $card }} space-y-2">
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="matchAll" class="{{ $btn }}" data-match-all>Eşleştir</button>
            <x-operator.ai-prompt-info operation="site.cluster_match" />
            <x-operator.ai-prompt-info operation="site.cluster_gaps" />
            @if ($auditStatus)<span class="text-gray-500">Eşleştir: {{ $auditStatus }}</span>@endif
            <select wire:model.live="service" aria-label="Hizmet" class="{{ $input }} ml-auto">
                <option value="">Tüm hizmetler</option>
                @foreach ($services as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
            <select wire:model.live="type" aria-label="Tür" class="{{ $input }}">
                <option value="">Tüm türler</option>
                @foreach ($types as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
        </div>
        <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Durum" data-state-filters>
            @php $allCount = array_sum($counts); @endphp
            <button type="button" wire:click="$set('state', '')" @class(['rounded-lg px-2.5 py-1 font-semibold', 'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white' => $state === '', 'text-gray-500 hover:text-gray-900 dark:text-gray-400' => $state !== ''])>Tümü <span class="font-normal opacity-75">{{ $num($allCount) }}</span></button>
            @foreach (\App\Services\Site\ContentIdeaState::LABELS as $key => $label)
                @if (($counts[$key] ?? 0) > 0 || $state === $key)
                    <button type="button" wire:click="$set('state', '{{ $key }}')" data-state-filter="{{ $key }}" @class(['rounded-lg px-2.5 py-1 font-semibold', 'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white' => $state === $key, 'text-gray-500 hover:text-gray-900 dark:text-gray-400' => $state !== $key])>{{ $label }} <span class="font-normal opacity-75">{{ $num($counts[$key] ?? 0) }}</span></button>
                @endif
            @endforeach
            <label class="ml-auto flex items-center gap-1 text-gray-500">Yeni fikir sayısı
                <select wire:model="ideaCount" aria-label="Fikir sayısı" class="{{ $input }} py-0.5">
                    @foreach (range(1, \App\Services\Site\ContentIdeaPool::MAX_COUNT) as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach
                </select>
            </label>
        <p class="sr-only">Her küme bir ana fikirdir (kalın satır); altındaki ↳ satırlar aynı kümenin ek fikirleri. Durum etiketine tıklayınca neden, içerik önerisi, eksikler ve çakışan sayfalar açılır.</p>
        <details class="text-gray-500" data-button-help>
            <summary class="cursor-pointer font-medium text-gray-600 hover:text-gray-900 dark:text-gray-300">Butonlar ne yapar?</summary>
            <ul class="mt-1 list-disc space-y-0.5 pl-4">
                <li><span class="font-semibold">Yeniden keşfet</span> — yalnız bu fikir için sitedeki sayfaları yeniden okur, uygun sayfa var mı bakar (AI).</li>
                <li><span class="font-semibold">SEO analizi</span> — sayfa için adım adım düzeltme reçetesi hazırlar (AI). Hiçbir şey yazmaz.</li>
                <li><span class="font-semibold">AI ile geliştir</span> — WordPress'teki sayfanın eksiklerini tamamlayan yeni sürümü hazırlar. «Önizle ve güncelle»de onaylayınca WordPress'te güncellenir (geri alınabilir).</li>
                <li><span class="font-semibold">AI ile taslak yaz</span> — sitede sayfa yoksa makaleyi yazar ve <span class="font-semibold">İçerik planı</span>'na koyar. WordPress'e kendiliğinden gitmez; orada inceleyip «WordPress taslağı gönder» dersen yazı WordPress'te <span class="font-semibold">taslak</span> olarak açılır, yayına almak sizde.</li>
                <li><span class="font-semibold">+ Fikir</span> — bu küme için seçili sayıda yeni içerik fikri üretir (AI); ↳ satırlar olarak eklenir.</li>
            </ul>
        </details>
    </section>

    @if ($pendingClusters->isNotEmpty())
        <details class="{{ $card }}" data-pending-clusters @if ($groups->total() === 0) open @endif>
            <summary class="cursor-pointer section-title"><span class="mr-2 inline-block h-1.5 w-1.5 rounded-full bg-warning-500 align-middle" aria-hidden="true"></span>Onay bekleyen kümeler ({{ $pendingClusters->count() }})</summary>
            <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                <p class="text-gray-500">Markanın hizmetlerinde. Onaylanan küme bu listeye iner (kümeler ortak: aynı hizmetteki diğer markalara da iner).</p>
                <button type="button" wire:click="approveAllClusters" wire:confirm="{{ $pendingClusters->count() }} küme onaylansın mı?" class="{{ $btn }}" data-approve-all-clusters>Tümünü onayla</button>
            </div>
            <ul class="mt-2 divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                @foreach ($pendingClusters as $pending)
                    <li class="flex flex-wrap items-center gap-2 py-2.5" wire:key="pending-cluster-{{ $pending->id }}">
                        <span class="font-medium text-gray-900 dark:text-white">{{ $pending->name }}</span>
                        <span class="text-gray-500">{{ $pending->service?->primaryName?->raw_label ?? '—' }}, {{ \App\Models\Cluster::PAGE_TYPE_LABELS[$pending->page_type] ?? $pending->page_type }}, {{ $pending->cluster_queries_count }} sorgu @if ($pending->mainQuery) «{{ $pending->mainQuery->text }}»@endif</span>
                        <button type="button" wire:click="approveCluster({{ $pending->id }})" class="{{ $ghost }} ml-auto" data-approve-cluster="{{ $pending->id }}">Onayla</button>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    <section class="{{ $card }}">
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead class="border-b border-gray-200 text-gray-500 dark:border-gray-800"><tr><th class="font-normal py-1">Fikir</th><th class="font-normal">Tür</th><th class="font-normal text-right" title="Kümenin sorgularının sektördeki toplam gösterimi">Talep</th><th class="font-normal">Sitedeki sayfa</th><th class="font-normal text-right" title="Search Console puanı (1–100)">Puan</th><th class="font-normal">Durum <span class="font-normal">(tıkla)</span></th><th class="font-normal text-right">İşlem</th></tr></thead>
                @forelse ($groups as $g)
                    @php $cluster = $g['cluster']; $ideaStatus = $ideaStatuses[$cluster->id] ?? null; @endphp
                    @foreach ($g['mains'] as $r)
                        @include('livewire.operator.website.v2.partials.content-idea-row', ['r' => $r, 'cluster' => $cluster, 'demand' => $g['demand'], 'extra' => false])
                    @endforeach
                    @foreach ($g['extras'] as $r)
                        @include('livewire.operator.website.v2.partials.content-idea-row', ['r' => $r, 'cluster' => $cluster, 'demand' => null, 'extra' => true])
                    @endforeach
                    @if (($ideaStatus['status'] ?? null) === 'done' || in_array($ideaStatus['status'] ?? null, ['error', 'no_provider'], true) || ! empty($ideaStatus['rejected']))
                        <tbody wire:key="idea-status-{{ $cluster->id }}"><tr><td colspan="7" class="py-1 pl-6">
                            @if (($ideaStatus['status'] ?? null) === 'done')<span class="text-gray-500">{{ $ideaStatus['added'] }} yeni fikir eklendi.</span>
                            @elseif (in_array($ideaStatus['status'] ?? null, ['error', 'no_provider'], true))<span class="text-error-600">Fikir üretilemedi.</span>@endif
                            @foreach ((array) ($ideaStatus['rejected'] ?? []) as $rej)<span class="text-warning-700">Eklenmedi: {{ $rej['title'] }} ({{ $rej['reason'] }})</span>@endforeach
                        </td></tr></tbody>
                    @endif
                @empty
                    <tbody><tr><td colspan="7" class="py-3"><x-operator.cluster-readiness :asset-id="$this->assetId" what="İçerik fikirleri" /><span class="text-gray-500">Filtreye uyan satır yok.</span></td></tr></tbody>
                @endforelse
            </table>
        </div>
        <div class="mt-2">{{ $groups->links() }}</div>
    </section>
</div>
