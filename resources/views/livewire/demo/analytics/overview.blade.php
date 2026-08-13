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
                    {{ $identity['measures_line'] ?? 'Measures · Website' }}
                    @if (! empty($identity['website_name']))
                        ·
                        <a href="{{ route('demo.website', ['assetId' => $identity['website_asset_id']]) }}" wire:navigate class="font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $identity['website_name'] }}</a>
                    @endif
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
    <x-demo.gads-drawer :title="$selectedLanding['path'] ?? $selectedLanding['url'] ?? 'Landing page'" :subtitle="$selectedLanding['content_role'] ?? $selectedLanding['title'] ?? null" :severity="$selectedLanding['website_attention'] ?? $selectedLanding['attention'] ?? null">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <p class="text-xs text-gray-400">Sessions</p>
                <p class="font-semibold tabular-nums">{{ number_format($selectedLanding['sessions'] ?? 0) }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Engagement</p>
                <p class="font-semibold">{{ $selectedLanding['engagement'] ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Business actions</p>
                <p class="font-semibold tabular-nums">{{ $selectedLanding['business_actions'] ?? $selectedLanding['actions'] ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Content role</p>
                <p class="font-semibold">{{ $selectedLanding['content_role'] ?? '—' }}</p>
            </div>
        </div>
        @if (! empty($selectedLanding['top_events']))
            <div>
                <p class="text-xs text-gray-400">Configured events</p>
                <p class="text-gray-700 dark:text-gray-300">{{ is_array($selectedLanding['top_events']) ? implode(' · ', $selectedLanding['top_events']) : $selectedLanding['top_events'] }}</p>
            </div>
        @endif
        @if (! empty($selectedLanding['note']))
            <div>
                <p class="text-xs text-gray-400">Note</p>
                <p class="text-gray-700 dark:text-gray-300">{{ $selectedLanding['note'] }}</p>
            </div>
        @endif
        <a href="{{ route('demo.website', ['assetId' => $identity['website_asset_id']]) }}" wire:navigate class="inline-flex rounded-lg bg-brand-500 px-3 py-2 text-xs font-medium text-white">Open Website</a>
        <p class="text-[11px] text-gray-400">GA4 · measured Website behavior · Demo Mode</p>
    </x-demo.gads-drawer>
@endif

@if ($selectedFinding)
    <x-demo.gads-drawer :title="$selectedFinding['title']" :subtitle="$selectedFinding['category'] ?? null" :severity="$selectedFinding['severity'] ?? null">
        @foreach (['what' => 'What happened', 'why' => 'Why this matters', 'scope' => 'Scope', 'evidence' => 'Evidence', 'next' => 'Recommended next action'] as $key => $label)
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
    <x-demo.gads-drawer :title="$selectedEvent['name'] ?? $selectedEvent['event'] ?? 'Event'" :subtitle="$selectedEvent['role'] ?? null" :severity="$selectedEvent['state'] ?? null">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <p class="text-xs text-gray-400">Count</p>
                <p class="font-semibold tabular-nums">{{ isset($selectedEvent['count']) ? number_format($selectedEvent['count']) : '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Mapped action</p>
                <p class="font-semibold">{{ $selectedEvent['business_action'] ?? $selectedEvent['mapped_action'] ?? 'Not mapped' }}</p>
            </div>
        </div>
        @if (! empty($selectedEvent['detail']))
            <div>
                <p class="text-xs text-gray-400">Detail</p>
                <p class="text-gray-700 dark:text-gray-300">{{ $selectedEvent['detail'] }}</p>
            </div>
        @endif
        <p class="text-[11px] text-blue-700 dark:text-blue-300">Missing ≠ zero — absent event signal is not performance.</p>
        <p class="text-[11px] text-gray-400">Configured mapping only · no invented event names</p>
    </x-demo.gads-drawer>
@endif

@if ($selectedAction)
    <x-demo.gads-drawer :title="$selectedAction['action'] ?? $selectedAction['business_action'] ?? 'Business action'" :subtitle="$selectedAction['role'] ?? null" :severity="$selectedAction['state'] ?? null">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <p class="text-xs text-gray-400">GA4 event</p>
                <p class="font-semibold">{{ $selectedAction['ga4_event'] ?? $selectedAction['event'] ?? 'Not mapped' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Count</p>
                <p class="font-semibold tabular-nums">{{ isset($selectedAction['count']) ? number_format($selectedAction['count']) : '—' }}</p>
            </div>
        </div>
        @if (! empty($selectedAction['detail']))
            <div>
                <p class="text-xs text-gray-400">Detail</p>
                <p class="text-gray-700 dark:text-gray-300">{{ $selectedAction['detail'] }}</p>
            </div>
        @endif
        @if (! empty($selectedAction['finding_id']))
            <button type="button" wire:click="openFinding('{{ $selectedAction['finding_id'] }}')" class="rounded-lg bg-brand-500 px-3 py-2 text-xs font-medium text-white">Open related Finding</button>
        @endif
        <button type="button" wire:click="setMeasSub('business_actions')" class="rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Open measurement mapping</button>
        <p class="text-[11px] text-gray-400">Business actions are operator-configured — not auto-inferred.</p>
    </x-demo.gads-drawer>
@endif
