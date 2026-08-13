@php
    $navTabs = [
        ['key' => 'overview', 'label' => 'Overview', 'wire' => true],
        ['key' => 'measurement', 'label' => 'Measurement', 'wire' => true],
        ['key' => 'acquisition', 'label' => 'Acquisition', 'wire' => true],
        ['key' => 'behavior', 'label' => 'Behavior', 'wire' => true],
        ['key' => 'journeys', 'label' => 'Journeys', 'wire' => true],
        ['key' => 'relationships', 'label' => 'Relationships', 'wire' => true],
        ['key' => 'operations', 'label' => 'Operations', 'wire' => true],
    ];
@endphp

<div class="space-y-4">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-col gap-3 border-b border-gray-200 pb-4 dark:border-gray-800 lg:flex-row lg:items-start lg:justify-between">
        <div class="flex min-w-0 items-start gap-3">
            <x-demo.digital-asset-mark type="ga4" size="lg" class="mt-0.5" />
            <div class="min-w-0">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $identity['eyebrow'] ?? 'Google Analytics' }}</p>
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $identity['title'] }}</h1>
                    @include('livewire.demo.partials.demo-badge')
                </div>
                <a href="{{ route('demo.brand', ['brand' => $identity['brand_id']]) }}" wire:navigate class="mt-1 inline-block text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $identity['brand_name'] }}</a>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    {{ $identity['relationship_line'] ?? $identity['measures_line'] ?? 'Measures · Website' }}
                    ·
                    <a href="{{ route('demo.website', ['assetId' => $identity['website_asset_id']]) }}" wire:navigate class="font-medium text-brand-600 hover:underline dark:text-brand-400">Open Website</a>
                </p>
                <p class="mt-2 text-xs text-gray-500">
                    <span class="font-medium text-emerald-700 dark:text-emerald-400">{{ $identity['status'] ?? 'Connected' }}</span>
                    · {{ $identity['freshness'] }}
                </p>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    @foreach ($data['freshness'] ?? [] as $chip)
                        <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-[11px] text-gray-600 dark:bg-white/5 dark:text-gray-300" title="{{ $chip['detail'] ?? '' }}">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            {{ $chip['source'] }} · {{ $chip['age'] }}
                        </span>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="shrink-0">
            @include('livewire.demo.partials._analytics-header-actions')
        </div>
    </div>

    @include('livewire.demo.partials.asset-nav', ['tabs' => $navTabs, 'active' => $tab])

    @if ($showPeriodBar)
        @include('livewire.demo.partials.period-bar')
    @endif

    <p class="text-xs text-gray-400">{{ $data['demo_boundary'] }}</p>

    @if ($tab === 'overview')
        @include('livewire.demo.analytics.tabs.overview')
    @elseif ($tab === 'measurement')
        @include('livewire.demo.analytics.tabs.measurement')
    @elseif ($tab === 'acquisition')
        @include('livewire.demo.analytics.tabs.acquisition')
    @elseif ($tab === 'behavior')
        @include('livewire.demo.analytics.tabs.behavior')
    @elseif ($tab === 'journeys')
        @include('livewire.demo.analytics.tabs.journeys')
    @elseif ($tab === 'relationships')
        @include('livewire.demo.analytics.tabs.relationships')
    @elseif ($tab === 'operations')
        @include('livewire.demo.analytics.tabs.operations')
    @endif
</div>

