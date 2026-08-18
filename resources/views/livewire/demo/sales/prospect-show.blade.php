@php
    $prospect = $detail['prospect'];
    $intelligence = $detail['sales_intelligence'] ?? null;
@endphp

<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.nav.groups.sales') }}</p>
            <h1 class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $prospect['company_name'] }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $prospect['source_label'] }} · {{ $prospect['status_label'] }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="researchProspect" wire:loading.attr="disabled"
                @disabled(!($prospect['can_research'] ?? true))
                class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-60">
                {{ __('operator.prospects.research_prospect') }}
            </button>
        </div>
    </div>

    <div class="border-b border-gray-200 dark:border-gray-700">
        <nav class="-mb-px flex gap-4 overflow-x-auto" aria-label="Prospect tabs">
            @foreach ([
                'overview' => __('operator.prospects.tabs.overview'),
                'research' => __('operator.prospects.tabs.research'),
                'intelligence' => __('operator.prospects.tabs.intelligence'),
                'activity' => __('operator.prospects.tabs.activity'),
            ] as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')"
                    class="whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium {{ $tab === $key ? 'border-brand-500 text-brand-600' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400' }}">
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    </div>

    @if ($tab === 'overview')
        @include('livewire.demo.sales.partials.prospect-overview', ['prospect' => $prospect, 'statusOptions' => $statusOptions, 'identityOptions' => $identityOptions])
    @elseif ($tab === 'research')
        @include('livewire.demo.sales.partials.prospect-research', ['detail' => $detail])
    @elseif ($tab === 'intelligence')
        @include('livewire.demo.sales.partials.prospect-sales-intelligence', ['intelligence' => $intelligence])
    @else
        @include('livewire.demo.sales.partials.prospect-activity', ['activities' => $detail['activities'] ?? []])
    @endif
</div>
