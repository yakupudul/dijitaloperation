@php
    $pw = $data['performance_workspace'];
    $vitalColor = fn (string $rating): string => match ($rating) {
        'good' => 'success',
        'needs_improvement' => 'warning',
        'poor' => 'error',
        default => 'info',
    };
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Performance</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Measured search visibility, Website behaviour and configured business actions.</p>
    </div>

    <div class="inline-flex flex-wrap gap-1 rounded-lg bg-gray-100 p-1 dark:bg-white/[0.04]" role="tablist" aria-label="Performance">
        @foreach ($pw['sub'] as $key => $label)
            <button type="button" wire:click="setPerfSub('{{ $key }}')" @class([
                'rounded-md px-3 py-1.5 text-sm font-medium',
                'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-white' => $perf_sub === $key,
                'text-gray-600 dark:text-gray-400' => $perf_sub !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($perf_sub === 'search')
        <p class="text-xs text-gray-400">Search Console · measured · {{ $data['period_label'] ?? '' }}</p>
        @include('livewire.demo.partials.kpi-strip', ['kpis' => $pw['search']['kpis'] ?? [], 'primaryCount' => 4])
        <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <table class="min-w-full divide-y divide-gray-100 text-sm dark:divide-gray-800">
                <thead class="text-left text-xs uppercase text-gray-400"><tr><th class="px-4 py-3">Query</th><th class="px-4 py-3">Clicks</th><th class="px-4 py-3">Position</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($pw['search']['top_queries'] ?? [] as $row)
                        <tr>
                            <td class="px-4 py-3 font-medium text-gray-800 dark:text-white/90">{{ $row['query'] }}</td>
                            <td class="px-4 py-3 tabular-nums text-gray-500">{{ number_format($row['clicks']) }}</td>
                            <td class="px-4 py-3 tabular-nums text-gray-500">{{ $row['position'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h3 class="font-semibold text-gray-800 dark:text-white/90">FIELD vitals</h3>
                    @include('livewire.demo.partials.provenance-badge', ['label' => 'Field / CrUX-style'])
                </div>
                @foreach ($pw['vitals']['field'] ?? [] as $row)
                    <div class="mb-2 flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.02]">
                        <div>
                            <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['metric'] }}</p>
                            <p class="text-xs text-gray-400">Mobile {{ $row['mobile'] }} · Desktop {{ $row['desktop'] }}</p>
                        </div>
                        <x-ta.badge :color="$vitalColor($row['rating'])" size="sm">{{ str_replace('_', ' ', $row['rating']) }}</x-ta.badge>
                    </div>
                @endforeach
            </section>
            <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h3 class="font-semibold text-gray-800 dark:text-white/90">LAB vitals</h3>
                    @include('livewire.demo.partials.provenance-badge', ['label' => 'Lab / Lighthouse-style'])
                </div>
                @foreach ($pw['vitals']['lab'] ?? [] as $row)
                    <div class="mb-2 flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.02]">
                        <div>
                            <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['metric'] }}</p>
                            <p class="text-xs text-gray-400">Mobile {{ $row['mobile'] }} · Desktop {{ $row['desktop'] }}@if (! empty($row['page'])) · {{ $row['page'] }}@endif</p>
                        </div>
                        <x-ta.badge :color="$vitalColor($row['rating'])" size="sm">{{ str_replace('_', ' ', $row['rating']) }}</x-ta.badge>
                    </div>
                @endforeach
            </section>
        </div>
        <p class="text-xs text-gray-400">Field and lab are never mixed into one score.</p>
    @endif

    @if ($perf_sub === 'acquisition')
        <p class="text-xs text-gray-400">GA4 · measured · {{ $pw['acquisition']['window'] }}</p>
        <div class="grid grid-cols-3 gap-3">
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><p class="text-xs text-gray-500">Sessions</p><p class="mt-1 text-xl font-semibold tabular-nums">{{ number_format($pw['acquisition']['sessions']) }}</p></div>
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><p class="text-xs text-gray-500">Users</p><p class="mt-1 text-xl font-semibold tabular-nums">{{ number_format($pw['acquisition']['users']) }}</p></div>
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><p class="text-xs text-gray-500">Engaged rate</p><p class="mt-1 text-xl font-semibold tabular-nums">{{ $pw['acquisition']['engaged_rate'] }}%</p></div>
        </div>
        <ul class="space-y-2">
            @foreach ($pw['acquisition']['sources'] as $src)
                <li class="flex items-center justify-between rounded-xl bg-white px-4 py-3 text-sm ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <span>{{ $src['source'] }} <span class="text-xs text-gray-400">{{ $src['label'] }}</span></span>
                    <span class="tabular-nums text-gray-500">{{ number_format($src['sessions']) }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($perf_sub === 'landing')
        <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <table class="min-w-full divide-y divide-gray-100 text-sm dark:divide-gray-800">
                <thead class="text-left text-xs uppercase text-gray-400">
                    <tr>
                        <th class="px-4 py-3">Landing page</th>
                        <th class="px-4 py-3">Sessions</th>
                        <th class="px-4 py-3">Engagement</th>
                        <th class="px-4 py-3">Key events</th>
                        <th class="px-4 py-3">Organic clicks</th>
                        <th class="px-4 py-3">Role</th>
                        <th class="px-4 py-3">State</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($pw['landing_pages'] as $row)
                        <tr>
                            <td class="px-4 py-3 font-medium text-gray-800 dark:text-white/90">{{ $row['path'] }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ number_format($row['sessions']) }}</td>
                            <td class="px-4 py-3">{{ $row['engagement'] }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ number_format($row['events']) }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ number_format($row['organic_clicks']) }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $row['role'] }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $row['state'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($perf_sub === 'conversions')
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Conversion mapping</h3>
            <p class="mt-1 text-xs text-gray-400">Operator-configured · not inferred event names</p>
            <ul class="mt-3 space-y-2 text-sm">
                @foreach ($pw['conversion_mapping'] as $row)
                    <li class="flex items-center justify-between gap-3">
                        <span class="text-gray-800 dark:text-white/90">{{ $row['business_action'] }}</span>
                        <span class="text-xs {{ $row['mapped'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400' }}">
                            {{ $row['mapped'] ? $row['ga4_event'] : 'Not mapped' }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Measurement debt</h3>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                @foreach ($pw['measurement_debt'] as $item)<li>{{ $item }}</li>@endforeach
            </ul>
            <p class="mt-3 text-xs text-gray-400">Missing measurement ≠ poor conversion.</p>
            <button type="button" wire:click="setTab('settings')" class="mt-3 text-xs font-medium text-brand-600 hover:underline">Configure measurement</button>
        </section>
    @endif

    @if ($perf_sub === 'outcome')
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Search → page → behaviour</h3>
            @foreach ($pw['search_to_outcome'] as $row)
                <div class="mt-3 text-sm">
                    <p class="font-medium text-gray-800 dark:text-white/90">{{ $row['cluster'] }}</p>
                    <p class="text-xs text-gray-500">Visibility {{ $row['visibility'] }} · Landing {{ $row['landing'] }} · Engagement {{ $row['engagement'] }}</p>
                    <p class="mt-1 text-gray-600 dark:text-gray-300">{{ $row['actions'] }}</p>
                    <p class="mt-2 text-xs text-amber-700 dark:text-amber-400">{{ $row['disclaimer'] }}</p>
                </div>
            @endforeach
        </section>
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Change impact</h3>
            @foreach ($pw['change_impact'] as $row)
                <div class="mt-3 text-sm">
                    <p class="font-medium text-gray-800 dark:text-white/90">{{ $row['change'] }} · {{ $row['when'] }}</p>
                    <p class="text-xs text-gray-500">{{ $row['window'] }}</p>
                    <p class="mt-1 text-gray-600 dark:text-gray-300">Impressions {{ $row['impressions'] }} · Clicks {{ $row['clicks'] }} · Configured key events {{ $row['events'] }}</p>
                    <p class="mt-1 text-xs font-medium text-emerald-700 dark:text-emerald-400">{{ $row['outcome'] }}</p>
                    <p class="text-xs text-gray-400">{{ $row['label'] }}</p>
                </div>
            @endforeach
        </section>
    @endif
</div>