@if ($selectedAttention)
    <x-demo.gads-drawer :title="$selectedAttention['title']" :subtitle="$selectedAttention['scope'] ?? null" :severity="$selectedAttention['severity'] ?? null">
        <div>
            <p class="text-xs text-gray-400">What happened</p>
            <p class="font-medium text-gray-900 dark:text-white">{{ $selectedAttention['metric'] }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-400">Why this matters</p>
            <p class="text-gray-700 dark:text-gray-300">{{ $selectedAttention['why'] ?? 'Measurement signal requiring agency review.' }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-400">Recommended next action</p>
            <p class="text-gray-700 dark:text-gray-300">{{ $selectedAttention['action'] }}@if (! empty($selectedAttention['tab'])) in {{ str_replace('_', ' ', $selectedAttention['tab']) }}@endif.</p>
        </div>
        @if (! empty($selectedAttention['finding_id']))
            <button type="button" wire:click="openFinding('{{ $selectedAttention['finding_id'] }}')" class="rounded-lg bg-brand-500 px-3 py-2 text-xs font-medium text-white">Open related Finding</button>
        @endif
        @if (! empty($selectedAttention['tab']))
            <button type="button" wire:click="setTab('{{ $selectedAttention['tab'] }}')" class="rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Go to workspace</button>
        @endif
    </x-demo.gads-drawer>
@endif

@if ($selectedLanding)
    <x-demo.gads-drawer :title="$selectedLanding['path'] ?? 'Landing page'" :subtitle="$selectedLanding['title'] ?? $selectedLanding['content_role'] ?? null" :severity="$selectedLanding['attention'] ?? null">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <p class="text-xs text-gray-400">Sessions</p>
                <p class="font-semibold tabular-nums">{{ number_format($selectedLanding['sessions'] ?? 0) }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Engaged rate</p>
                <p class="font-semibold tabular-nums">{{ isset($selectedLanding['engaged_rate']) ? $selectedLanding['engaged_rate'].'%' : ($selectedLanding['engagement'] ?? '—') }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Mapped actions</p>
                <p class="font-semibold tabular-nums">
                    @if (array_key_exists('mapped_actions', $selectedLanding) && $selectedLanding['mapped_actions'] !== null)
                        {{ number_format($selectedLanding['mapped_actions']) }}
                    @else
                        —
                    @endif
                </p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Content role</p>
                <p class="font-semibold">{{ $selectedLanding['content_role'] ?? '—' }}</p>
            </div>
        </div>
        @if (! empty($selectedLanding['attention']))
            <div>
                <p class="text-xs text-gray-400">Website attention</p>
                <p class="text-amber-700 dark:text-amber-400">{{ $selectedLanding['attention'] }}</p>
            </div>
        @endif
        <a href="{{ route('demo.website', ['assetId' => $selectedLanding['website_asset_id'] ?? $identity['website_asset_id']]) }}" wire:navigate class="inline-flex rounded-lg bg-brand-500 px-3 py-2 text-xs font-medium text-white">Open Website</a>
        <p class="text-[11px] text-gray-400">GA4 · measured Website behavior · Demo Mode</p>
    </x-demo.gads-drawer>
@endif

@if ($selectedFinding)
    <x-demo.gads-drawer :title="$selectedFinding['title']" :subtitle="$selectedFinding['category'] ?? null" :severity="$selectedFinding['severity'] ?? null">
        @foreach (['what' => 'What happened', 'why' => 'Why this matters', 'scope' => 'Scope', 'evidence' => 'Evidence', 'next' => 'Recommended next action', 'outcome' => 'Outcome'] as $key => $label)
            @if (! empty($selectedFinding[$key]))
                <div>
                    <p class="text-xs text-gray-400">{{ $label }}</p>
                    <p class="text-gray-800 dark:text-white/90">{{ $selectedFinding[$key] }}</p>
                </div>
            @endif
        @endforeach
        <p class="text-[11px] text-gray-400">Tasks are not auto-created. No GA4 write actions.</p>
    </x-demo.gads-drawer>
@endif

@if ($selectedEvent)
    <x-demo.gads-drawer :title="$selectedEvent['event'] ?? $selectedEvent['name'] ?? 'Event'" :subtitle="$selectedEvent['mapped_action'] ?? null" :severity="$selectedEvent['state'] ?? null">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <p class="text-xs text-gray-400">Count</p>
                <p class="font-semibold tabular-nums">
                    @if (array_key_exists('count', $selectedEvent) && $selectedEvent['count'] !== null)
                        {{ number_format($selectedEvent['count']) }}
                    @else
                        <span class="text-slate-400">Unavailable</span>
                    @endif
                </p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Mapped action</p>
                <p class="font-semibold">{{ $selectedEvent['mapped_action'] ?? 'Not mapped' }}</p>
            </div>
        </div>
        <p class="text-[11px] text-blue-700 dark:text-blue-300">{{ $data['missing_note'] ?? 'Missing ≠ zero — absent event signal is not performance.' }}</p>
    </x-demo.gads-drawer>
@endif

@if ($selectedAction)
    <x-demo.gads-drawer :title="$selectedAction['action'] ?? 'Business action'" :subtitle="$selectedAction['role'] ?? null" :severity="$selectedAction['state'] ?? null">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <p class="text-xs text-gray-400">GA4 event</p>
                <p class="font-semibold">{{ $selectedAction['ga4_event'] ?? 'Unavailable' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Event count</p>
                <p class="font-semibold tabular-nums">
                    @if (array_key_exists('event_count', $selectedAction) && $selectedAction['event_count'] !== null)
                        {{ number_format($selectedAction['event_count']) }}
                    @else
                        <span class="text-slate-400">Unavailable</span>
                    @endif
                </p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Mapping</p>
                <p class="font-semibold">{{ $selectedAction['mapping'] ?? $selectedAction['state'] ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Role</p>
                <p class="font-semibold">{{ $selectedAction['role'] ?? '—' }}</p>
            </div>
        </div>
        @if (! empty($selectedAction['note']))
            <div>
                <p class="text-xs text-gray-400">Note</p>
                <p class="text-gray-700 dark:text-gray-300">{{ $selectedAction['note'] }}</p>
            </div>
        @endif
        <button type="button" wire:click="setMeasSub('business_actions')" class="rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Open measurement mapping</button>
        <p class="text-[11px] text-blue-700 dark:text-blue-300">{{ $data['missing_note'] ?? 'Not mapped / Unavailable ≠ measured zero.' }}</p>
    </x-demo.gads-drawer>
@endif
