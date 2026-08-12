@php
    $vis = $data['visibility'];
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Visibility</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">How the Website is discovered across search, local and AI-assisted experiences.</p>
    </div>

    <div class="inline-flex flex-wrap gap-1 rounded-lg bg-gray-100 p-1 dark:bg-white/[0.04]" role="tablist" aria-label="Visibility lenses">
        @foreach ($vis['lenses'] as $key => $label)
            <button type="button" wire:click="setVisLens('{{ $key }}')" @class([
                'rounded-md px-3 py-1.5 text-sm font-medium',
                'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-white' => $vis_lens === $key,
                'text-gray-600 dark:text-gray-400' => $vis_lens !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($vis_lens === 'organic')
        @php $organic = $vis['organic']; @endphp
        <p class="text-xs text-gray-400">{{ $organic['source'] }} · {{ $organic['window'] }}</p>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><p class="text-xs text-gray-500">Clicks</p><p class="mt-1 text-xl font-semibold tabular-nums">{{ number_format($organic['kpis']['clicks']) }}</p></div>
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><p class="text-xs text-gray-500">Impressions</p><p class="mt-1 text-xl font-semibold tabular-nums">{{ number_format($organic['kpis']['impressions']) }}</p></div>
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><p class="text-xs text-gray-500">CTR</p><p class="mt-1 text-xl font-semibold tabular-nums">{{ $organic['kpis']['ctr'] }}%</p></div>
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><p class="text-xs text-gray-500">Avg position</p><p class="mt-1 text-xl font-semibold tabular-nums">{{ $organic['kpis']['avg_position'] }}</p></div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            @foreach (['growing' => 'Growing', 'declining' => 'Declining', 'striking_distance' => 'Striking distance (MoxDOP heuristic)', 'high_impression_low_ctr' => 'High impression / low CTR'] as $key => $label)
                <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $label }}</h3>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($organic['groups'][$key] as $row)
                            <li>
                                <p class="font-medium text-gray-800 dark:text-white/90">{{ $row['query'] }}</p>
                                <p class="text-xs text-gray-500">
                                    @if (isset($row['delta'])) {{ $row['delta'] }} · @endif
                                    @if (isset($row['position'])) Pos {{ $row['position'] }} · @endif
                                    @if (isset($row['impressions'])) {{ number_format($row['impressions']) }} impr · @endif
                                    @if (isset($row['ctr'])) CTR {{ $row['ctr'] }}% · @endif
                                    @if (isset($row['page'])) {{ $row['page'] }} @endif
                                    @if (isset($row['note'])) · {{ $row['note'] }} @endif
                                </p>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>

        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Query → page</h3>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100 text-sm dark:divide-gray-800">
                    <thead class="text-left text-xs uppercase text-gray-400">
                        <tr>
                            <th class="py-2 pr-4">Query</th>
                            <th class="py-2 pr-4">Primary page</th>
                            <th class="py-2 pr-4">Impr</th>
                            <th class="py-2 pr-4">Clicks</th>
                            <th class="py-2 pr-4">Avg pos</th>
                            <th class="py-2">Overlap</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($organic['query_pages'] as $qp)
                            <tr>
                                <td class="py-2 pr-4 font-medium text-gray-800 dark:text-white/90">{{ $qp['query'] }}</td>
                                <td class="py-2 pr-4 text-gray-500">{{ $qp['primary_page'] }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ number_format($qp['impressions']) }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ number_format($qp['clicks']) }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ $qp['avg_position'] }}</td>
                                <td class="py-2 text-xs text-gray-500">
                                    @if ($qp['overlap_label'])
                                        {{ $qp['overlap_label'] }} · {{ $qp['competing_pages'] }} pages · {{ $qp['confidence'] }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">DataForSEO</h3>
            <p class="mt-1 text-xs text-gray-400">{{ $organic['dataforseo']['label'] }}</p>
            <p class="mt-3 text-sm text-gray-700 dark:text-gray-300">
                Ranked keywords {{ number_format($organic['dataforseo']['ranked_keywords']) }}
                · Keywords for site {{ number_format($organic['dataforseo']['keywords_for_site']) }}
                · Opportunities {{ $organic['dataforseo']['opportunities'] }}
            </p>
            <p class="mt-2 text-xs text-amber-700 dark:text-amber-400">{{ $organic['dataforseo']['guard'] }}</p>
        </section>
    @endif

    @if ($vis_lens === 'local')
        @php $local = $vis['local']; @endphp
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $local['reason'] }}</p>
        <p class="text-xs text-gray-400">These are controllable Website/Brand signals relevant to local discoverability — not a ranking promise.</p>
        <ul class="space-y-2">
            @foreach ($local['signals'] as $signal)
                <li class="flex items-center justify-between rounded-xl bg-white px-4 py-3 text-sm ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <span class="text-gray-800 dark:text-white/90">{{ $signal['label'] }}</span>
                    <span class="text-xs text-gray-500">{{ $signal['state'] }}</span>
                </li>
            @endforeach
        </ul>
        <section class="overflow-x-auto rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Local service coverage</h3>
            <table class="mt-3 min-w-full text-sm">
                <thead class="text-left text-xs uppercase text-gray-400">
                    <tr>@foreach ($local['matrix']['headers'] as $h)<th class="py-2 pr-4">{{ $h }}</th>@endforeach</tr>
                </thead>
                <tbody>
                    @foreach ($local['matrix']['rows'] as $row)
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            @foreach ($row as $cell)<td class="py-2 pr-4 text-gray-700 dark:text-gray-300">{{ $cell }}</td>@endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="mt-2 text-xs text-gray-400">{{ $local['matrix']['note'] }}</p>
        </section>
        <a href="{{ route($local['gbp']['route']) }}" wire:navigate class="inline-flex rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">
            Open related GBP · {{ $local['gbp']['asset_name'] }}
        </a>
        <p class="text-xs text-gray-400">{{ $local['gbp']['note'] }}</p>
    @endif

    @if ($vis_lens === 'ai')
        @php $ai = $vis['ai']; @endphp
        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">AI readiness</h3>
                <p class="mt-1 text-xs text-gray-400">{{ $ai['readiness_note'] }}</p>
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($ai['readiness'] as $row)
                        <li class="flex justify-between gap-3"><span class="text-gray-700 dark:text-gray-300">{{ $row['condition'] }}</span><span class="text-xs text-gray-500">{{ $row['state'] }}</span></li>
                    @endforeach
                </ul>
            </section>
            <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Observed AI visibility</h3>
                <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">{{ $ai['observed']['sample_note'] }}</p>
                @foreach ($ai['observed']['demo_rows'] as $row)
                    <div class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/[0.03]">
                        <p class="font-medium text-gray-800 dark:text-white/90">{{ $row['platform'] }} · {{ $row['query'] }}</p>
                        <p class="text-xs text-gray-500">Mentioned: {{ $row['mentioned'] ? 'Yes' : 'No' }} · Recommended: {{ $row['recommended'] ? 'Yes' : 'No' }} · Cited: {{ $row['cited'] ? 'Yes' : 'No' }}</p>
                        <p class="text-xs text-gray-400">{{ $row['when'] }}</p>
                    </div>
                @endforeach
            </section>
        </div>
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $ai['referrals']['label'] }}</h3>
            <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">{{ number_format($ai['referrals']['sessions']) }} sessions · {{ $ai['referrals']['source'] }}</p>
            <p class="mt-1 text-xs text-gray-400">{{ $ai['referrals']['note'] }}</p>
        </section>
        <p class="text-sm text-gray-500">{{ $ai['generative_search']['message'] }}</p>
        <p class="text-xs text-gray-400">Competitors: {{ $vis['competitors']['capability'] }} · Known: {{ implode(', ', $vis['competitors']['known']) }}</p>
    @endif
</div>
