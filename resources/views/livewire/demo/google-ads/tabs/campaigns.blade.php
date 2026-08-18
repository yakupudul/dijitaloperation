<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator.google_ads.tabs.campaigns') }}</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Portfolio performance with Campaign Context — not a Google Ads console clone.</p>
    </div>

    <div class="flex flex-wrap gap-2">
        <label class="text-xs text-gray-500">Attention
            <select wire:model.live="campaign_filter" class="mt-1 block rounded-lg border-gray-200 text-sm dark:border-gray-700 dark:bg-gray-900">
                <option value="all">All campaigns</option>
                <option value="attention">With attention</option>
                <option value="budget">Budget pacing issues</option>
            </select>
        </label>
    </div>

    <x-ta.table>
        <x-slot:head>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Campaign</th>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Budget</th>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Spend</th>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Primary result</th>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">CPA</th>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">IS / Lost</th>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Attention</th>
            <th class="px-4 py-2.5"></th>
        </x-slot:head>
        @foreach ($campaignRows as $c)
            <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                <td class="px-4 py-2.5">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $c['name'] }}</p>
                    <p class="text-[11px] text-gray-400">{{ $c['offering'] }} · {{ $c['market'] === 'United Kingdom' ? 'UK' : $c['market'] }} · {{ $c['type'] }}</p>
                </td>
                <td class="px-4 py-2.5 text-sm tabular-nums">₺{{ number_format($c['budget']) }} <span class="text-xs text-gray-400">{{ $c['pacing'] }}</span></td>
                <td class="px-4 py-2.5 text-sm tabular-nums">₺{{ number_format($c['spend']) }}</td>
                <td class="px-4 py-2.5 text-sm tabular-nums">{{ $c['leads'] }} leads</td>
                <td class="px-4 py-2.5 text-sm tabular-nums">₺{{ number_format($c['cpa']) }}</td>
                <td class="px-4 py-2.5 text-xs text-gray-500">{{ $c['impr_share'] }}% · budget {{ $c['lost_is_budget'] }}% · rank {{ $c['lost_is_rank'] }}%</td>
                <td class="px-4 py-2.5 text-xs">{{ $c['attention_primary'] ?? '—' }}</td>
                <td class="px-4 py-2.5"><button type="button" wire:click="openCampaign('{{ $c['id'] }}')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open</button></td>
            </tr>
        @endforeach
    </x-ta.table>
</div>

@if ($selectedCampaign)
    <x-demo.gads-drawer :title="$selectedCampaign['name']" :subtitle="$selectedCampaign['type'].' · '.$selectedCampaign['status']">
        <div class="grid grid-cols-2 gap-3">
            <div><p class="text-xs text-gray-400">Spend</p><p class="font-semibold tabular-nums">₺{{ number_format($selectedCampaign['spend']) }}</p></div>
            <div><p class="text-xs text-gray-400">Leads</p><p class="font-semibold tabular-nums">{{ $selectedCampaign['leads'] }}</p></div>
            <div><p class="text-xs text-gray-400">CPA</p><p class="font-semibold tabular-nums">₺{{ number_format($selectedCampaign['cpa']) }}</p></div>
            <div><p class="text-xs text-gray-400">Pacing</p><p class="font-semibold">{{ $selectedCampaign['pacing'] }}</p></div>
        </div>

        <div>
            <h3 class="text-xs font-semibold uppercase text-gray-400">Campaign Context</h3>
            <dl class="mt-2 grid grid-cols-2 gap-2 text-sm">
                <div><dt class="text-xs text-gray-400">Offering</dt><dd>{{ $selectedCampaign['offering'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Market</dt><dd>{{ $selectedCampaign['market'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Language</dt><dd>{{ $selectedCampaign['language'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Business goal</dt><dd>{{ $selectedCampaign['goal'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Primary conversion</dt><dd>{{ $selectedCampaign['primary_conversion'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Funnel</dt><dd>{{ $selectedCampaign['funnel'] }}</dd></div>
                <div class="col-span-2"><dt class="text-xs text-gray-400">Search strategy</dt><dd>{{ $selectedCampaign['search_strategy'] }}</dd></div>
            </dl>
            <p class="mt-2 text-[11px] text-violet-700 dark:text-violet-300">Operator-maintained strategy · does not mutate Google Ads</p>
        </div>

        <div>
            <h3 class="text-xs font-semibold uppercase text-gray-400">Impression share</h3>
            <ul class="mt-2 space-y-1 text-sm">
                <li class="flex justify-between"><span>Search IS</span><span class="tabular-nums">{{ $selectedCampaign['impr_share'] }}%</span></li>
                <li class="flex justify-between"><span>Lost IS · budget</span><span class="tabular-nums text-amber-700 dark:text-amber-400">{{ $selectedCampaign['lost_is_budget'] }}%</span></li>
                <li class="flex justify-between"><span>Lost IS · rank</span><span class="tabular-nums">{{ $selectedCampaign['lost_is_rank'] }}%</span></li>
            </ul>
            <p class="mt-1 text-[11px] text-gray-400">Google Ads · Auction Insights context · Demo</p>
        </div>

        <div>
            <h3 class="text-xs font-semibold uppercase text-gray-400">Breakdowns</h3>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Device · Location · Day · Hour — specialist controls live here (not top-level tabs).</p>
            <div class="mt-2 grid grid-cols-2 gap-2 text-xs">
                <div class="rounded-lg bg-gray-50 p-2 dark:bg-white/[0.03]"><p class="text-gray-400">Mobile</p><p class="font-medium">52% spend</p></div>
                <div class="rounded-lg bg-gray-50 p-2 dark:bg-white/[0.03]"><p class="text-gray-400">Desktop</p><p class="font-medium">48% spend</p></div>
                <div class="rounded-lg bg-gray-50 p-2 dark:bg-white/[0.03]"><p class="text-gray-400">UK</p><p class="font-medium">Primary market</p></div>
                <div class="rounded-lg bg-gray-50 p-2 dark:bg-white/[0.03]"><p class="text-gray-400">Peak hours</p><p class="font-medium">10:00–14:00</p></div>
            </div>
        </div>
    </x-demo.gads-drawer>
@endif
