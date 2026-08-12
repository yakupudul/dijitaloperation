@php
    $glance = $data['glance'];
    $coverage = $data['profile_coverage'];
    $pulse = $data['review_pulse'];
    $actions = $data['customer_actions'];
    $snap = $data['visibility_snapshot'];
@endphp

<div class="space-y-6">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <button type="button" wire:click="setTab('profile')" class="rounded-xl bg-white p-4 text-left ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-800 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
            <p class="text-xs text-gray-500">Profile</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $glance['profile']['value'] }}</p>
            <p class="mt-1 text-xs text-gray-400">{{ $glance['profile']['label'] }}</p>
        </button>
        <button type="button" wire:click="setTab('visibility')" class="rounded-xl bg-white p-4 text-left ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-800 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
            <p class="text-xs text-gray-500">Local visibility</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $glance['visibility']['value'] }}</p>
            <p class="mt-1 text-xs text-gray-400">{{ $glance['visibility']['label'] }}</p>
        </button>
        <button type="button" wire:click="setTab('reviews')" class="rounded-xl bg-white p-4 text-left ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-800 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
            <p class="text-xs text-gray-500">Reviews</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $glance['reviews']['value'] }}</p>
            <p class="mt-1 text-xs text-gray-400">{{ $glance['reviews']['label'] }}</p>
        </button>
        <button type="button" wire:click="setPerfSub('actions')" class="rounded-xl bg-white p-4 text-left ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-800 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
            <p class="text-xs text-gray-500">Customer actions</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $glance['actions']['value'] }}</p>
            <p class="mt-1 text-xs text-gray-400">{{ $glance['actions']['label'] }}</p>
        </button>
    </div>

    <section>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Needs attention</h2>
        <ul class="mt-3 space-y-2">
            @foreach ($data['needs_attention'] as $item)
                <li class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span @class([
                                    'inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase',
                                    'bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-400' => $item['severity'] === 'High',
                                    'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400' => $item['severity'] === 'Medium',
                                    'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' => ! in_array($item['severity'], ['High', 'Medium'], true),
                                ])>{{ $item['severity'] }}</span>
                                <span class="text-xs text-gray-400">{{ $item['category'] }}</span>
                                <span class="text-xs text-gray-500">{{ $item['actionability'] }}</span>
                            </div>
                            <p class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">{{ $item['problem'] }}</p>
                            <p class="mt-1 text-xs text-gray-500">{{ $item['context'] }}</p>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $item['why'] }}</p>
                            <p class="mt-1 text-xs text-gray-400">Evidence · {{ $item['evidence'] }}</p>
                            <p class="mt-1 text-xs font-medium text-gray-700 dark:text-gray-200">{{ $item['suggested'] }}</p>
                        </div>
                        @if (! empty($item['finding_id']))
                            <button type="button" wire:click="openFinding('{{ $item['finding_id'] }}')" class="shrink-0 rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Review finding</button>
                        @elseif (($item['category'] ?? '') === 'Visibility')
                            <button type="button" wire:click="setTab('visibility')" class="shrink-0 rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Open visibility map</button>
                        @else
                            <button type="button" wire:click="setTab('profile')" class="shrink-0 rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Review</button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    <div class="grid gap-4 lg:grid-cols-5">
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 lg:col-span-3">
            <div class="flex items-center justify-between gap-2">
                <div>
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">Local visibility snapshot</h2>
                    <p class="mt-0.5 text-xs text-gray-400">{{ $snap['keyword'] }} · {{ $snap['scanned_at'] }} · {{ $snap['source'] }}</p>
                </div>
                <button type="button" wire:click="setTab('visibility')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Explore visibility</button>
            </div>
            <div wire:key="gbp-overview-mini-map">
                <div class="gbp-map-shell gbp-map-mini mt-3" data-gbp-rank-map='@json($miniMapPayload)' role="img" aria-label="Mini map of local rank observations"></div>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                <div><dt class="text-xs text-gray-400">Avg observed rank</dt><dd class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $snap['average_rank'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Top 3</dt><dd class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $snap['top3'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Top 10</dt><dd class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $snap['top10'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Weakest area</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $snap['weakest_area'] }}</dd></div>
            </dl>
        </section>

        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 lg:col-span-2">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Profile coverage</h2>
            <p class="mt-1 text-sm text-gray-700 dark:text-gray-200">{{ $coverage['present'] }} of {{ $coverage['total_reviewed'] }} reviewed fields present</p>
            <p class="mt-0.5 text-xs text-gray-400">{{ $coverage['need_attention'] }} need attention · {{ $coverage['unavailable'] }} unavailable</p>
            <p class="mt-2 text-xs text-gray-500">{{ $coverage['note'] }}</p>
            <ul class="mt-3 space-y-1.5 text-sm">
                @foreach ($coverage['groups'] as $group)
                    <li class="flex items-center justify-between gap-2">
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
            <button type="button" wire:click="setTab('profile')" class="mt-3 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Review profile</button>
        </section>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Customer interactions</h2>
            <p class="mt-1 text-xs text-gray-400">{{ $actions['period'] }}</p>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-xs text-gray-400">Search impressions</dt><dd class="font-semibold tabular-nums">{{ number_format($actions['search_impressions']) }}</dd></div>
                <div><dt class="text-xs text-gray-400">Maps impressions</dt><dd class="font-semibold tabular-nums">{{ number_format($actions['maps_impressions']) }}</dd></div>
                <div><dt class="text-xs text-gray-400">Website clicks</dt><dd class="font-semibold tabular-nums">{{ number_format($actions['website_clicks']) }}</dd></div>
                <div><dt class="text-xs text-gray-400">Call clicks</dt><dd class="font-semibold tabular-nums">{{ number_format($actions['call_clicks']) }}</dd></div>
                <div><dt class="text-xs text-gray-400">Direction requests</dt><dd class="font-semibold tabular-nums">{{ number_format($actions['direction_requests']) }}</dd></div>
            </dl>
            <button type="button" wire:click="setPerfSub('discovery')" class="mt-3 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open performance</button>
        </section>

        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Review pulse</h2>
            <p class="mt-1 text-xs text-gray-400">{{ $pulse['provenance'] }}</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $pulse['rating'] }} ★ <span class="text-sm font-normal text-gray-500">{{ $pulse['total'] }} reviews</span></p>
            <p class="mt-1 text-xs text-gray-500">{{ $pulse['new'] }} new · {{ $pulse['unanswered'] }} unanswered · {{ $pulse['attention'] }} need attention</p>
            <div class="mt-3 grid grid-cols-2 gap-3 text-sm">
                <div>
                    <p class="text-xs font-medium text-emerald-700 dark:text-emerald-400">Positive themes</p>
                    <ul class="mt-1 space-y-0.5 text-gray-700 dark:text-gray-300">
                        @foreach ($pulse['positive'] as $t)<li>{{ $t }}</li>@endforeach
                    </ul>
                </div>
                <div>
                    <p class="text-xs font-medium text-rose-700 dark:text-rose-400">Needs attention</p>
                    <ul class="mt-1 space-y-0.5 text-gray-700 dark:text-gray-300">
                        @foreach ($pulse['needs_attention_themes'] as $t)<li>{{ $t }}</li>@endforeach
                    </ul>
                </div>
            </div>
            <button type="button" wire:click="setTab('reviews')" class="mt-3 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open reviews</button>
        </section>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">Website consistency</h2>
                <button type="button" wire:click="setTab('profile')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Review consistency</button>
            </div>
            <p class="mt-1 text-xs text-gray-400">Sibling Digital Assets · Website ↔ GBP · Checked 5h ago</p>
            <ul class="mt-3 space-y-1.5 text-sm">
                @foreach ($data['website_consistency'] as $row)
                    <li class="flex items-center justify-between gap-2">
                        <span class="text-gray-700 dark:text-gray-300">{{ $row['field'] }}</span>
                        <span @class([
                            'text-xs font-medium',
                            'text-emerald-700 dark:text-emerald-400' => $row['state'] === 'Match' || str_starts_with($row['state'], 'Matched'),
                            'text-rose-700 dark:text-rose-400' => $row['state'] === 'Mismatch',
                            'text-amber-700 dark:text-amber-400' => in_array($row['state'], ['Partial', 'Needs review'], true) || str_contains($row['state'], 'Partial'),
                        ])>{{ $row['state'] }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">Local opportunities</h2>
                <button type="button" wire:click="setTab('visibility')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Explore opportunities</button>
            </div>
            <ul class="mt-3 space-y-2">
                @foreach ($data['opportunities'] as $opp)
                    <li class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                        <p class="text-xs text-gray-400">{{ $opp['priority'] }} · {{ $opp['evidence'] }}</p>
                        <p class="mt-0.5 text-sm font-semibold text-gray-900 dark:text-white">{{ $opp['title'] }}</p>
                        <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-300">{{ $opp['why'] }}</p>
                        <button type="button" wire:click="setTab('{{ $opp['tab'] }}')" class="mt-1 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Review opportunity</button>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <div class="flex items-center justify-between gap-2">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Recent operational outcomes</h2>
            <button type="button" wire:click="setOps('outcomes')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open operations</button>
        </div>
        <ul class="mt-3 space-y-2">
            @foreach ($data['recent_outcomes'] as $outcome)
                <li class="flex flex-col gap-1 border-b border-gray-100 pb-2 last:border-0 dark:border-gray-700 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $outcome['title'] }}</p>
                        <p class="text-xs text-gray-500">{{ $outcome['chain'] }}</p>
                        <p class="text-xs text-gray-600 dark:text-gray-300">{{ $outcome['note'] }}</p>
                    </div>
                    <span @class([
                        'shrink-0 text-xs font-semibold',
                        'text-emerald-700 dark:text-emerald-400' => $outcome['outcome'] === 'Improvement observed',
                        'text-amber-700 dark:text-amber-400' => $outcome['outcome'] === 'Still observed',
                    ])>{{ $outcome['outcome'] }}</span>
                </li>
            @endforeach
        </ul>
    </section>
</div>
