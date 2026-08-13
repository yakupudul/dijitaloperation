@php
    $recs = collect($recommendations);
    $glance = [
        'awaiting' => $recs->whereIn('status', ['pending', 'awaiting_decision'])->count(),
        'accepted' => $recs->whereIn('status', ['approved', 'accepted'])->count(),
        'dismissed' => $recs->whereIn('status', ['rejected', 'dismissed'])->count(),
        'has_task' => $recs->filter(fn ($r) => ($r['status'] ?? '') === 'approved' || filled($r['task_id'] ?? null))->count(),
    ];
@endphp

<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Operations',
        'title' => 'Recommendations',
        'subtitle' => 'Decision inbox — what MoxDOP thinks is worth considering. Not yet committed work.',
    ])

    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs uppercase tracking-wide text-gray-400">Awaiting Decision</p>
            <p class="mt-1 text-2xl font-bold text-warning-600 dark:text-warning-400">{{ $glance['awaiting'] }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs uppercase tracking-wide text-gray-400">Accepted</p>
            <p class="mt-1 text-2xl font-bold text-success-600 dark:text-success-400">{{ $glance['accepted'] }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs uppercase tracking-wide text-gray-400">Dismissed</p>
            <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $glance['dismissed'] }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs uppercase tracking-wide text-gray-400">Converted to Work</p>
            <p class="mt-1 text-2xl font-bold text-brand-600 dark:text-brand-400">{{ $glance['has_task'] }}</p>
        </div>
    </div>

    <div class="space-y-3">
        @forelse ($recommendations as $rec)
            <article class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ta.badge :color="match($rec['priority'] ?? 'medium') { 'critical', 'high' => 'error', default => 'warning' }" size="sm">
                                {{ strtoupper($rec['priority'] ?? 'MEDIUM') }}
                            </x-ta.badge>
                            @if (! empty($rec['asset_type']))
                                <x-demo.digital-asset-mark :type="$rec['asset_type']" size="sm" />
                            @endif
                            <x-ta.badge :color="match($rec['status']) { 'approved' => 'success', 'rejected' => 'error', default => 'warning' }" size="sm">
                                {{ $rec['status'] === 'pending' ? 'Awaiting decision' : $rec['status'] }}
                            </x-ta.badge>
                            @if (! empty($rec['ai_assisted']))
                                <x-ta.badge color="info" size="sm">AI-assisted</x-ta.badge>
                            @endif
                        </div>
                        <h3 class="mt-2 text-base font-semibold text-gray-800 dark:text-white/90">{{ $rec['title'] }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ $rec['brand'] }} · {{ $rec['asset'] }}</p>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Why surfaced · {{ $rec['why'] }}</p>
                        <p class="mt-1 text-xs text-gray-500">
                            Effort · {{ $rec['effort'] ?? '—' }}
                            · Evidence · {{ $rec['provenance'] ?? 'Deterministic' }}
                        </p>

                        @if ($expandedId === $rec['id'])
                            @php
                                $recContext = \App\Support\Demo\CommercialContextFixtures::contextForOperationalRow($rec);
                                $sourceKind = filled($rec['source_opportunity_id'] ?? null)
                                    ? 'opportunity'
                                    : (filled($rec['finding_id'] ?? null) ? 'finding' : null);
                            @endphp
                            <div class="mt-3 space-y-2">
                                <x-demo.commercial-context
                                    :service="$recContext['service']"
                                    :goal="$recContext['goal']"
                                    :offering="$recContext['offering']"
                                />
                                @if ($sourceKind === 'opportunity')
                                    <p class="text-xs text-gray-500">
                                        <span class="font-medium text-gray-700 dark:text-gray-300">{{ __('operator.commercial.source') }}:</span>
                                        {{ __('operator.commercial.source_opportunity') }}
                                        · {{ $rec['source_opportunity_id'] }}
                                    </p>
                                @elseif ($sourceKind === 'finding')
                                    <p class="text-xs text-gray-500">
                                        <span class="font-medium text-gray-700 dark:text-gray-300">{{ __('operator.commercial.source') }}:</span>
                                        {{ __('operator.commercial.source_finding') }}
                                        · {{ $rec['finding_id'] }}
                                    </p>
                                @endif
                            </div>
                            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03] sm:col-span-2">
                                    <p class="text-xs uppercase tracking-wide text-gray-400">Recommended action</p>
                                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $rec['action'] }}</p>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                                    <p class="text-xs uppercase tracking-wide text-gray-400">Verification plan</p>
                                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $rec['verification_plan'] ?? $rec['success'] }}</p>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                                    <p class="text-xs uppercase tracking-wide text-gray-400">{{ __('operator.commercial.source') }}</p>
                                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                                        @if ($sourceKind === 'opportunity')
                                            {{ __('operator.commercial.source_opportunity') }} · {{ $rec['source_opportunity_id'] }}
                                        @elseif ($sourceKind === 'finding')
                                            {{ __('operator.commercial.source_finding') }} · {{ $rec['finding_id'] }}
                                        @else
                                            —
                                        @endif
                                    </p>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                                    <p class="text-xs uppercase tracking-wide text-gray-400">Evidence</p>
                                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $rec['evidence'] }}</p>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                                    <p class="text-xs uppercase tracking-wide text-gray-400">Expected outcome</p>
                                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $rec['success'] }}</p>
                                </div>
                            </div>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-ta.button wire:click="expand('{{ $rec['id'] }}')" size="sm" variant="outline">
                            {{ $expandedId === $rec['id'] ? 'Hide' : 'Review' }}
                        </x-ta.button>
                        @if ($rec['status'] === 'pending')
                            <x-ta.button wire:click="approve('{{ $rec['id'] }}')" size="sm">Accept</x-ta.button>
                            <x-ta.button wire:click="defer('{{ $rec['id'] }}')" size="sm" variant="outline">Defer</x-ta.button>
                            <x-ta.button wire:click="reject('{{ $rec['id'] }}')" size="sm" variant="danger">Dismiss</x-ta.button>
                        @endif
                        <x-ta.button wire:click="createTask('{{ $rec['id'] }}')" size="sm" variant="outline">Create Task</x-ta.button>
                    </div>
                </div>
            </article>
        @empty
            @include('livewire.demo.partials.empty-panel', [
                'title' => 'No recommendations are awaiting decision',
                'message' => 'Recommendations appear after analysis surfaces actionable options.',
            ])
        @endforelse
    </div>
</div>
