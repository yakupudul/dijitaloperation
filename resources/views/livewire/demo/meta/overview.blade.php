@php
    $navTabs = [
        ['key' => 'overview', 'label' => 'Overview', 'wire' => true],
        ['key' => 'campaigns', 'label' => 'Campaigns', 'wire' => true],
        ['key' => 'creatives', 'label' => 'Creatives', 'wire' => true],
        ['key' => 'audience', 'label' => 'Audience & Delivery', 'wire' => true],
        ['key' => 'funnel', 'label' => 'Funnel & Destinations', 'wire' => true],
        ['key' => 'measurement', 'label' => 'Measurement', 'wire' => true],
        ['key' => 'operations', 'label' => 'Operations', 'wire' => true],
    ];
@endphp

<div class="space-y-4">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-col gap-3 border-b border-gray-200 pb-4 dark:border-gray-800 lg:flex-row lg:items-start lg:justify-between">
        <div class="flex min-w-0 items-start gap-3">
            <x-demo.digital-asset-mark type="meta_ads" size="lg" class="mt-0.5" />
            <div class="min-w-0">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $identity['eyebrow'] }}</p>
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $identity['title'] }}</h1>
                    @include('livewire.demo.partials.demo-badge')
                </div>
                <a href="{{ route('demo.brand', ['brand' => $identity['brand_id']]) }}" wire:navigate class="mt-1 inline-block text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $identity['brand_name'] }}</a>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $identity['strategy_line'] }}</p>
                <p class="mt-2 text-xs text-gray-500">
                    <span class="font-medium text-emerald-700 dark:text-emerald-400">{{ $identity['status'] }}</span>
                    · {{ $identity['freshness'] }}
                </p>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    @foreach ($data['freshness'] as $chip)
                        <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-[11px] text-gray-600 dark:bg-white/5 dark:text-gray-300" title="{{ $chip['detail'] }}">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            {{ $chip['source'] }} · {{ $chip['age'] }}
                        </span>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="shrink-0">
            @include('livewire.demo.partials._meta-header-actions')
        </div>
    </div>

    @include('livewire.demo.partials.asset-nav', ['tabs' => $navTabs, 'active' => $tab])

    @if ($showPeriodBar)
        @include('livewire.demo.partials.period-bar')
    @endif

    <p class="text-xs text-gray-400">{{ $data['demo_boundary'] }}</p>

    @if ($tab === 'overview')
        @include('livewire.demo.meta.tabs.overview')
    @elseif ($tab === 'campaigns')
        @include('livewire.demo.meta.tabs.campaigns')
    @elseif ($tab === 'creatives')
        @include('livewire.demo.meta.tabs.creatives')
    @elseif ($tab === 'audience')
        @include('livewire.demo.meta.tabs.audience')
    @elseif ($tab === 'funnel')
        @include('livewire.demo.meta.tabs.funnel')
    @elseif ($tab === 'measurement')
        @include('livewire.demo.meta.tabs.measurement')
    @elseif ($tab === 'operations')
        @include('livewire.demo.meta.tabs.operations')
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
            <p class="text-gray-700 dark:text-gray-300">{{ $selectedAttention['why'] ?? 'Operational signal requiring agency review.' }}</p>
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

@if ($selectedCreative)
    <x-demo.gads-drawer :title="$selectedCreative['name']" :subtitle="($selectedCreative['format'] ?? '').' · '.($selectedCreative['campaign'] ?? '')">
        <x-demo.meta-creative-thumb :gradient="$selectedCreative['thumb'] ?? 'slate'" :name="$selectedCreative['name']" class="mb-1 max-h-40 rounded-xl" />
        <div class="grid grid-cols-2 gap-3">
            <div><p class="text-xs text-gray-400">Spend</p><p class="font-semibold tabular-nums">₺{{ number_format($selectedCreative['spend'] ?? 0) }}</p></div>
            <div>
                <p class="text-xs text-gray-400">Result</p>
                <p class="font-semibold tabular-nums">{{ number_format($selectedCreative['result'] ?? $selectedCreative['results'] ?? 0) }} <span class="text-xs font-normal text-gray-400">{{ $selectedCreative['result_label'] ?? '' }}</span></p>
            </div>
            <div><p class="text-xs text-gray-400">Cost / result</p><p class="font-semibold tabular-nums">₺{{ number_format($selectedCreative['cost_result'] ?? 0) }}</p></div>
            <div><p class="text-xs text-gray-400">Angle</p><p class="font-semibold">{{ $selectedCreative['angle'] ?? '—' }}</p></div>
        </div>
        @if (! empty($selectedCreative['headline']))
            <div>
                <p class="text-xs text-gray-400">Primary text</p>
                <p class="text-gray-700 dark:text-gray-300">{{ $selectedCreative['headline'] }}</p>
                <p class="mt-1 text-[11px] text-violet-700 dark:text-violet-300">Untrusted creative copy · display only</p>
            </div>
        @endif
        @if (! empty($selectedCreative['persona']))
            <div>
                <p class="text-xs text-gray-400">Persona coverage</p>
                <p>{{ $selectedCreative['persona'] }}</p>
            </div>
        @endif
        @if (! empty($selectedCreative['signal']))
            <div>
                <p class="text-xs text-gray-400">Delivery signal</p>
                <p class="text-amber-700 dark:text-amber-400">{{ $selectedCreative['signal'] }}</p>
            </div>
        @endif
        <p class="text-[11px] text-gray-400">No fatigue diagnosis claimed · Meta Ads · Demo</p>
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
        <p class="text-[11px] text-gray-400">Tasks are not auto-created. No Meta Ads write actions.</p>
    </x-demo.gads-drawer>
@endif
