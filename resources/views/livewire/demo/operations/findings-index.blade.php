<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Operations',
        'title' => 'Findings',
        'subtitle' => 'Detected issues across connected and public assets — prioritized for agency action.',
    ])

    <div class="grid gap-3 sm:grid-cols-3">
        <x-ta.card>
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Critical</p>
            <p class="mt-1 text-2xl font-bold text-error-600 dark:text-error-400">{{ $summary['critical'] }}</p>
        </x-ta.card>
        <x-ta.card>
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">High</p>
            <p class="mt-1 text-2xl font-bold text-warning-600 dark:text-warning-400">{{ $summary['high'] }}</p>
        </x-ta.card>
        <x-ta.card>
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Medium</p>
            <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $summary['medium'] }}</p>
        </x-ta.card>
    </div>

    <div>
        @include('livewire.demo.partials.section-question', [
            'question' => 'Filter by severity',
            'hint' => 'Critical first — then high and medium.',
        ])
        <div class="flex flex-wrap gap-2">
            @foreach (['all' => 'All', 'critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low', 'info' => 'Info'] as $key => $label)
                <button type="button" wire:click="setSeverity('{{ $key }}')"
                    @class([
                        'rounded-lg px-3 py-2 text-sm font-medium transition',
                        'bg-brand-500 text-white' => $severity === $key,
                        'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $severity !== $key,
                    ])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <div>
        @include('livewire.demo.partials.section-question', [
            'question' => 'Filter by asset type',
            'hint' => 'Cross-channel findings stay in one queue.',
        ])
        <div class="flex flex-wrap gap-2">
            @foreach ([
                'all' => 'All assets',
                'meta_ads' => 'Meta Ads',
                'google_ads' => 'Google Ads',
                'website' => 'Website',
                'gbp' => 'GBP',
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
    </div>

    <div class="space-y-3">
        @forelse ($findings as $finding)
            @php
                $severityColor = match ($finding['severity']) {
                    'critical' => 'error',
                    'high' => 'error',
                    'medium' => 'warning',
                    'low' => 'info',
                    default => 'light',
                };
            @endphp
            <x-ta.card>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ta.badge :color="$severityColor" size="sm">{{ $finding['severity'] }}</x-ta.badge>
                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ $finding['brand'] }} · {{ $finding['asset'] }}</span>
                            <x-ta.badge color="light" size="sm">{{ $finding['status'] }}</x-ta.badge>
                        </div>
                        <h3 class="mt-2 text-base font-semibold text-gray-800 dark:text-white/90">{{ $finding['title'] }}</h3>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $finding['plain'] ?? $finding['title'] }}</p>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $finding['evidence'] }}</p>
                        <p class="mt-2 text-xs text-gray-400">Detected {{ $finding['detected'] }} · {{ $finding['type'] }}</p>

                        @if ($expandedId === $finding['id'])
                            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                                <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.02]">
                                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Observation</p>
                                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $finding['observation'] ?? '—' }}</p>
                                </div>
                                <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.02]">
                                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Why it matters</p>
                                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $finding['why'] ?? '—' }}</p>
                                </div>
                                <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.02]">
                                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Evidence</p>
                                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $finding['evidence'] }}</p>
                                </div>
                            </div>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-ta.button wire:click="expand('{{ $finding['id'] }}')" size="sm" variant="outline">
                            {{ $expandedId === $finding['id'] ? 'Hide detail' : 'Expand detail' }}
                        </x-ta.button>
                        <x-ta.button href="{{ route('demo.recommendations') }}" size="sm" variant="outline">Related recommendations</x-ta.button>
                    </div>
                </div>
            </x-ta.card>
        @empty
            <x-ta.empty-state title="No findings for these filters" message="Try another severity or asset type." />
        @endforelse
    </div>
</div>
