@php
    $campMeta = $data['campaigns_tab'] ?? [];
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Campaigns</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $campMeta['subtitle'] ?? 'Delivered in period · Campaign Context — not Ads Manager.' }}</p>
    </div>

    <div class="flex flex-wrap gap-2">
        <label class="text-xs text-gray-500">Attention
            <select wire:model.live="campaign_filter" class="mt-1 block rounded-lg border-gray-200 text-sm dark:border-gray-700 dark:bg-gray-900">
                <option value="all">All campaigns</option>
                <option value="attention">With attention</option>
                <option value="budget">Budget pacing issues</option>
                <option value="delivered">Delivered in period</option>
            </select>
        </label>
        @if (! empty($campMeta['filters']))
            @foreach ($campMeta['filters'] as $filter)
                <label class="text-xs text-gray-500">{{ $filter['label'] }}
                    <select wire:change="setCampaignFilter('{{ $filter['key'] }}', $event.target.value)" class="mt-1 block rounded-lg border-gray-200 text-sm dark:border-gray-700 dark:bg-gray-900">
                        @foreach ($filter['options'] as $opt)
                            <option value="{{ $opt['value'] }}" @selected(($filter['value'] ?? 'all') === $opt['value'])>{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                </label>
            @endforeach
        @endif
    </div>

    <x-ta.table>
        <x-slot:head>
            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Campaign</th>
            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Status</th>
            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Offering</th>
            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Market</th>
            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Funnel</th>
            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Destination</th>
            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Spend</th>
            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Result</th>
            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Cost / result</th>
            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Pacing</th>
            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Attention</th>
            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400"></th>
        </x-slot:head>
        @foreach ($campaignRows as $c)
            <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                <td class="px-3 py-2">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $c['name'] }}</p>
                    <p class="text-[11px] text-gray-400">{{ $c['objective'] ?? $c['type'] ?? '' }}</p>
                </td>
                <td class="px-3 py-2"><x-ta.badge color="success" size="sm">{{ $c['status'] }}</x-ta.badge></td>
                <td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-300">{{ $c['offering'] }}</td>
                <td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-300">{{ $c['market'] === 'United Kingdom' ? 'UK' : $c['market'] }}</td>
                <td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-300">{{ $c['funnel'] }}</td>
                <td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-300">{{ $c['destination'] }}</td>
                <td class="px-3 py-2 text-sm tabular-nums">₺{{ number_format($c['spend']) }}</td>
                <td class="px-3 py-2 text-sm tabular-nums">{{ number_format($c['results']) }} <span class="text-[11px] text-gray-400">{{ $c['result_label'] }}</span></td>
                <td class="px-3 py-2 text-sm tabular-nums">₺{{ number_format($c['cost_result']) }}</td>
                <td class="px-3 py-2"><x-ta.badge :color="match($c['pacing']) { 'Ahead', 'Constrained' => 'warning', 'Behind' => 'info', default => 'success' }" size="sm">{{ $c['pacing'] }}</x-ta.badge></td>
                <td class="px-3 py-2 text-xs text-gray-500">{{ $c['attention_primary'] ?? '—' }}</td>
                <td class="px-3 py-2"><button type="button" wire:click="openCampaign('{{ $c['id'] }}')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open</button></td>
            </tr>
        @endforeach
    </x-ta.table>
</div>

@if ($selectedCampaign)
    <x-demo.gads-drawer :title="$selectedCampaign['name']" :subtitle="($selectedCampaign['objective'] ?? $selectedCampaign['type'] ?? '').' · '.$selectedCampaign['status']">
        <div>
            <h3 class="text-xs font-semibold uppercase text-gray-400">Overview</h3>
            <div class="mt-2 grid grid-cols-2 gap-3">
                <div><p class="text-xs text-gray-400">Spend</p><p class="font-semibold tabular-nums">₺{{ number_format($selectedCampaign['spend']) }}</p></div>
                <div>
                    <p class="text-xs text-gray-400">Result</p>
                    <p class="font-semibold tabular-nums">{{ number_format($selectedCampaign['results']) }} <span class="text-xs font-normal text-gray-400">{{ $selectedCampaign['result_label'] }}</span></p>
                </div>
                <div><p class="text-xs text-gray-400">Cost / result</p><p class="font-semibold tabular-nums">₺{{ number_format($selectedCampaign['cost_result']) }}</p></div>
                <div><p class="text-xs text-gray-400">Pacing</p><p class="font-semibold">{{ $selectedCampaign['pacing'] }}</p></div>
                <div><p class="text-xs text-gray-400">Destination</p><p class="font-semibold">{{ $selectedCampaign['destination'] }}</p></div>
                <div><p class="text-xs text-gray-400">Funnel</p><p class="font-semibold">{{ $selectedCampaign['funnel'] }}</p></div>
            </div>
        </div>

        <div>
            <h3 class="text-xs font-semibold uppercase text-gray-400">Strategy</h3>
            <dl class="mt-2 grid grid-cols-2 gap-2 text-sm">
                <div><dt class="text-xs text-gray-400">Offering</dt><dd>{{ $selectedCampaign['offering'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Market</dt><dd>{{ $selectedCampaign['market'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Language</dt><dd>{{ $selectedCampaign['language'] ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-400">Business goal</dt><dd>{{ $selectedCampaign['goal'] ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-400">Primary result</dt><dd>{{ $selectedCampaign['result_label'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Optimization</dt><dd>{{ $selectedCampaign['optimization'] ?? $selectedCampaign['objective'] ?? '—' }}</dd></div>
            </dl>
            <p class="mt-2 text-[11px] text-violet-700 dark:text-violet-300">Operator-maintained strategy · does not mutate Meta Ads</p>
        </div>

        <div>
            <h3 class="text-xs font-semibold uppercase text-gray-400">Ad Sets</h3>
            @if (! empty($selectedCampaign['ad_sets']))
                <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($selectedCampaign['ad_sets'] as $adSet)
                        <li class="flex items-center justify-between gap-2 py-2 text-sm">
                            <div class="min-w-0">
                                <p class="truncate font-medium text-gray-900 dark:text-white">{{ $adSet['name'] }}</p>
                                <p class="text-[11px] text-gray-400">{{ $adSet['status'] ?? '' }} · {{ $adSet['audience'] ?? '' }}</p>
                            </div>
                            <div class="shrink-0 text-right text-xs tabular-nums text-gray-600 dark:text-gray-300">
                                <p>₺{{ number_format($adSet['spend'] ?? 0) }}</p>
                                <p>{{ number_format($adSet['results'] ?? 0) }} {{ $adSet['result_label'] ?? '' }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-2 text-sm text-gray-500">Ad set breakdown available in deep data when selected.</p>
            @endif
        </div>

        @if (! empty($selectedCampaign['attention']))
            <div>
                <h3 class="text-xs font-semibold uppercase text-gray-400">Attention</h3>
                <ul class="mt-2 space-y-1 text-sm text-amber-800 dark:text-amber-300">
                    @foreach ($selectedCampaign['attention'] as $a)
                        <li>{{ $a }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </x-demo.gads-drawer>
@endif
