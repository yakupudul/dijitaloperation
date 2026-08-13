@php $ads = $data['ads']; @endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Ads & assets</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $ads['subtitle'] }}</p>
        <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">{{ $ads['policy_summary'] }}</p>
    </div>

    <div class="grid gap-3 lg:grid-cols-3">
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03] lg:col-span-1">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Asset coverage</h3>
            <p class="mt-1 text-xs text-gray-400">Relevant asset groups present — not a score</p>
            <ul class="mt-3 space-y-1.5 text-sm">
                @foreach ($ads['asset_groups'] as $g)
                    <li class="flex justify-between gap-2">
                        <span>{{ $g['group'] }}</span>
                        <x-ta.badge :color="match($g['state']) { 'Present' => 'success', 'Partial' => 'warning', default => 'light' }" size="sm">{{ $g['state'] }}</x-ta.badge>
                    </li>
                @endforeach
            </ul>
        </section>
        <div class="lg:col-span-2">
            <x-ta.table>
                <x-slot:head>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Ad</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Campaign</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">State</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Assets</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Policy</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Message</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">LP match</th>
                    <th class="px-4 py-2.5"></th>
                </x-slot:head>
                @foreach ($ads['rows'] as $row)
                    <tr>
                        <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</td>
                        <td class="px-4 py-2.5 text-xs text-gray-500">{{ $row['campaign'] }}</td>
                        <td class="px-4 py-2.5"><x-ta.badge color="success" size="sm">{{ $row['state'] }}</x-ta.badge></td>
                        <td class="px-4 py-2.5 text-xs tabular-nums">{{ $row['asset_coverage'] }}</td>
                        <td class="px-4 py-2.5"><x-ta.badge :color="$row['policy'] === 'Approved' ? 'success' : 'warning'" size="sm">{{ $row['policy'] }}</x-ta.badge></td>
                        <td class="px-4 py-2.5 text-xs">{{ $row['theme'] }}</td>
                        <td class="px-4 py-2.5"><x-ta.badge :color="match($row['landing_match']) { 'Strong' => 'success', 'Weak' => 'error', default => 'warning' }" size="sm">{{ $row['landing_match'] }}</x-ta.badge></td>
                        <td class="px-4 py-2.5"><button type="button" wire:click="openAd('{{ $row['id'] }}')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Inspect</button></td>
                    </tr>
                @endforeach
            </x-ta.table>
        </div>
    </div>
</div>

@if ($selectedAd)
    <x-demo.gads-drawer :title="$selectedAd['name']" :subtitle="$selectedAd['campaign'].' · '.$selectedAd['ad_group']">
        <div>
            <p class="text-xs text-gray-400">Final URL</p>
            <p class="font-medium">{{ $selectedAd['final_url'] }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-400">Headlines</p>
            <ul class="mt-1 list-disc pl-4 text-sm">
                @foreach ($selectedAd['headlines'] as $h)<li>{{ $h }}</li>@endforeach
            </ul>
        </div>
        <div>
            <p class="text-xs text-gray-400">Search intent → Ad message</p>
            <p class="font-medium text-gray-900 dark:text-white">{{ $selectedAd['intent_match'] }}</p>
            <p class="mt-1 text-gray-600 dark:text-gray-300">{{ $selectedAd['intent_why'] }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-400">Brand alignment</p>
            <p>{{ $selectedAd['brand_note'] }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-400">Google Ad Strength</p>
            <p>{{ $selectedAd['google_strength'] }} <span class="text-[11px] text-gray-400">provider metric · not MoxDOP judgement</span></p>
        </div>
    </x-demo.gads-drawer>
@endif
