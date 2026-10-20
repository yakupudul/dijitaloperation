@php
    $card = 'min-w-0';
    $btn = 'btn-primary';
    $ghost = 'btn h-7! px-2.5! text-xs!';
    $quiet = 'btn-quiet h-7! px-2! text-xs!';
    $input = 'h-7 rounded-lg border border-gray-200 bg-white px-2 text-xs text-gray-700 focus:border-gray-300 focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
    $labels = \App\Services\Site\Competitors\CompetitorClassifier::LABELS;
    $tone = ['kendi' => 'ok', 'ticari' => 'critical', 'bilgi' => 'info', 'dizin' => 'info', 'haber' => 'look'];
    $decisions = ['improve' => 'mevcut sayfayı geliştir', 'new_page' => 'yeni sayfa'];
    $analyzeErrors = ['few_competitors' => 'en az 2 rakip sayfası gerekli', 'no_provider' => 'AI bağlı değil', 'not_operational' => 'marka hizmet dışı', 'error' => 'hata'];
@endphp
<div class="min-w-0 space-y-8 text-sm dark:text-gray-200" data-competitors-tab @if ($polling) wire:poll.4s @endif>
    {{-- Who ranks above the site for each cluster, and what they have that the site lacks. --}}
    <div class="section-head mb-0! justify-between">
        <div class="flex min-w-0 flex-wrap items-baseline gap-x-3">
            <h2 class="section-title">Rakipler</h2>
            <span class="section-count">{{ count($rows) }}</span>
            <span class="text-[13px] text-gray-500">kümenin ana sorgusunda Google'da üstte olanlar</span>
        </div>
        <div class="flex w-full flex-wrap items-center gap-x-3 gap-y-1.5 sm:w-auto">
            @if (($refresh['status'] ?? null) === 'running')<span class="text-xs text-gray-500">güncelleniyor…</span>
            @elseif (($refresh['status'] ?? null) === 'ready')<span class="text-xs text-gray-500">{{ $refresh['serps'] }} sorgu, {{ $refresh['pages'] }} sayfa{{ ($refresh['errors'] ?? 0) > 0 ? ', '.$refresh['errors'].' hata' : '' }}</span>
            @elseif ($refresh !== null)<span class="text-xs text-error-600">{{ ['not_operational' => 'marka hizmet dışı', 'no_clusters' => 'onaylı küme yok', 'error' => 'hata'][$refresh['status']] ?? $refresh['status'] }}</span>@endif
            <span class="flex w-full items-center gap-1.5 sm:w-auto">
                <button type="button" wire:click="refreshCompetitors" @disabled(! $operational) class="{{ $btn }} flex-1 sm:flex-none" data-action="refresh" @if (! $operational) title="Müşteri pasif veya marka yok" @endif>Rakipleri güncelle</button>
                <x-operator.ai-prompt-info operation="competitors.classify" />
            </span>
        </div>
    </div>

    @if ($message !== '')
        <p role="status" class="rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-800 shadow-card dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-200">{{ $message }}</p>
    @endif
    @if ($errors->any())<p class="text-xs text-error-600">{{ $errors->first() }}</p>@endif

    @forelse ($rows as $row)
        @php($serp = $row['serp'])
        <section class="card" data-cluster="{{ $row['cluster']->id }}" wire:key="cluster-{{ $row['cluster']->id }}">
            <div class="flex min-w-0 flex-wrap items-start justify-between gap-x-4 gap-y-2 px-4 py-3.5">
                <div class="min-w-0 flex-1">
                    <h3 class="section-title">{{ $row['cluster']->name }}</h3>
                    <p class="mt-0.5 text-[13px] text-gray-500">„{{ $serp?->query ?? $row['query'] }}"
                        @if ($serp), {{ $serp->language_code }}, {{ $serp->device }}, {{ $serp->fetched_at?->format('d.m.Y') ?? '—' }}@endif
                        <span class="ml-2">bizim sıra</span> <span class="font-medium text-gray-900 dark:text-white" data-own-rank>{{ $serp?->own_rank ?? 'yok' }}</span></p>
                </div>
                <div class="flex items-center gap-2">
                    @if (($row['analyze']['status'] ?? null) === 'running')<span class="text-xs text-gray-500">analiz ediliyor…</span>
                    @elseif (isset($analyzeErrors[$row['analyze']['status'] ?? '']))<span class="text-xs text-error-600">{{ $analyzeErrors[$row['analyze']['status']] }}</span>@endif
                    @if ($serp && $serp->results)
                        <button type="button" wire:click="analyze({{ $serp->id }})" @disabled(! $operational) class="btn" data-action="analyze">Analiz et</button>
                        <x-operator.ai-prompt-info operation="competitors.analyze" />
                    @endif
                </div>
            </div>

            @if ($serp?->status === 'error')
                <p class="border-t border-gray-200 px-4 py-2.5 text-xs text-error-600 dark:border-gray-800">SERP alınamadı: {{ \Illuminate\Support\Str::limit((string) $serp->error, 120) }}</p>
            @endif

            @if ($serp && $serp->results)
                <div class="min-w-0 overflow-x-auto border-t border-gray-200 dark:border-gray-800">
                    <table class="data-table">
                        <thead><tr><th class="w-14">Sıra</th><th>Domain</th><th class="w-32">Tür</th></tr></thead>
                        <tbody>
                            @foreach ($serp->results as $result)
                                <tr>
                                    <td class="tabular-nums text-gray-500">{{ $result['rank'] }}</td>
                                    <td class="min-w-[12rem]"><a href="{{ $result['url'] }}" target="_blank" rel="noopener noreferrer" class="text-gray-900 hover:underline dark:text-white">{{ $result['domain'] }}</a>
                                        @if (isset($missing[\App\Services\Site\Competitors\CompetitorPageStore::hash($result['url'])]))<span class="ml-1 text-xs text-gray-500">eksik</span>@endif</td>
                                    <td class="whitespace-nowrap">@if ($result['class'])<x-ta.pill :tone="$tone[$result['class']] ?? 'info'">{{ $labels[$result['class']] ?? $result['class'] }}</x-ta.pill>@else — @endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @elseif (! $serp)
                <p class="border-t border-gray-200 px-4 py-3 text-[13px] text-gray-500 dark:border-gray-800">Henüz SERP yok.</p>
            @endif

            @if ($serp?->analysis)
                @php($a = $serp->analysis)
                <dl class="grid gap-x-6 gap-y-1.5 border-t border-gray-200 bg-gray-50/70 px-4 py-3 text-[13px] sm:grid-cols-2 dark:border-gray-800 dark:bg-white/[0.02]" data-analysis>
                    <div><dt class="inline text-gray-500">İhtiyaç:</dt> <dd class="inline text-gray-800 dark:text-gray-200">{{ $a['need'] ?? '—' }}</dd></div>
                    <div><dt class="inline text-gray-500">Sayfa tipi:</dt> <dd class="inline text-gray-800 dark:text-gray-200">{{ \App\Models\Cluster::PAGE_TYPE_LABELS[$a['page_type'] ?? ''] ?? '—' }}, {{ $decisions[$a['decision'] ?? ''] ?? '—' }}{{ ($a['our_page'] ?? null) === null ? ', sayfa yok' : '' }}</dd></div>
                    @if (($a['missing_info'] ?? []) !== [])<div class="sm:col-span-2"><dt class="inline text-gray-500">Bizde eksik:</dt> <dd class="inline text-gray-800 dark:text-gray-200">{{ implode(', ', $a['missing_info']) }}</dd></div>@endif
                    @if (($a['local_trust'] ?? []) !== [])<div class="sm:col-span-2"><dt class="inline text-gray-500">Yerel / güven:</dt> <dd class="inline text-gray-800 dark:text-gray-200">{{ implode(', ', $a['local_trust']) }}</dd></div>@endif
                </dl>
            @endif

            @if ($row['suggestions']->isNotEmpty())
                <ul class="border-t border-gray-200 dark:border-gray-800" data-suggestions>
                    @foreach ($row['suggestions'] as $suggestion)
                        <li class="card-row flex-wrap sm:flex-nowrap" data-suggestion="{{ $suggestion->id }}" wire:key="rs-{{ $suggestion->id }}">
                            <div class="min-w-0 flex-[1_1_16rem]">
                                <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $suggestion->title }}</span>
                                <p class="mt-0.5 text-[13px] text-gray-500">{{ $suggestion->reason }}</p>
                                <p class="text-xs text-gray-500">{{ count((array) ($suggestion->evidence['competitor_urls'] ?? [])) }} rakip{{ ($suggestion->evidence['gap'] ?? false) ? ', boşluk' : '' }}, {{ \App\Livewire\Operator\Website\V2\SuggestionsTab::STATUS_LABELS[$suggestion->status] ?? $suggestion->status }}</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-1">
                                @if (in_array($suggestion->status, ['open', 'recheck'], true))
                                    <button type="button" wire:click="approve({{ $suggestion->id }})" class="{{ $ghost }}">Onayla</button>
                                    <input type="text" wire:model="reasons.{{ $suggestion->id }}" placeholder="Neden" aria-label="Reddetme nedeni" class="{{ $input }} w-24">
                                    <button type="button" wire:click="dismiss({{ $suggestion->id }})" class="{{ $quiet }}">Reddet</button>
                                @endif
                                @if ($suggestion->page_id !== null)
                                    @if ($suggestion->applied_at === null)<button type="button" wire:click="aiDo({{ $suggestion->id }})" @disabled(! $operational) class="{{ $ghost }}" data-action="ai-do">AI ile yap</button><x-operator.ai-prompt-info operation="site.apply_change" />@endif
                                @elseif (data_get($suggestion->action, 'content_suggestion_id'))
                                    <button type="button" wire:click="prepareDraft({{ $suggestion->id }})" @disabled(! $operational) class="{{ $ghost }}" data-action="draft">Taslak hazırla</button>
                                    <x-operator.ai-prompt-info operation="site.write_article" />
                                @else
                                    <button type="button" wire:click="addContent({{ $suggestion->id }})" class="{{ $ghost }}" data-action="add-content">Yeni içerik olarak ekle</button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @empty
        <div class="card"><x-operator.cluster-readiness :asset-id="$this->websiteId" what="Rakipler" class="px-4 py-3.5" /></div>
    @endforelse
</div>
