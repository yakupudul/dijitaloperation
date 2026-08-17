@php
    $glance = $data['glance'];
    $freshness = $data['source_freshness'];
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
        @foreach ($freshness as $row)
            <button type="button" wire:click="setTab('{{ $row['tab'] }}')" class="hover:text-gray-800 dark:hover:text-white/90">
                <span class="font-medium text-gray-700 dark:text-gray-300">{{ $row['source'] }}</span>
                {{ $row['label'] }}
            </button>
        @endforeach
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <button type="button" wire:click="setTab('health')" class="rounded-xl bg-white p-4 text-left ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-800 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
            <p class="text-xs text-gray-500">Open findings</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $glance['open_findings'] }}</p>
            <p class="mt-1 text-xs text-gray-400">{{ $glance['high_findings'] }} high priority</p>
        </button>
        <button type="button" wire:click="setTab('activity')" class="rounded-xl bg-white p-4 text-left ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-800 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
            <p class="text-xs text-gray-500">Active tasks</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $glance['active_tasks'] }}</p>
            <p class="mt-1 text-xs text-error-600 dark:text-error-400">{{ $glance['overdue_tasks'] }} overdue</p>
        </button>
        <button type="button" wire:click="setVisLens('organic')" class="rounded-xl bg-white p-4 text-left ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-800 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
            <p class="text-xs text-gray-500">Search visibility</p>
            <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $glance['search_visibility']['value'] }}</p>
            <p class="mt-1 text-xs text-gray-400">{{ $glance['search_visibility']['secondary'] }}</p>
        </button>
        <button type="button" wire:click="setTab('content')" class="rounded-xl bg-white p-4 text-left ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-800 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
            <p class="text-xs text-gray-500">Site inventory</p>
            <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $glance['site_inventory']['value'] }}</p>
            <p class="mt-1 text-xs text-gray-400">{{ $glance['site_inventory']['secondary'] }}</p>
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
                            <p class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">{{ $item['what'] }}</p>
                            <p class="mt-1 text-xs text-gray-500">{{ $item['where'] }}@if (! empty($item['affected_scope'])) · {{ $item['affected_scope'] }}@endif</p>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $item['why'] }}</p>
                            <p class="mt-1 text-xs font-medium text-gray-700 dark:text-gray-200">{{ $item['recommended'] }}</p>
                        </div>
                        @if (! empty($item['finding_id']))
                            <button type="button" wire:click="openFinding('{{ $item['finding_id'] }}')" class="shrink-0 rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Review finding</button>
                        @else
                            <button type="button" wire:click="setTab('content')" class="shrink-0 rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Review opportunity</button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    <section>
        <div class="flex items-center justify-between gap-2">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Opportunities</h2>
            <button type="button" wire:click="setTab('visibility')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">View opportunities</button>
        </div>
        <ul class="mt-3 space-y-2">
            @foreach ($data['opportunities'] as $opp)
                <li class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="text-xs text-gray-400">{{ $opp['priority'] }} · {{ $opp['source'] }}</p>
                            <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $opp['title'] }}</p>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $opp['why'] }}</p>
                        </div>
                        <button type="button" wire:click="setTab('{{ $opp['tab'] }}')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $opp['action'] }}</button>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Site inventory</h2>
            <p class="mt-1 text-xs text-gray-400">{{ $data['inventory_snapshot']['label'] }}</p>
            <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                <div><dt class="text-xs text-gray-400">Pages</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['inventory_snapshot']['pages'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Posts</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['inventory_snapshot']['posts'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Custom types</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['inventory_snapshot']['custom_types'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Media</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['inventory_snapshot']['media'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Sitemap URLs</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['inventory_snapshot']['sitemap_urls'] }}</dd></div>
            </dl>
            <p class="mt-3 text-xs text-gray-500">{{ $data['inventory_snapshot']['reconciliation']['note'] }}</p>
        </section>

        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Search &amp; demand</h2>
            <p class="mt-1 text-xs text-gray-400">{{ $data['search_snapshot']['gsc_label'] }} · {{ $data['search_snapshot']['window'] }}</p>
            <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                <div><dt class="text-xs text-gray-400">Organic clicks</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ number_format($data['search_snapshot']['clicks']) }}</dd></div>
                <div><dt class="text-xs text-gray-400">Impressions</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ number_format($data['search_snapshot']['impressions']) }}</dd></div>
                <div><dt class="text-xs text-gray-400">Striking distance</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['search_snapshot']['striking_distance'] }} · heuristic</dd></div>
                <div><dt class="text-xs text-gray-400">DataForSEO opps</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['search_snapshot']['dataforseo_opportunities'] }} · estimated</dd></div>
            </dl>
        </section>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">Conversion snapshot</h2>
                <button type="button" wire:click="setTab('settings')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Configure measurement</button>
            </div>
            <ul class="mt-3 space-y-2 text-sm">
                @foreach ($data['conversion_snapshot']['mapped'] as $row)
                    <li class="flex justify-between gap-3"><span class="text-gray-700 dark:text-gray-300">{{ $row['action'] }}</span><span class="tabular-nums text-gray-500">{{ number_format($row['count']) }}</span></li>
                @endforeach
            </ul>
            @foreach ($data['conversion_snapshot']['gaps'] as $gap)
                <p class="mt-3 text-xs text-amber-700 dark:text-amber-400">{{ $gap }}</p>
            @endforeach
        </section>

        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Recent outcomes</h2>
            <ul class="mt-3 space-y-3">
                @foreach ($data['recent_outcomes'] as $outcome)
                    <li class="text-sm">
                        <p class="font-medium text-gray-800 dark:text-white/90">{{ $outcome['title'] }}</p>
                        <p class="text-xs text-gray-500">{{ $outcome['task'] }}</p>
                        <p class="text-xs text-gray-500">{{ $outcome['follow_up'] }}</p>
                        <p class="mt-1 text-xs font-medium text-emerald-700 dark:text-emerald-400">{{ $outcome['state'] }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">AI guidance</h2>
        <p class="mt-1 text-xs text-gray-400">Derived interpretation · not a Finding source</p>
        <h3 class="mt-3 text-xs font-semibold uppercase tracking-wide text-gray-400">What matters most</h3>
        <ul class="mt-1 list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
            @foreach ($data['ai_guidance']['what_matters'] as $point)
                <li>{{ $point }}</li>
            @endforeach
        </ul>
        <h3 class="mt-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Suggested next step</h3>
        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $data['ai_guidance']['next_step'] }}</p>
        <h3 class="mt-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Based on</h3>
        <p class="mt-1 text-xs text-gray-500">{{ implode(' · ', $data['ai_guidance']['evidence']) }}</p>
        <p class="mt-3 text-xs text-gray-400">{{ $data['ai_guidance']['disclaimer'] }}</p>
    </section>

    @include('livewire.demo.partials._opportunity-card', ['opportunity' => null])
</div>
