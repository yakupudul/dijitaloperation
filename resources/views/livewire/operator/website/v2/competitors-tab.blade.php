@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $btn = 'rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50';
    $ghost = 'rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 dark:text-gray-300 dark:ring-gray-700';
    $labels = \App\Services\Site\Competitors\CompetitorClassifier::LABELS;
    $tone = ['kendi' => 'bg-emerald-50 text-emerald-700', 'ticari' => 'bg-rose-50 text-rose-700', 'bilgi' => 'bg-blue-50 text-blue-700', 'dizin' => 'bg-gray-100 text-gray-600', 'haber' => 'bg-amber-50 text-amber-700'];
    $decisions = ['improve' => 'mevcut sayfayı geliştir', 'new_page' => 'yeni sayfa'];
    $analyzeErrors = ['few_competitors' => 'en az 2 rakip sayfası gerekli', 'no_provider' => 'AI bağlı değil', 'not_operational' => 'marka hizmet dışı', 'error' => 'hata'];
@endphp
<div class="space-y-4 text-sm dark:text-gray-200" data-competitors-tab @if ($polling) wire:poll.4s @endif>
    <header class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Rakipler</h2>
        <div class="flex items-center gap-2">
            @if (($refresh['status'] ?? null) === 'running')<span class="text-xs text-gray-500">güncelleniyor…</span>
            @elseif (($refresh['status'] ?? null) === 'ready')<span class="text-xs text-gray-500">{{ $refresh['serps'] }} sorgu · {{ $refresh['pages'] }} sayfa{{ ($refresh['errors'] ?? 0) > 0 ? ' · '.$refresh['errors'].' hata' : '' }}</span>
            @elseif ($refresh !== null)<span class="text-xs text-rose-600">{{ ['not_operational' => 'marka hizmet dışı', 'no_clusters' => 'onaylı küme yok', 'error' => 'hata'][$refresh['status']] ?? $refresh['status'] }}</span>@endif
            <button type="button" wire:click="refreshCompetitors" @disabled(! $operational) class="{{ $btn }}" data-action="refresh" @if (! $operational) title="Müşteri pasif veya marka yok" @endif>Rakipleri güncelle</button>
        </div>
    </header>

    @if ($message !== '')
        <p role="status" class="rounded-lg bg-blue-50 p-2 text-blue-800 dark:bg-blue-950 dark:text-blue-200">{{ $message }}</p>
    @endif
    @if ($errors->any())<p class="text-xs text-rose-600">{{ $errors->first() }}</p>@endif

    @forelse ($rows as $row)
        @php($serp = $row['serp'])
        <section class="{{ $card }}" data-cluster="{{ $row['cluster']->id }}" wire:key="cluster-{{ $row['cluster']->id }}">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p class="font-semibold text-gray-900 dark:text-white">{{ $row['cluster']->name }}</p>
                    <p class="text-xs text-gray-500">„{{ $serp?->query ?? $row['query'] }}"
                        @if ($serp) · {{ $serp->language_code }} · {{ $serp->device }} · {{ $serp->fetched_at?->format('d.m.Y') ?? '—' }}@endif
                        · bizim sıra <span class="font-semibold" data-own-rank>{{ $serp?->own_rank ?? 'yok' }}</span></p>
                </div>
                <div class="flex items-center gap-2">
                    @if (($row['analyze']['status'] ?? null) === 'running')<span class="text-xs text-gray-500">analiz ediliyor…</span>
                    @elseif (isset($analyzeErrors[$row['analyze']['status'] ?? '']))<span class="text-xs text-rose-600">{{ $analyzeErrors[$row['analyze']['status']] }}</span>@endif
                    @if ($serp && $serp->results)
                        <button type="button" wire:click="analyze({{ $serp->id }})" @disabled(! $operational) class="{{ $ghost }}" data-action="analyze">Analiz et</button>
                    @endif
                </div>
            </div>

            @if ($serp?->status === 'error')
                <p class="mt-2 text-xs text-rose-600">SERP alınamadı: {{ \Illuminate\Support\Str::limit((string) $serp->error, 120) }}</p>
            @endif

            @if ($serp && $serp->results)
                <table class="mt-3 w-full text-left text-xs">
                    <thead class="text-gray-500"><tr><th class="w-10 py-1">Sıra</th><th>Domain</th><th class="w-28">Tür</th></tr></thead>
                    <tbody>
                        @foreach ($serp->results as $result)
                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                <td class="py-1">{{ $result['rank'] }}</td>
                                <td><a href="{{ $result['url'] }}" target="_blank" rel="noopener noreferrer" class="hover:underline">{{ $result['domain'] }}</a>
                                    @if (isset($missing[\App\Services\Site\Competitors\CompetitorPageStore::hash($result['url'])]))<span class="ml-1 text-gray-400">· eksik</span>@endif</td>
                                <td>@if ($result['class'])<span class="rounded-full px-2 py-0.5 {{ $tone[$result['class']] ?? '' }}">{{ $labels[$result['class']] ?? $result['class'] }}</span>@else — @endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @elseif (! $serp)
                <p class="mt-2 text-xs text-gray-500">Henüz SERP yok.</p>
            @endif

            @if ($serp?->analysis)
                @php($a = $serp->analysis)
                <dl class="mt-3 grid gap-1 text-xs sm:grid-cols-2" data-analysis>
                    <div><dt class="inline text-gray-500">İhtiyaç:</dt> <dd class="inline">{{ $a['need'] ?? '—' }}</dd></div>
                    <div><dt class="inline text-gray-500">Sayfa tipi:</dt> <dd class="inline">{{ \App\Models\Cluster::PAGE_TYPE_LABELS[$a['page_type'] ?? ''] ?? '—' }} · {{ $decisions[$a['decision'] ?? ''] ?? '—' }}{{ ($a['our_page'] ?? null) === null ? ' · sayfa yok' : '' }}</dd></div>
                    @if (($a['missing_info'] ?? []) !== [])<div class="sm:col-span-2"><dt class="inline text-gray-500">Bizde eksik:</dt> <dd class="inline">{{ implode(' · ', $a['missing_info']) }}</dd></div>@endif
                    @if (($a['local_trust'] ?? []) !== [])<div class="sm:col-span-2"><dt class="inline text-gray-500">Yerel / güven:</dt> <dd class="inline">{{ implode(' · ', $a['local_trust']) }}</dd></div>@endif
                </dl>
            @endif

            @if ($row['suggestions']->isNotEmpty())
                <ul class="mt-3 space-y-1" data-suggestions>
                    @foreach ($row['suggestions'] as $suggestion)
                        <li class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-gray-50 p-2 text-xs dark:bg-gray-950" data-suggestion="{{ $suggestion->id }}" wire:key="rs-{{ $suggestion->id }}">
                            <div class="min-w-0">
                                <span class="font-medium text-gray-900 dark:text-white">{{ $suggestion->title }}</span>
                                <span class="text-gray-500">· {{ $suggestion->reason }}</span>
                                <span class="text-gray-400">· {{ count((array) ($suggestion->evidence['competitor_urls'] ?? [])) }} rakip{{ ($suggestion->evidence['gap'] ?? false) ? ' · boşluk' : '' }} · {{ \App\Livewire\Operator\Website\V2\SuggestionsTab::STATUS_LABELS[$suggestion->status] ?? $suggestion->status }}</span>
                            </div>
                            <div class="flex flex-wrap items-center gap-1">
                                @if (in_array($suggestion->status, ['open', 'recheck'], true))
                                    <button type="button" wire:click="approve({{ $suggestion->id }})" class="{{ $ghost }}">Onayla</button>
                                    <input type="text" wire:model="reasons.{{ $suggestion->id }}" placeholder="Neden" aria-label="Reddetme nedeni" class="w-24 rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950">
                                    <button type="button" wire:click="dismiss({{ $suggestion->id }})" class="{{ $ghost }}">Reddet</button>
                                @endif
                                @if ($suggestion->page_id !== null)
                                    @if ($suggestion->applied_at === null)<button type="button" wire:click="aiDo({{ $suggestion->id }})" @disabled(! $operational) class="{{ $btn }}" data-action="ai-do">AI ile yap</button>@endif
                                @elseif (data_get($suggestion->action, 'content_suggestion_id'))
                                    <button type="button" wire:click="prepareDraft({{ $suggestion->id }})" @disabled(! $operational) class="{{ $btn }}" data-action="draft">Taslak hazırla</button>
                                @else
                                    <button type="button" wire:click="addContent({{ $suggestion->id }})" class="{{ $btn }}" data-action="add-content">Yeni içerik olarak ekle</button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @empty
        <p class="{{ $card }} text-gray-500">Onaylı küme yok. Sorgular › Kümeler'de markanın hizmet kümelerini onaylayın.</p>
    @endforelse
</div>
