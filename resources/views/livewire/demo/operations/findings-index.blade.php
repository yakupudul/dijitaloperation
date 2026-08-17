<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Operations',
        'title' => 'Findings',
        'subtitle' => 'Operational triage inbox — what meaningful conditions were observed across the portfolio.',
    ])

    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Critical / High Open</p>
            <p class="mt-1 text-2xl font-bold text-error-600 dark:text-error-400">{{ $summary['critical_high'] }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">New</p>
            <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $summary['new'] }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Regressions</p>
            <p class="mt-1 text-2xl font-bold text-warning-600 dark:text-warning-400">{{ $summary['regressions'] }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Recently Resolved</p>
            <p class="mt-1 text-2xl font-bold text-success-600 dark:text-success-400">{{ $summary['resolved'] }}</p>
        </div>
    </div>

    <div class="flex flex-wrap gap-2" role="group" aria-label="Severity filter">
        @foreach (['all' => 'All', 'critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low', 'info' => 'Info'] as $key => $label)
            <button type="button" wire:click="setSeverity('{{ $key }}')"
                @class([
                    'rounded-lg px-3 py-2 text-sm font-medium transition',
                    'bg-brand-500 text-white' => $severity === $key,
                    'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $severity !== $key,
                ])>{{ $label }}</button>
        @endforeach
    </div>

    <div class="flex flex-wrap gap-2" role="group" aria-label="Asset type filter">
        @foreach ([
            'all' => 'All assets',
            'meta_ads' => 'Meta Ads',
            'google_ads' => 'Google Ads',
            'website' => 'Website',
            'gbp' => 'GBP',
            'ga4' => 'GA4',
            'gsc' => 'Search Console',
            'hosting' => 'Hosting',
        ] as $key => $label)
            <button type="button" wire:click="setAssetType('{{ $key }}')"
                @class([
                    'rounded-lg px-3 py-2 text-sm font-medium transition',
                    'bg-brand-500 text-white' => $assetType === $key,
                    'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $assetType !== $key,
                ])>{{ $label }}</button>
        @endforeach
    </div>

    <div class="space-y-3">
        @forelse ($findings as $finding)
            @php
                $severityColor = match ($finding['severity']) {
                    'critical', 'high' => 'error',
                    'medium' => 'warning',
                    'low' => 'info',
                    default => 'light',
                };
            @endphp
            <article class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ta.badge :color="$severityColor" size="sm">{{ strtoupper($finding['severity']) }}</x-ta.badge>
                            @if (! empty($finding['asset_type']))
                                <x-demo.digital-asset-mark :type="$finding['asset_type']" size="sm" />
                            @endif
                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ $finding['brand'] }} · {{ $finding['asset'] }}</span>
                            @if (! empty($finding['category']))
                                <x-ta.badge color="light" size="sm">{{ $finding['category'] }}</x-ta.badge>
                            @endif
                        </div>
                        <h3 class="mt-2 text-base font-semibold text-gray-800 dark:text-white/90">{{ $finding['title'] }}</h3>
                        <p class="mt-1 text-xs text-gray-500">
                            Last observed {{ $finding['last_observed'] ?? $finding['detected'] }}
                            · {{ ($finding['recommendations_count'] ?? 0) }} Recommendation
                            · {{ ($finding['tasks_count'] ?? 0) }} Task
                        </p>

                        @if ($expandedId === $finding['id'])
                            @php
                                $findingContext = \App\Support\Demo\CommercialContextFixtures::contextForOperationalRow($finding);
                            @endphp
                            <div class="mt-3">
                                <x-demo.commercial-context
                                    :service="$findingContext['service']"
                                    :goal="$findingContext['goal']"
                                    :offering="$findingContext['offering']"
                                />
                            </div>
                            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400">What happened</p>
                                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $finding['observation'] ?? $finding['plain'] }}</p>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Why it matters</p>
                                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $finding['why'] ?? '—' }}</p>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Evidence</p>
                                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $finding['evidence'] }}</p>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Source &amp; freshness</p>
                                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $finding['source_label'] ?? 'Observed' }} · {{ $finding['detected'] }}</p>
                                </div>
                            </div>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-ta.button wire:click="expand('{{ $finding['id'] }}')" size="sm" variant="outline">
                            {{ $expandedId === $finding['id'] ? 'Hide' : 'Open' }}
                        </x-ta.button>
                        @if (($finding['status'] ?? 'open') === 'open')
                            <x-ta.button wire:click="acknowledge('{{ $finding['id'] }}')" size="sm" variant="outline">Acknowledge</x-ta.button>
                            <x-ta.button wire:click="resolve('{{ $finding['id'] }}')" size="sm" variant="primary">Resolve</x-ta.button>
                        @elseif (($finding['status'] ?? '') === 'acknowledged')
                            <x-ta.button wire:click="resolve('{{ $finding['id'] }}')" size="sm" variant="primary">Resolve</x-ta.button>
                            <x-ta.button wire:click="reopen('{{ $finding['id'] }}')" size="sm" variant="outline">Reopen</x-ta.button>
                        @else
                            <x-ta.button wire:click="reopen('{{ $finding['id'] }}')" size="sm" variant="outline">Reopen</x-ta.button>
                        @endif
                        <x-ta.button href="{{ route('operator.recommendations') }}" size="sm" variant="outline">Recommendation</x-ta.button>
                    </div>
                </div>
            </article>
        @empty
            @include('livewire.demo.partials.empty-panel', [
                'title' => 'No Findings yet',
                'message' => 'No Finding rows match the current filters. Empty means empty — sample Findings are never invented for production.',
            ])
        @endforelse
    </div>
</div>
