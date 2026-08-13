@php
    $glance = $data['glance'];
    $coverage = $data['profile_coverage'];
    $pulse = $data['review_pulse'];
    $actions = $data['customer_actions'];
    $snap = $data['visibility_snapshot'];
    $attentionItems = collect($data['needs_attention'])->take(4);
@endphp

<div class="space-y-5">
    {{-- KPI strip --}}
    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <button type="button" wire:click="setTab('profile')" class="rounded-xl bg-white p-4 text-left ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-900 dark:ring-gray-800 dark:hover:bg-white/[0.03]">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Profile coverage</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $glance['profile']['value'] }}</p>
            <p class="mt-1 text-xs text-warning-600 dark:text-warning-400">{{ $glance['profile']['label'] }}</p>
        </button>
        <button type="button" wire:click="setTab('visibility')" class="rounded-xl bg-white p-4 text-left ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-900 dark:ring-gray-800 dark:hover:bg-white/[0.03]">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Local visibility</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $glance['visibility']['value'] }}</p>
            <p class="mt-1 text-xs text-gray-500">{{ $glance['visibility']['label'] }}</p>
        </button>
        <button type="button" wire:click="setTab('reviews')" class="rounded-xl bg-white p-4 text-left ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-900 dark:ring-gray-800 dark:hover:bg-white/[0.03]">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Reviews</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $glance['reviews']['value'] }}</p>
            <p class="mt-1 text-xs text-gray-500">{{ $glance['reviews']['label'] }}</p>
        </button>
        <button type="button" wire:click="setPerfSub('actions')" class="rounded-xl bg-white p-4 text-left ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-900 dark:ring-gray-800 dark:hover:bg-white/[0.03]">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Customer actions</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $glance['actions']['value'] }}</p>
            <p class="mt-1 text-xs text-gray-500">{{ $glance['actions']['label'] }}</p>
        </button>
    </div>

    {{-- Needs Attention + Local Visibility Map --}}
    <div class="grid gap-4 xl:grid-cols-5">
        <section class="xl:col-span-2" aria-labelledby="gbp-attention-heading">
            <div class="mb-3 flex items-center justify-between gap-2">
                <h2 id="gbp-attention-heading" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Needs attention</h2>
                <span class="text-xs text-gray-400">{{ $attentionItems->count() }} items</span>
            </div>
            <ul class="space-y-2">
                @foreach ($attentionItems as $item)
                    <li class="rounded-xl bg-white p-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span @class([
                                        'inline-flex rounded-md px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide',
                                        'bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-400' => $item['severity'] === 'High',
                                        'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400' => $item['severity'] === 'Medium',
                                        'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' => ! in_array($item['severity'], ['High', 'Medium'], true),
                                    ])>{{ $item['severity'] }}</span>
                                    <span class="text-[11px] text-gray-400">{{ $item['summary'] }}</span>
                                </div>
                                <p class="mt-1.5 text-sm font-semibold text-gray-900 dark:text-white">{{ $item['problem'] }}</p>
                                <p class="mt-0.5 text-xs text-gray-500">{{ $item['context'] }}</p>
                            </div>
                            <button type="button" wire:click="openAttention('{{ $item['id'] }}')" class="shrink-0 rounded-lg px-2.5 py-1.5 text-xs font-medium text-brand-600 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:text-brand-400 dark:ring-brand-500/30 dark:hover:bg-brand-500/10">
                                Review →
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800 xl:col-span-3" aria-labelledby="gbp-map-heading">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 id="gbp-map-heading" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Local visibility snapshot</h2>
                    <p class="mt-0.5 text-xs text-gray-400">{{ $snap['keyword'] }} · {{ $snap['scanned_at'] }} · {{ $snap['source'] }}</p>
                </div>
                <button type="button" wire:click="setTab('visibility')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Explore visibility</button>
            </div>
            <div wire:key="gbp-overview-mini-map" class="mt-3">
                <div class="gbp-map-shell gbp-map-mini" data-gbp-rank-map='@json($miniMapPayload)' role="img" aria-label="Mini map of local rank observations for {{ $snap['keyword'] }}"></div>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-xs text-gray-400">Avg observed rank</dt>
                    <dd class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $snap['average_rank'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-400">Top 3 sample points</dt>
                    <dd class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $snap['top3'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-400">Top 10 sample points</dt>
                    <dd class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $snap['top10'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-400">Weakest observed area</dt>
                    <dd class="font-medium text-gray-800 dark:text-white/90">{{ $snap['weakest_area'] }}</dd>
                </div>
            </dl>
            <p class="mt-2 text-[11px] text-gray-400">Observed rank from Demo local-rank sample points — not a Google position claim.</p>
        </section>
    </div>

    {{-- Profile Coverage + Review Pulse + Customer Actions --}}
    <div class="grid gap-4 lg:grid-cols-3">
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Profile coverage</h2>
                <button type="button" wire:click="setTab('profile')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open</button>
            </div>
            <p class="mt-1 text-xs text-gray-500">{{ $coverage['present'] }} / {{ $coverage['total_reviewed'] }} reviewed · {{ $coverage['need_attention'] }} need attention</p>
            <ul class="mt-3 space-y-1 text-sm">
                @foreach ($coverage['groups'] as $group)
                    <li class="flex items-center justify-between gap-2 border-b border-gray-50 py-1 last:border-0 dark:border-gray-800/60">
                        <span class="text-gray-700 dark:text-gray-300">{{ $group['area'] }}</span>
                        <span @class([
                            'text-xs font-medium',
                            'text-emerald-700 dark:text-emerald-400' => $group['state'] === 'Present',
                            'text-amber-700 dark:text-amber-400' => $group['state'] === 'Needs attention',
                            'text-gray-500' => ! in_array($group['state'], ['Present', 'Needs attention'], true),
                        ])>{{ $group['state'] }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Review pulse</h2>
                <button type="button" wire:click="setTab('reviews')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open</button>
            </div>
            <p class="mt-2 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $pulse['rating'] }} ★</p>
            <p class="text-xs text-gray-500">{{ $pulse['total'] }} reviews</p>
            <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">{{ $pulse['new'] }} new · {{ $pulse['unanswered'] }} unanswered · {{ $pulse['attention'] }} need attention</p>
            <ul class="mt-3 space-y-1 text-sm">
                @foreach ($pulse['needs_attention_themes'] as $theme)
                    <li class="flex justify-between gap-2"><span class="text-gray-700 dark:text-gray-300">{{ $theme }}</span><span class="text-xs text-amber-700 dark:text-amber-400">Needs attention</span></li>
                @endforeach
                @foreach (array_slice($pulse['positive'], 0, 2) as $theme)
                    <li class="flex justify-between gap-2"><span class="text-gray-700 dark:text-gray-300">{{ $theme }}</span><span class="text-xs text-emerald-700 dark:text-emerald-400">Positive</span></li>
                @endforeach
            </ul>
            <p class="mt-2 text-[11px] text-gray-400">Topics · {{ $pulse['provenance'] }}</p>
        </section>

        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Customer actions</h2>
                <button type="button" wire:click="setPerfSub('actions')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open Performance</button>
            </div>
            <p class="mt-1 text-xs text-gray-400">{{ $actions['period'] }}</p>
            <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                <div><dt class="text-xs text-gray-400">Search impressions</dt><dd class="font-semibold tabular-nums">{{ number_format($actions['search_impressions']) }}</dd></div>
                <div><dt class="text-xs text-gray-400">Maps impressions</dt><dd class="font-semibold tabular-nums">{{ number_format($actions['maps_impressions']) }}</dd></div>
                <div><dt class="text-xs text-gray-400">Website clicks</dt><dd class="font-semibold tabular-nums">{{ number_format($actions['website_clicks']) }}</dd></div>
                <div><dt class="text-xs text-gray-400">Call clicks</dt><dd class="font-semibold tabular-nums">{{ number_format($actions['call_clicks']) }}</dd></div>
                <div class="col-span-2"><dt class="text-xs text-gray-400">Direction requests</dt><dd class="font-semibold tabular-nums">{{ number_format($actions['direction_requests']) }}</dd></div>
            </dl>
        </section>
    </div>

    {{-- Brand & Website Consistency + Local Opportunities --}}
    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Brand &amp; Website Consistency</h2>
                    <p class="mt-0.5 text-xs text-gray-400">Public Identity Consistency · Brand Context ↔ Website ↔ GBP</p>
                </div>
                    <a href="{{ route('demo.brand', ['brand' => $identity['brand_id'], 'tab' => 'discovery', 'discovery' => 'conflicts']) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                        Review in Public Discovery →
                    </a>
            </div>
            <ul class="mt-3 space-y-1.5 text-sm">
                @foreach ($data['website_consistency'] as $row)
                    <li class="flex items-center justify-between gap-2 border-b border-gray-50 py-1.5 last:border-0 dark:border-gray-800/60">
                        <span class="text-gray-700 dark:text-gray-300">{{ $row['field'] }}</span>
                        <span @class([
                            'text-xs font-medium',
                            'text-emerald-700 dark:text-emerald-400' => $row['state'] === 'Match',
                            'text-rose-700 dark:text-rose-400' => in_array($row['state'], ['Conflict', 'Mismatch'], true),
                            'text-amber-700 dark:text-amber-400' => in_array($row['state'], ['Partial', 'Needs review'], true),
                        ])>{{ $row['state'] }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Local opportunities</h2>
                <button type="button" wire:click="setTab('visibility')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Explore</button>
            </div>
            <ul class="mt-3 space-y-2">
                @foreach ($data['opportunities'] as $opp)
                    <li class="rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-white/[0.03]">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="text-[10px] font-bold uppercase text-gray-500">{{ $opp['priority'] }}</span>
                        </div>
                        <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $opp['title'] }}</p>
                        <p class="mt-1 text-[11px] text-gray-500">
                            Visibility {{ $opp['visibility'] ?? '—' }}
                            · Website {{ $opp['website'] ?? '—' }}
                            · GBP {{ $opp['gbp_service'] ?? '—' }}
                        </p>
                        <button type="button" wire:click="setTab('{{ $opp['tab'] }}')" class="mt-1.5 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Review →</button>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    {{-- Recent Outcomes --}}
    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="flex items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Recent operational outcomes</h2>
            <button type="button" wire:click="setOps('outcomes')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open operations</button>
        </div>
        <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
            @foreach ($data['recent_outcomes'] as $outcome)
                <li class="flex flex-wrap items-start justify-between gap-2 py-2.5">
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $outcome['title'] }}</p>
                        <p class="text-xs text-gray-500">{{ $outcome['chain'] }}</p>
                    </div>
                    <span @class([
                        'shrink-0 text-xs font-semibold',
                        'text-emerald-700 dark:text-emerald-400' => $outcome['outcome'] === 'Improvement observed',
                        'text-amber-700 dark:text-amber-400' => $outcome['outcome'] === 'Still observed',
                        'text-gray-500' => ! in_array($outcome['outcome'], ['Improvement observed', 'Still observed'], true),
                    ])>{{ $outcome['outcome'] }}</span>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- Attention drawer --}}
    @if ($selectedAttention)
        <div class="fixed inset-0 z-50 flex justify-end bg-gray-900/40" role="dialog" aria-modal="true" aria-labelledby="attention-drawer-title" wire:click="closeAttention">
            <div class="flex h-full w-full max-w-lg flex-col overflow-y-auto bg-white shadow-xl dark:bg-gray-900" wire:click.stop>
                <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-400">{{ $selectedAttention['severity'] }} · {{ $selectedAttention['category'] }}</p>
                        <h3 id="attention-drawer-title" class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $selectedAttention['problem'] }}</h3>
                    </div>
                    <button type="button" wire:click="closeAttention" class="rounded-lg px-2 py-1 text-sm text-gray-500 hover:bg-gray-100 dark:hover:bg-white/10" aria-label="Close">✕</button>
                </div>
                <div class="space-y-4 px-5 py-4 text-sm">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-400">What was observed</p>
                        <p class="mt-1 text-gray-700 dark:text-gray-300">{{ $selectedAttention['evidence'] }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Affected scope</p>
                        <p class="mt-1 text-gray-700 dark:text-gray-300">{{ $selectedAttention['context'] }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Why it matters</p>
                        <p class="mt-1 text-gray-700 dark:text-gray-300">{{ $selectedAttention['why'] }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Source</p>
                            <p class="mt-1 text-gray-700 dark:text-gray-300">{{ $selectedAttention['source'] }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Freshness</p>
                            <p class="mt-1 text-gray-700 dark:text-gray-300">{{ $selectedAttention['freshness'] }}</p>
                        </div>
                    </div>
                    @if (! empty($selectedAttention['related_assets']))
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Related Digital Assets</p>
                            <p class="mt-1 text-gray-700 dark:text-gray-300">{{ implode(' · ', $selectedAttention['related_assets']) }}</p>
                        </div>
                    @endif
                    @if (! empty($selectedAttention['brand_context']))
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Brand Context</p>
                            <p class="mt-1 text-gray-700 dark:text-gray-300">{{ $selectedAttention['brand_context'] }}</p>
                        </div>
                    @endif
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Recommendation</p>
                        <p class="mt-1 text-gray-700 dark:text-gray-300">{{ $selectedAttention['recommendation'] ?? $selectedAttention['suggested'] }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Task</p>
                        <p class="mt-1 text-gray-700 dark:text-gray-300">{{ $selectedAttention['task'] ?? 'Not created yet' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Outcome</p>
                        <p class="mt-1 text-gray-700 dark:text-gray-300">{{ $selectedAttention['outcome'] ?? 'Awaiting follow-up' }}</p>
                    </div>
                </div>
                <div class="mt-auto flex flex-wrap gap-2 border-t border-gray-200 px-5 py-4 dark:border-gray-800">
                    @if (! empty($selectedAttention['finding_id']))
                        <button type="button" wire:click="openFinding('{{ $selectedAttention['finding_id'] }}')" class="rounded-lg bg-brand-500 px-3 py-2 text-xs font-medium text-white">Open Finding</button>
                    @endif
                    @if (! empty($selectedAttention['public_discovery_cta']))
                        <a href="{{ route('demo.brand', ['brand' => $identity['brand_id'], 'tab' => 'discovery', 'discovery' => 'conflicts']) }}" wire:navigate class="rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Review in Public Discovery →</a>
                    @endif
                    @if (($selectedAttention['category'] ?? '') === 'Visibility')
                        <button type="button" wire:click="setTab('visibility')" class="rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Open Visibility</button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
