@php
    $navTabs = [
        ['key' => 'overview', 'label' => 'Overview', 'wire' => true],
        ['key' => 'campaigns', 'label' => 'Campaigns', 'wire' => true],
        ['key' => 'adgroups', 'label' => 'Ad groups', 'wire' => true],
        ['key' => 'keywords', 'label' => 'Keywords', 'wire' => true],
        ['key' => 'search_terms', 'label' => 'Search terms', 'wire' => true],
        ['key' => 'ads', 'label' => 'Ads', 'wire' => true],
        ['key' => 'landing_pages', 'label' => 'Landing pages', 'wire' => true],
        ['key' => 'conversions', 'label' => 'Conversions', 'wire' => true],
        ['key' => 'insights', 'label' => 'Insights', 'wire' => true],
    ];
@endphp

<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Google Ads · '.($asset['name'] ?? 'Atlas Dental — Google Ads'),
        'title' => 'Workspace',
        'subtitle' => ($data['period_label'] ?? '').' · Specialist density · Connected provider',
        'badges' => ['Connected provider'],
    ])

    @include('livewire.demo.partials.period-bar')
    @include('livewire.demo.partials.asset-nav', ['tabs' => $navTabs, 'active' => $tab])

    @if ($tab === 'overview')
        @include('livewire.demo.partials.kpi-strip', [
            'kpis' => $data['kpis'],
            'primaryCount' => 4,
        ])

        @include('livewire.demo.partials.section-question', [
            'question' => 'What needs attention in paid search?',
            'hint' => 'Highest-severity signals for this Google Ads asset.',
        ])
        @include('livewire.demo.partials.attention-list', ['items' => $attention])

        @if ($seasonality)
            <x-ta.alert variant="info" title="Seasonality note" :message="$seasonality" />
        @endif
    @endif

    @if ($tab === 'campaigns')
        @include('livewire.demo.partials.section-question', [
            'question' => 'Which campaigns are carrying conversions?',
            'hint' => 'Status, spend, conversions, and CPA for the selected period.',
        ])
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Campaign</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Status</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Spend</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Conv.</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">CPA</th>
            </x-slot:head>
            @foreach ($data['campaigns'] as $campaign)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $campaign['name'] }}</td>
                    <td class="px-5 py-4">
                        <x-ta.badge :color="$campaign['status'] === 'ENABLED' ? 'success' : 'light'" size="sm">{{ $campaign['status'] }}</x-ta.badge>
                    </td>
                    <td class="px-5 py-4 text-sm text-gray-500">₺{{ number_format($campaign['spend']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $campaign['conv'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">₺{{ number_format($campaign['cpa']) }}</td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif

    @if ($tab === 'adgroups')
        @include('livewire.demo.partials.section-question', [
            'question' => 'How are ad groups performing inside campaigns?',
        ])
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Ad group</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Campaign</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Status</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Spend</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Clicks</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Conv.</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">CPA</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">CTR</th>
            </x-slot:head>
            @foreach ($data['ad_groups'] as $row)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['name'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['campaign'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['status'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">₺{{ number_format($row['spend']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($row['clicks']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['conv'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">₺{{ number_format($row['cpa']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['ctr'] }}%</td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif

    @if ($tab === 'keywords')
        @include('livewire.demo.partials.section-question', [
            'question' => 'Which keywords convert efficiently?',
        ])
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Keyword</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Match</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Ad group</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Spend</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Clicks</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Conv.</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">QS</th>
            </x-slot:head>
            @foreach ($data['keywords'] as $row)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['keyword'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['match'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['ad_group'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">₺{{ number_format($row['spend']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['clicks'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['conv'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['qs'] }}</td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif

    @if ($tab === 'search_terms')
        @include('livewire.demo.partials.section-question', [
            'question' => 'Where is search-term spend wasted?',
            'hint' => 'Classification filters are live via DemoState.',
        ])

        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="setClassificationFilter('all')"
                @class([
                    'rounded-md px-2.5 py-1.5 text-xs font-medium transition',
                    'bg-brand-500 text-white' => $classificationFilter === 'all',
                    'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $classificationFilter !== 'all',
                ])>All</button>
            @foreach ($classifications as $classification)
                <button type="button" wire:click="setClassificationFilter('{{ $classification }}')"
                    @class([
                        'rounded-md px-2.5 py-1.5 text-xs font-medium transition',
                        'bg-brand-500 text-white' => $classificationFilter === $classification,
                        'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $classificationFilter !== $classification,
                    ])>{{ $classification }}</button>
            @endforeach
        </div>

        <div class="flex flex-wrap gap-2">
            <x-ta.button type="button" wire:click="createRecommendation" size="sm">Create Recommendation</x-ta.button>
            <x-ta.button href="{{ route('demo.recommendations') }}" size="sm" variant="outline">Open recommendations</x-ta.button>
        </div>

        @if (count($searchTerms) === 0)
            @include('livewire.demo.partials.empty-panel', [
                'title' => 'No search terms for this classification',
                'message' => 'Clear the classification filter to see all terms.',
            ])
        @else
            <x-ta.table>
                <x-slot:head>
                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Search term</th>
                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Campaign</th>
                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Spend</th>
                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Conv.</th>
                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Classification</th>
                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Action</th>
                </x-slot:head>
                @foreach ($searchTerms as $row)
                    <tr>
                        <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['term'] }}</td>
                        <td class="px-5 py-4 text-sm text-gray-500">{{ $row['campaign'] }}</td>
                        <td class="px-5 py-4 text-sm text-gray-500">₺{{ number_format($row['spend']) }}</td>
                        <td class="px-5 py-4 text-sm text-gray-500">{{ $row['conversions'] }}</td>
                        <td class="px-5 py-4">
                            <x-ta.badge :color="match($row['classification']) {
                                'Keep', 'Brand' => 'success',
                                'Negative candidate', 'Irrelevant' => 'error',
                                default => 'warning',
                            }" size="sm">{{ $row['classification'] }}</x-ta.badge>
                        </td>
                        <td class="px-5 py-4">
                            @if (in_array($row['classification'], ['Negative candidate', 'Irrelevant'], true))
                                <button type="button" wire:click="createRecommendation(@js($row['term']))"
                                    class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">
                                    Create recommendation
                                </button>
                            @else
                                <span class="text-sm text-gray-500">{{ $row['action'] }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ta.table>
        @endif
    @endif

    @if ($tab === 'ads')
        @include('livewire.demo.partials.section-question', [
            'question' => 'Which ads and assets are getting impressions?',
        ])
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Asset</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Type</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Campaign</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Impr.</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Clicks</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Conv.</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">CTR</th>
            </x-slot:head>
            @foreach ($data['ads_assets'] as $row)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">
                        {{ $row['name'] }}
                        @if (! empty($row['headlines']))
                            <div class="mt-1 text-xs text-gray-400">{{ implode(' · ', array_slice($row['headlines'], 0, 2)) }}</div>
                        @endif
                    </td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['type'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['campaign'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($row['impressions']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['clicks'] !== null ? number_format($row['clicks']) : '—' }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['conv'] !== null ? number_format($row['conv']) : '—' }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['ctr'] !== null ? $row['ctr'].'%' : '—' }}</td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif

    @if ($tab === 'landing_pages')
        @include('livewire.demo.partials.section-question', [
            'question' => 'Are landing pages protecting paid efficiency?',
            'hint' => 'Cross-link to Website workspace for technical detail.',
        ])
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">URL</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Sessions</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Conv rate</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Mobile LCP</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Note</th>
            </x-slot:head>
            @foreach ($data['landing_pages'] as $row)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['url'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($row['sessions']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['conv_rate'] }}%</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['mobile_lcp'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['note'] }}</td>
                </tr>
            @endforeach
        </x-ta.table>
        <x-ta.button href="{{ route('demo.website', ['tab' => 'technical']) }}" size="sm" variant="outline">Open Website workspace</x-ta.button>
    @endif

    @if ($tab === 'conversions')
        @include('livewire.demo.partials.section-question', [
            'question' => 'Which conversion actions are driving reported value?',
        ])
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Conversion</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Category</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Source</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Count</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Value</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">CPA</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Status</th>
            </x-slot:head>
            @foreach ($data['conversions'] as $row)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['name'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['category'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['source'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($row['count']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['value'] !== null ? '₺'.number_format($row['value']) : '—' }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">₺{{ number_format($row['cpa']) }}</td>
                    <td class="px-5 py-4">
                        <x-ta.badge :color="$row['status'] === 'active' ? 'success' : 'warning'" size="sm">{{ $row['status'] }}</x-ta.badge>
                    </td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif

    @if ($tab === 'insights')
        @include('livewire.demo.partials.section-question', [
            'question' => 'What themes should the specialist act on?',
            'hint' => 'Themed cards derived from demo Google Ads signals.',
        ])
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <x-ta.card>
                <p class="text-xs uppercase tracking-wide text-gray-400">Waste control</p>
                <h3 class="mt-2 font-semibold text-gray-800 dark:text-white/90">Search-term negatives</h3>
                <p class="mt-1 text-sm text-gray-500">Low-relevance queries still consume budget. Use Search terms → Create Recommendation.</p>
                <div class="mt-4">
                    <x-ta.button type="button" wire:click="setTab('search_terms')" size="sm" variant="outline">Open search terms</x-ta.button>
                </div>
            </x-ta.card>
            <x-ta.card>
                <p class="text-xs uppercase tracking-wide text-gray-400">Landing quality</p>
                <h3 class="mt-2 font-semibold text-gray-800 dark:text-white/90">Mobile LCP on /implant</h3>
                <p class="mt-1 text-sm text-gray-500">Paid traffic lands on a weak mobile page. Cross-check Website technical findings.</p>
                <div class="mt-4">
                    <x-ta.button href="{{ route('demo.website', ['tab' => 'technical']) }}" size="sm" variant="outline">Website workspace</x-ta.button>
                </div>
            </x-ta.card>
            <x-ta.card>
                <p class="text-xs uppercase tracking-wide text-gray-400">Conversion hygiene</p>
                <h3 class="mt-2 font-semibold text-gray-800 dark:text-white/90">Offline consult import</h3>
                <p class="mt-1 text-sm text-gray-500">Offline conversion still needs review before trusting CPA on brand+local campaigns.</p>
                <div class="mt-4">
                    <x-ta.button type="button" wire:click="setTab('conversions')" size="sm" variant="outline">Open conversions</x-ta.button>
                </div>
            </x-ta.card>
            <x-ta.card>
                <p class="text-xs uppercase tracking-wide text-gray-400">Seasonality</p>
                <h3 class="mt-2 font-semibold text-gray-800 dark:text-white/90">Period narrative</h3>
                <p class="mt-1 text-sm text-gray-500">{{ $seasonality ?? 'No seasonality note for this preset.' }}</p>
            </x-ta.card>
            <x-ta.card>
                <p class="text-xs uppercase tracking-wide text-gray-400">Brand vs non-brand</p>
                <h3 class="mt-2 font-semibold text-gray-800 dark:text-white/90">Protect brand CPA</h3>
                <p class="mt-1 text-sm text-gray-500">Brand Exact remains the efficiency anchor — keep competitor paused until waste is controlled.</p>
                <div class="mt-4">
                    <x-ta.button type="button" wire:click="setTab('campaigns')" size="sm" variant="outline">Open campaigns</x-ta.button>
                </div>
            </x-ta.card>
            <x-ta.card>
                <p class="text-xs uppercase tracking-wide text-gray-400">Keyword quality</p>
                <h3 class="mt-2 font-semibold text-gray-800 dark:text-white/90">Broad price intent</h3>
                <p class="mt-1 text-sm text-gray-500">Broad “diş implantı fiyat” has weaker QS — review match types after negatives land.</p>
                <div class="mt-4">
                    <x-ta.button type="button" wire:click="setTab('keywords')" size="sm" variant="outline">Open keywords</x-ta.button>
                </div>
            </x-ta.card>
        </div>
    @endif
</div>
