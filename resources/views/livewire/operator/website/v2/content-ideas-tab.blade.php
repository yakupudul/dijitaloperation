@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-white';
    $btn = 'rounded-lg bg-brand-500 px-2 py-1 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50';
    $ghost = 'rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
    $chip = 'rounded-full px-2 py-0.5 text-xs';
    $tone = ['technical' => 'bg-rose-100 text-rose-800', 'no_page' => 'bg-rose-50 text-rose-700', 'improve' => 'bg-amber-50 text-amber-700', 'sufficient' => 'bg-success-50 text-success-700', 'unchecked' => 'bg-gray-100 text-gray-600', 'excluded' => 'bg-gray-100 text-gray-500'];
    $kinds = ['soru' => 'soru', 'bolum' => 'bölüm', 'yon' => 'yön', 'lokasyon' => 'lokasyon', 'ai_sorusu' => 'AI sorusu'];
    $types = \App\Models\ContentIdea::TYPE_LABELS;
    $num = fn ($v) => number_format((int) $v, 0, ',', '.');
@endphp
<div class="space-y-4 text-xs" data-content-ideas-tab @if ($polling) wire:poll.5s @endif>
    @if ($message !== '')
        <p role="status" class="flex items-center justify-between rounded-lg bg-blue-50 p-2 text-blue-800 dark:bg-blue-950 dark:text-blue-200">
            <span>{{ $message }}</span><button type="button" wire:click="$set('message', '')" aria-label="Kapat" class="px-2">×</button>
        </p>
    @endif

    @if ($flow !== [])
        <section class="{{ $card }}" data-site-flow>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="font-semibold text-gray-900 dark:text-white">Site akışı <span class="font-normal text-gray-500">· WordPress bağlıyken her gece kendiliğinden ilerler</span></p>
                <button type="button" wire:click="advanceFlow" class="{{ $ghost }}" data-advance-flow>Akışı şimdi ilerlet</button>
            </div>
            <ol class="mt-2 grid gap-1 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($flow as $step)
                    <li class="flex items-start gap-1.5" data-flow-step="{{ $step['key'] }}" data-done="{{ $step['done'] ? '1' : '0' }}">
                        <span @class(['mt-0.5 inline-block h-2 w-2 shrink-0 rounded-full', 'bg-success-500' => $step['done'], 'bg-gray-300' => ! $step['done']])></span>
                        <span><span class="font-medium">{{ $step['label'] }}</span> <span class="text-gray-500">· {{ $step['detail'] }}</span></span>
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
            <select wire:model.live="state" aria-label="Durum" class="{{ $input }}">
                <option value="">Tüm durumlar</option>
                @foreach (\App\Services\Site\ContentIdeaState::LABELS as $key => $label)<option value="{{ $key }}">{{ $label }} ({{ $counts[$key] ?? 0 }})</option>@endforeach
            </select>
        </div>
        <p class="text-gray-500">Her küme bir ana fikirdir; altındakiler havuzdaki ek fikirler. Eşleştirme sisteme çekilmiş sayfa metinleriyle yapılır, siteye bağlanılmaz. Puan (1–100) yalnız Search Console: sıralama, kapsam, tıklama oranı.</p>
    </section>

    @if ($pendingClusters->isNotEmpty())
        <details class="{{ $card }}" data-pending-clusters @if ($groups->total() === 0) open @endif>
            <summary class="cursor-pointer font-semibold text-amber-800 dark:text-amber-200">Onay bekleyen kümeler ({{ $pendingClusters->count() }}) · markanın hizmetlerinde</summary>
            <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                <p class="text-gray-500">Onaylanan küme bu listeye iner (kümeler ortak: aynı hizmetteki diğer markalara da iner).</p>
                <button type="button" wire:click="approveAllClusters" wire:confirm="{{ $pendingClusters->count() }} küme onaylansın mı?" class="{{ $btn }}" data-approve-all-clusters>Tümünü onayla</button>
            </div>
            <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($pendingClusters as $pending)
                    <li class="flex flex-wrap items-center gap-2 py-1.5" wire:key="pending-cluster-{{ $pending->id }}">
                        <span class="font-medium text-gray-900 dark:text-white">{{ $pending->name }}</span>
                        <span class="{{ $chip }} bg-gray-100 text-gray-600">{{ $pending->service?->primaryName?->raw_label ?? '—' }}</span>
                        <span class="text-gray-500">{{ \App\Models\Cluster::PAGE_TYPE_LABELS[$pending->page_type] ?? $pending->page_type }} · {{ $pending->cluster_queries_count }} sorgu @if ($pending->mainQuery) · «{{ $pending->mainQuery->text }}»@endif</span>
                        <button type="button" wire:click="approveCluster({{ $pending->id }})" class="{{ $ghost }} ml-auto" data-approve-cluster="{{ $pending->id }}">Onayla</button>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    <section class="{{ $card }}">
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead class="text-gray-500"><tr><th class="py-1">Fikir</th><th>Tür</th><th class="text-right">Talep</th><th>Eşleşen URL</th><th class="text-right">Puan</th><th>Durum</th><th class="text-right">İşlem</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($groups as $g)
                        @php $cluster = $g['cluster']; $ideaStatus = $ideaStatuses[$cluster->id] ?? null; @endphp
                        @foreach ($g['mains'] as $r)
                            @include('livewire.operator.website.v2.partials.content-idea-row', ['r' => $r, 'cluster' => $cluster, 'demand' => $g['demand'], 'extra' => false])
                        @endforeach
                        @foreach ($g['extras'] as $r)
                            @include('livewire.operator.website.v2.partials.content-idea-row', ['r' => $r, 'cluster' => $cluster, 'demand' => null, 'extra' => true])
                        @endforeach
                        <tr wire:key="new-ideas-{{ $cluster->id }}" class="bg-gray-50/50 dark:bg-gray-950/40">
                            <td colspan="7" class="py-1 pl-6">
                                <div class="flex flex-wrap items-center gap-2">
                                    <select wire:model="ideaCount" aria-label="Fikir sayısı" class="{{ $input }} py-0.5">
                                        @foreach (range(1, \App\Services\Site\ContentIdeaPool::MAX_COUNT) as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach
                                    </select>
                                    <button type="button" wire:click="generateIdeas({{ $cluster->id }})" @disabled(($ideaStatus['status'] ?? null) === 'running') class="{{ $ghost }}" data-generate-ideas="{{ $cluster->id }}">Yeni fikir üret</button>
                                    <x-operator.ai-prompt-info operation="content.ideas" />
                                    @if (($ideaStatus['status'] ?? null) === 'running')<span class="text-brand-600">Fikirler üretiliyor…</span>
                                    @elseif (($ideaStatus['status'] ?? null) === 'done')<span class="text-gray-500">{{ $ideaStatus['added'] }} fikir havuza eklendi.</span>
                                    @elseif (in_array($ideaStatus['status'] ?? null, ['error', 'no_provider'], true))<span class="text-rose-600">Fikir üretilemedi.</span>@endif
                                    @foreach ((array) ($ideaStatus['rejected'] ?? []) as $rej)<span class="text-amber-700">Eklenmedi: {{ $rej['title'] }} · {{ $rej['reason'] }}</span>@endforeach
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-3"><x-operator.cluster-readiness :asset-id="$this->assetId" what="İçerik fikirleri" /><span class="text-gray-500">Filtreye uyan satır yok.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-2">{{ $groups->links() }}</div>
    </section>
</div>
