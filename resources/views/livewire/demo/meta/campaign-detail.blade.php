@php
    $ctx = $campaign['context'] ?? [];
    $sections = [
        'overview' => 'Overview',
        'strategy' => 'Strategy',
        'adsets' => 'Ad Sets',
        'creatives' => 'Creatives',
        'delivery' => 'Delivery',
        'destination' => 'Destination',
        'diagnostics' => 'Diagnostics',
        'history' => 'Decision history',
    ];
@endphp

<div class="space-y-4">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-col gap-3 border-b border-gray-200 pb-4 dark:border-gray-800 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Meta Ads · Campaign</p>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $campaign['name'] }}</h1>
                @include('livewire.demo.partials.demo-badge')
                <x-ta.badge :color="$campaign['status'] === 'ACTIVE' ? 'success' : 'light'" size="sm">{{ $campaign['status'] }}</x-ta.badge>
            </div>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $campaign['offering'] }} · {{ $campaign['market'] }} · {{ $campaign['language'] }} · {{ $campaign['goal'] }}</p>
            <p class="mt-1 text-xs text-gray-500">{{ $campaign['destination'] }} · {{ $campaign['result_label'] }} · {{ $campaign['period_label'] }}</p>
        </div>
        <a href="{{ route('demo.meta.overview', ['assetId' => $assetId, 'tab' => 'campaigns']) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">← Campaign portfolio</a>
    </div>

    @include('livewire.demo.partials.meta-asset-nav', ['assetId' => $assetId, 'active' => 'campaigns'])
    @include('livewire.demo.partials.period-bar')

    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        @foreach ($campaign['kpis'] as $kpi)
            <x-ta.metric-card
                :label="$kpi['label']"
                :value="match ($kpi['format'] ?? 'int') {
                    'try' => '₺'.number_format((float) $kpi['value']),
                    'pct' => $kpi['value'].'%',
                    'float' => (string) $kpi['value'],
                    default => number_format((float) $kpi['value']),
                }"
            />
        @endforeach
    </div>

    <div class="flex flex-wrap gap-1 border-b border-gray-200 dark:border-gray-800" role="tablist">
        @foreach ($sections as $key => $label)
            <button type="button" wire:click="setSection('{{ $key }}')" @class([
                'rounded-t-lg px-3 py-2 text-sm font-medium',
                'border-b-2 border-brand-500 text-brand-600 dark:text-brand-400' => $section === $key,
                'border-b-2 border-transparent text-gray-500' => $section !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($section === 'overview')
        <div class="grid gap-3 lg:grid-cols-2">
            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Delivery snapshot</h2>
                <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                    <div><dt class="text-xs text-gray-400">Spend</dt><dd class="font-semibold tabular-nums">₺{{ number_format($campaign['spend']) }}</dd></div>
                    <div><dt class="text-xs text-gray-400">{{ $campaign['result_label'] }}</dt><dd class="font-semibold tabular-nums">{{ number_format($campaign['results']) }}</dd></div>
                    <div><dt class="text-xs text-gray-400">Frequency</dt><dd class="font-semibold tabular-nums">{{ $campaign['frequency'] }}</dd></div>
                    <div><dt class="text-xs text-gray-400">Link CTR</dt><dd class="font-semibold tabular-nums">{{ $campaign['ctr'] }}%</dd></div>
                </dl>
            </section>
            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Destination / funnel</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-500">Destination</dt><dd class="font-medium">{{ $campaign['funnel']['destination'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Optimization</dt><dd class="font-medium">{{ $campaign['funnel']['optimization'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Platform result</dt><dd class="font-medium">{{ $campaign['funnel']['result_label'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Cost / result</dt><dd class="font-medium tabular-nums">₺{{ number_format($campaign['funnel']['cost_result'] ?? 0) }}</dd></div>
                </dl>
            </section>
        </div>
    @elseif ($section === 'strategy')
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Meta Campaign Context</h2>
            <p class="mt-1 text-[11px] text-violet-700 dark:text-violet-300">Operator-maintained · does not mutate Meta Ads</p>
            <dl class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 text-sm">
                @foreach ([
                    'Offering' => $ctx['offering'] ?? $campaign['offering'],
                    'Market' => $ctx['market'] ?? $campaign['market'],
                    'Language' => $ctx['language'] ?? $campaign['language'],
                    'Audience strategy' => $ctx['audience_strategy'] ?? '—',
                    'Funnel stage' => $ctx['funnel_stage'] ?? $campaign['goal'],
                    'Destination' => $ctx['destination'] ?? $campaign['destination'],
                    'Platform result' => $ctx['platform_result'] ?? $campaign['result_label'],
                    'Desired business outcome' => $ctx['desired_business_outcome'] ?? '—',
                    'Planned budget' => isset($ctx['planned_budget']) ? '₺'.number_format($ctx['planned_budget']) : '—',
                ] as $label => $value)
                    <div>
                        <dt class="text-xs text-gray-400">{{ $label }}</dt>
                        <dd class="font-medium text-gray-900 dark:text-white">{{ is_array($value) ? implode(', ', $value) : $value }}</dd>
                    </div>
                @endforeach
                <div class="sm:col-span-2 lg:col-span-3">
                    <dt class="text-xs text-gray-400">Creative strategy</dt>
                    <dd class="mt-1 flex flex-wrap gap-1.5">
                        @foreach (($ctx['creative_strategy'] ?? []) as $angle)
                            <x-ta.badge color="info" size="sm">{{ $angle }}</x-ta.badge>
                        @endforeach
                    </dd>
                </div>
            </dl>
        </section>
    @elseif ($section === 'adsets')
        <x-ta.table>
            <x-slot:head>
                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Ad set</th>
                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Status</th>
                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Spend</th>
                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Result</th>
                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">CTR</th>
            </x-slot:head>
            @foreach ($campaign['adsets'] as $adset)
                <tr>
                    <td class="px-3 py-2 text-sm font-medium text-gray-900 dark:text-white">{{ $adset['name'] }}</td>
                    <td class="px-3 py-2"><x-ta.badge color="success" size="sm">{{ $adset['status'] ?? 'ACTIVE' }}</x-ta.badge></td>
                    <td class="px-3 py-2 text-sm tabular-nums">₺{{ number_format($adset['spend']) }}</td>
                    <td class="px-3 py-2 text-sm tabular-nums">{{ number_format($adset['results']) }} <span class="text-[11px] text-gray-400">{{ $adset['result_label'] ?? $campaign['result_label'] }}</span></td>
                    <td class="px-3 py-2 text-sm tabular-nums">{{ $adset['ctr'] }}%</td>
                </tr>
            @endforeach
        </x-ta.table>
    @elseif ($section === 'creatives')
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @forelse ($campaign['creatives'] as $cr)
                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                    <x-demo.meta-creative-thumb :gradient="$cr['thumb'] ?? $cr['thumb_gradient'] ?? 'slate'" :name="$cr['name']" class="h-28 !aspect-auto" />
                    <div class="space-y-1 p-3">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $cr['name'] }}</p>
                        <p class="text-[11px] text-gray-400">{{ $cr['format'] }} · {{ $cr['angle'] ?? '' }}</p>
                        <p class="text-xs tabular-nums">₺{{ number_format($cr['spend']) }} · {{ number_format($cr['result'] ?? $cr['results'] ?? 0) }} {{ $cr['result_label'] }}</p>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">No creatives mapped to this campaign in the selected period.</p>
            @endforelse
        </div>
    @elseif ($section === 'delivery')
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Observed delivery</h2>
            <p class="mt-1 text-xs text-gray-500">Configured vs observed — descriptive only</p>
            @php $aud = $campaign['audience'] ?? []; @endphp
            <ul class="mt-3 space-y-2 text-sm">
                @foreach (($aud['placements'] ?? $aud['observed']['placement'] ?? []) as $row)
                    <li class="flex justify-between gap-2">
                        <span>{{ $row['label'] }}</span>
                        <span class="tabular-nums text-gray-500">{{ $row['share'] ?? '' }}%@if(isset($row['spend'])) · ₺{{ number_format($row['spend']) }}@endif</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @elseif ($section === 'destination')
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Destination</h2>
            <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">{{ $campaign['destination'] }} · {{ $campaign['result_label'] }}</p>
            @if ($campaign['destination'] === 'Website')
                <a href="{{ route('demo.website', ['assetId' => $identity['website_asset_id']]) }}" wire:navigate class="mt-3 inline-block text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open Website asset →</a>
            @endif
        </section>
    @elseif ($section === 'diagnostics')
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Diagnostics</h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                @if (! empty($campaign['attention_primary']))
                    Attention: {{ $campaign['attention_primary'] }}
                @else
                    No campaign-level attention signal in this period.
                @endif
            </p>
            <p class="mt-2 text-xs text-gray-400">No fake health/fatigue scores. Open Operations for Findings.</p>
        </section>
    @else
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Decision history</h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Findings → Recommendations → Tasks → Outcomes live in Operations. No automatic Meta write.</p>
            <a href="{{ route('demo.meta.overview', ['assetId' => $assetId, 'tab' => 'operations']) }}" wire:navigate class="mt-3 inline-block text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open Operations →</a>
        </section>
    @endif

    <p class="text-xs text-gray-400">{{ $campaign['demo_boundary'] }}</p>
</div>
