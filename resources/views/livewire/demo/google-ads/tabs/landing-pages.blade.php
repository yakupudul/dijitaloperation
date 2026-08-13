@php $lp = $data['landing_pages']; @endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Landing pages</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $lp['subtitle'] }}</p>
    </div>

    <div class="grid grid-cols-3 gap-3">
        <x-ta.metric-card label="Active paid destinations" :value="(string) $lp['active']" />
        <x-ta.metric-card label="Need Website review" :value="(string) $lp['need_review']" tone="warning" />
        <x-ta.metric-card label="Spend exposed to attention" :value="'₺'.number_format($lp['exposure_attention'] / 1000, 1).'K'" />
    </div>

    <x-ta.table>
        <x-slot:head>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Landing page</th>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Paid exposure</th>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Leads</th>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Technical</th>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Mobile</th>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Measurement</th>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Message</th>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Attention</th>
            <th class="px-4 py-2.5"></th>
        </x-slot:head>
        @foreach ($lp['rows'] as $row)
            <tr>
                <td class="px-4 py-2.5">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $row['url'] }}</p>
                    <p class="text-[11px] text-gray-400">{{ $row['title'] }} · {{ $row['language'] }}</p>
                </td>
                <td class="px-4 py-2.5 text-sm tabular-nums">₺{{ number_format($row['spend']) }} · {{ number_format($row['clicks']) }} clicks</td>
                <td class="px-4 py-2.5 text-sm tabular-nums">{{ $row['leads'] }}</td>
                <td class="px-4 py-2.5"><x-ta.badge :color="$row['technical'] === 'Good' ? 'success' : 'warning'" size="sm">{{ $row['technical'] }}</x-ta.badge></td>
                <td class="px-4 py-2.5"><x-ta.badge :color="$row['mobile'] === 'Good' ? 'success' : 'warning'" size="sm">{{ $row['mobile'] }}</x-ta.badge></td>
                <td class="px-4 py-2.5"><x-ta.badge :color="$row['measurement'] === 'Good' ? 'success' : 'warning'" size="sm">{{ $row['measurement'] }}</x-ta.badge></td>
                <td class="px-4 py-2.5"><x-ta.badge :color="match($row['message']) { 'Strong' => 'success', 'Weak' => 'error', default => 'warning' }" size="sm">{{ $row['message'] }}</x-ta.badge></td>
                <td class="px-4 py-2.5 text-xs text-gray-500">{{ $row['attention'] ?? '—' }}</td>
                <td class="px-4 py-2.5"><button type="button" wire:click="openLanding('{{ $row['id'] }}')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Inspect</button></td>
            </tr>
        @endforeach
    </x-ta.table>
</div>

@if ($selectedLanding)
    <x-demo.gads-drawer :title="$selectedLanding['url']" :subtitle="$selectedLanding['title']" :severity="$selectedLanding['attention']">
        <div class="grid grid-cols-2 gap-3">
            <div><p class="text-xs text-gray-400">Spend</p><p class="font-semibold">₺{{ number_format($selectedLanding['spend']) }}</p></div>
            <div><p class="text-xs text-gray-400">Clicks</p><p class="font-semibold">{{ number_format($selectedLanding['clicks']) }}</p></div>
            <div><p class="text-xs text-gray-400">Leads</p><p class="font-semibold">{{ $selectedLanding['leads'] }}</p></div>
            <div><p class="text-xs text-gray-400">Language</p><p class="font-semibold">{{ $selectedLanding['language'] }}</p></div>
        </div>
        <div>
            <p class="text-xs text-gray-400">Campaigns</p>
            <p>{{ implode(', ', $selectedLanding['campaigns']) }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-400">Query themes</p>
            <p>{{ implode(' · ', $selectedLanding['query_themes']) }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-400">Ad message themes</p>
            <p>{{ implode(' · ', $selectedLanding['ad_themes']) }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-400">Message match</p>
            <p class="font-medium">{{ $selectedLanding['message'] }}</p>
            <p class="mt-1 text-gray-600 dark:text-gray-300">{{ $selectedLanding['message_reason'] }}</p>
            <p class="mt-1 text-[11px] text-violet-700 dark:text-violet-300">Derived · explainable state — no numeric match score</p>
        </div>
        @if (! empty($selectedLanding['website_finding']))
            <a href="{{ route('demo.website', ['tab' => 'health', 'finding' => $selectedLanding['website_finding']]) }}" wire:navigate class="inline-flex rounded-lg bg-brand-500 px-3 py-2 text-xs font-medium text-white">Open Website finding</a>
        @else
            <a href="{{ route('demo.website', ['assetId' => $identity['website_asset_id']]) }}" wire:navigate class="inline-flex rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Open Website</a>
        @endif
    </x-demo.gads-drawer>
@endif
