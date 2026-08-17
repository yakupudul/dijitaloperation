@php
    $discoverySections = [
        'overview' => 'Overview',
        'facts' => 'Observed Facts',
        'candidates' => 'Candidates',
        'conflicts' => 'Conflicts',
        'sources' => 'Sources & History',
    ];
    $pendingCandidates = collect($discoveryCandidates)->where('status', 'pending')->values();
    $resolvedCandidates = collect($discoveryCandidates)->whereIn('status', ['accepted', 'mapped', 'ignored'])->values();
@endphp

<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Public Discovery</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Observe public Brand identity · Compare with canonical Brand Context · Review · Apply
            </p>
            <p class="mt-1 text-xs text-gray-400">
                Observed ≠ canonical. Derived ≠ provider fact. No silent Brand Context mutation. No external Website/GBP writes.
            </p>
        </div>
        <button
            type="button"
            wire:click="runPublicResearch"
            class="inline-flex rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600"
        >
            Refresh public observations
        </button>
    </div>

    <nav class="flex flex-wrap gap-1 border-b border-gray-200 dark:border-gray-800" aria-label="Public Discovery sections">
        @foreach ($discoverySections as $key => $label)
            <button
                type="button"
                wire:click="setDiscovery('{{ $key }}')"
                @class([
                    'rounded-t-lg px-3 py-2 text-sm font-medium transition',
                    'border-b-2 border-brand-500 text-brand-700 dark:text-brand-400' => $discovery === $key,
                    'text-gray-500 hover:text-gray-800 dark:hover:text-white/90' => $discovery !== $key,
                ])
            >
                {{ $label }}
            </button>
        @endforeach
    </nav>

    @if (($discoveryCandidates ?? []) === [] && ($discoveryFacts ?? []) === [])
        <div class="rounded-xl bg-white p-6 text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-400 dark:ring-gray-700">
            No Public Discovery candidates. Discovery has not run.
        </div>
    @else
        {{-- OVERVIEW --}}
        @if ($discovery === 'overview')
            <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
                <button type="button" wire:click="setDiscovery('facts')" class="rounded-xl bg-white p-4 text-left ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-900 dark:ring-gray-800 dark:hover:bg-white/[0.03]">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Observed facts</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $discoveryOverview['observed_facts'] }}</p>
                </button>
                <button type="button" wire:click="setDiscovery('candidates')" class="rounded-xl bg-white p-4 text-left ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-900 dark:ring-gray-800 dark:hover:bg-white/[0.03]">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Awaiting review</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $discoveryOverview['awaiting_review'] }}</p>
                </button>
                <button type="button" wire:click="setDiscovery('conflicts')" class="rounded-xl bg-white p-4 text-left ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-900 dark:ring-gray-800 dark:hover:bg-white/[0.03]">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Conflicts</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $discoveryOverview['conflicts'] }}</p>
                </button>
                <button type="button" wire:click="setDiscovery('sources')" class="rounded-xl bg-white p-4 text-left ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-900 dark:ring-gray-800 dark:hover:bg-white/[0.03]">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Accepted recently</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $discoveryOverview['accepted_recently'] }}</p>
                </button>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Public identity</h3>
                    <p class="mt-0.5 text-xs text-gray-400">Brand Context vs public sources</p>
                    <ul class="mt-3 space-y-1.5 text-sm">
                        @foreach ($discoveryPublicIdentity as $row)
                            <li class="flex items-center justify-between gap-2 border-b border-gray-50 py-1.5 last:border-0 dark:border-gray-800/60">
                                <span class="text-gray-700 dark:text-gray-300">{{ $row['field'] }}</span>
                                <span @class([
                                    'text-xs font-medium',
                                    'text-emerald-700 dark:text-emerald-400' => $row['state'] === 'Match',
                                    'text-rose-700 dark:text-rose-400' => $row['state'] === 'Conflict',
                                    'text-amber-700 dark:text-amber-400' => $row['state'] === 'Needs review',
                                ])>{{ $row['state'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>

                <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Candidate Brand updates</h3>
                        <button type="button" wire:click="setDiscovery('candidates')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">All →</button>
                    </div>
                    <ul class="mt-3 space-y-2">
                        @forelse ($pendingCandidates->take(4) as $candidate)
                            <li class="flex items-start justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-white/[0.03]">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $candidate['value'] }}</p>
                                    <p class="mt-0.5 text-[11px] text-gray-500">{{ $candidate['kind_label'] }} · {{ $candidate['source'] }} · {{ $candidate['provenance'] }}</p>
                                </div>
                                <button type="button" wire:click="openCandidate('{{ $candidate['id'] }}')" class="shrink-0 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Review</button>
                            </li>
                        @empty
                            <li class="text-sm text-gray-500">No candidates awaiting review.</li>
                        @endforelse
                    </ul>
                </section>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Source coverage</h3>
                    <ul class="mt-3 space-y-2">
                        @foreach ($discoverySources as $source)
                            <li class="flex items-center justify-between gap-3">
                                <div class="flex items-center gap-2">
                                    <x-demo.digital-asset-mark :type="$source['type']" size="sm" />
                                    <div>
                                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $source['label'] }}</p>
                                        <p class="text-[11px] text-gray-500">{{ $source['state_detail'] }}</p>
                                    </div>
                                </div>
                                <span @class([
                                    'text-xs font-medium',
                                    'text-emerald-700 dark:text-emerald-400' => $source['active'],
                                    'text-gray-400' => ! $source['active'],
                                ])>{{ $source['state'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>

                <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Recent review history</h3>
                        <button type="button" wire:click="setDiscovery('sources')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Full trail →</button>
                    </div>
                    <ol class="mt-3 space-y-2.5">
                        @foreach (collect($discoveryHistory)->take(5) as $event)
                            <li class="border-l-2 border-gray-200 pl-3 dark:border-gray-700">
                                <p class="text-[11px] text-gray-400">{{ $event['when'] }} · {{ $event['actor'] }}</p>
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $event['event'] }}</p>
                                <p class="text-xs text-gray-500">{{ $event['detail'] }}</p>
                            </li>
                        @endforeach
                    </ol>
                </section>
            </div>

            <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Top conflicts</h3>
                    <button type="button" wire:click="setDiscovery('conflicts')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Review conflicts →</button>
                </div>
                <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach (collect($discoveryConflicts)->where('resolution', 'open')->take(3) as $conflict)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2.5">
                            <div>
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $conflict['field'] }}</p>
                                <p class="text-xs text-gray-500">{{ $conflict['state'] }} · side-by-side source values available</p>
                            </div>
                            <button type="button" wire:click="openConflict('{{ $conflict['id'] }}')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Inspect →</button>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- OBSERVED FACTS --}}
        @if ($discovery === 'facts')
            <section class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <table class="min-w-full text-left text-sm">
                    <caption class="sr-only">Observed public facts with provenance</caption>
                    <thead class="border-b border-gray-100 text-xs uppercase text-gray-500 dark:border-gray-800">
                        <tr>
                            <th scope="col" class="px-4 py-2.5 font-medium">Field</th>
                            <th scope="col" class="px-4 py-2.5 font-medium">Observed value</th>
                            <th scope="col" class="px-4 py-2.5 font-medium">Source</th>
                            <th scope="col" class="px-4 py-2.5 font-medium">Provenance</th>
                            <th scope="col" class="px-4 py-2.5 font-medium">Observed at</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($discoveryFacts as $fact)
                            <tr wire:key="fact-{{ $fact['id'] }}">
                                <td class="px-4 py-2.5">
                                    <p class="font-medium text-gray-900 dark:text-white">{{ $fact['field'] }}</p>
                                    <p class="text-[11px] text-gray-400">{{ $fact['category'] }}</p>
                                </td>
                                <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300">{{ $fact['value'] }}</td>
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center gap-2">
                                        <x-demo.digital-asset-mark :type="$fact['source_type']" size="sm" />
                                        <div>
                                            <p class="text-gray-800 dark:text-white/90">{{ $fact['source'] }}</p>
                                            <p class="text-[11px] text-gray-400">{{ $fact['source_asset'] }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="inline-flex rounded-md bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300">{{ $fact['provenance'] }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-xs text-gray-500">{{ $fact['observed_at'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="border-t border-gray-100 px-4 py-3 text-xs text-gray-400 dark:border-gray-800">
                    These are public observations (evidence). They do not equal canonical Brand Context until a human Accepts or Maps them.
                </p>
            </section>
        @endif

        {{-- CANDIDATES --}}
        @if ($discovery === 'candidates')
            <div class="space-y-4">
                <section>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Awaiting review</h3>
                    <div class="mt-2 space-y-2">
                        @forelse ($pendingCandidates as $candidate)
                            <article class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" wire:key="cand-{{ $candidate['id'] }}">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <span class="text-[10px] font-bold uppercase tracking-wide text-gray-400">Candidate</span>
                                            <span class="inline-flex rounded-md bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $candidate['provenance'] }}</span>
                                            @if (! empty($candidate['ai_assisted']))
                                                <span class="inline-flex rounded-md bg-violet-50 px-1.5 py-0.5 text-[10px] font-medium text-violet-700 dark:bg-violet-500/15 dark:text-violet-300">AI-derived</span>
                                            @endif
                                        </div>
                                        <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $candidate['action_label'] }} · {{ $candidate['value'] }}</p>
                                        <p class="mt-1 text-xs text-gray-500">Sources · {{ $candidate['source'] }}</p>
                                        <p class="mt-1 text-xs text-gray-500">Current Brand Context · {{ $candidate['current_context'] }}</p>
                                        <p class="mt-1 text-xs text-gray-400">{{ $candidate['provenance_detail'] }}</p>
                                    </div>
                                    <button type="button" wire:click="openCandidate('{{ $candidate['id'] }}')" class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-600">Review</button>
                                </div>
                            </article>
                        @empty
                            <p class="text-sm text-gray-500">No pending candidates.</p>
                        @endforelse
                    </div>
                </section>

                <section>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Reviewed</h3>
                    <div class="mt-2 space-y-2">
                        @foreach ($resolvedCandidates as $candidate)
                            <article class="rounded-xl bg-white/80 p-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-900/80 dark:ring-gray-800" wire:key="cand-done-{{ $candidate['id'] }}">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $candidate['value'] }}</p>
                                        <p class="text-xs text-gray-500">
                                            {{ $candidate['kind_label'] }} · {{ $candidate['status'] }}
                                            @if (! empty($candidate['mapped_to']))
                                                · mapped to {{ $candidate['mapped_to'] }}
                                            @endif
                                            @if (! empty($candidate['ignore_reason']))
                                                · {{ $candidate['ignore_reason'] }}
                                            @endif
                                        </p>
                                    </div>
                                    <span class="text-xs font-medium uppercase text-gray-400">{{ $candidate['status'] }}</span>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            </div>
        @endif

        {{-- CONFLICTS --}}
        @if ($discovery === 'conflicts')
            <div class="space-y-3">
                @foreach ($discoveryConflicts as $conflict)
                    <article class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" wire:key="conf-{{ $conflict['id'] }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $conflict['field'] }}</h3>
                                    <span @class([
                                        'text-xs font-medium',
                                        'text-rose-700 dark:text-rose-400' => $conflict['state'] === 'Conflict',
                                        'text-amber-700 dark:text-amber-400' => in_array($conflict['state'], ['Needs review', 'Partial'], true),
                                    ])>{{ $conflict['state'] }}</span>
                                    @if (($conflict['resolution'] ?? 'open') !== 'open')
                                        <span class="text-[11px] text-gray-400">Resolved · {{ $conflict['resolution'] }}</span>
                                    @endif
                                </div>
                                <div class="mt-3 grid gap-2 sm:grid-cols-3">
                                    @foreach ($conflict['values'] as $value)
                                        <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                                            <p class="text-[11px] font-medium uppercase tracking-wide text-gray-400">{{ $value['source'] }}</p>
                                            <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $value['value'] }}</p>
                                            <p class="mt-0.5 text-[11px] text-gray-500">{{ $value['role'] }} · {{ $value['observed_at'] }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            @if (($conflict['resolution'] ?? 'open') === 'open')
                                <button type="button" wire:click="openConflict('{{ $conflict['id'] }}')" class="rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Decide →</button>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        {{-- SOURCES & HISTORY --}}
        @if ($discovery === 'sources')
            <div class="grid gap-4 lg:grid-cols-2">
                <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Sources</h3>
                    <ul class="mt-3 space-y-3">
                        @foreach ($discoverySources as $source)
                            <li class="flex items-start justify-between gap-3 border-b border-gray-50 pb-3 last:border-0 dark:border-gray-800/60">
                                <div class="flex items-start gap-2">
                                    <x-demo.digital-asset-mark :type="$source['type']" size="md" />
                                    <div>
                                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $source['label'] }}</p>
                                        <p class="text-xs text-gray-500">{{ $source['state'] }} · {{ $source['state_detail'] }}</p>
                                    </div>
                                </div>
                                <span class="text-[11px] font-medium {{ $source['active'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400' }}">
                                    {{ $source['active'] ? 'Active' : 'Future' }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-3 text-xs text-gray-400">Unsupported providers are not shown as active. Instagram remains future capability.</p>
                </section>

                <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Decision history</h3>
                    <ol class="mt-3 space-y-3">
                        @foreach ($discoveryHistory as $event)
                            <li class="border-l-2 border-brand-200 pl-3 dark:border-brand-500/40">
                                <p class="text-[11px] text-gray-400">{{ $event['when'] }} · {{ $event['actor'] }}</p>
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $event['event'] }}</p>
                                <p class="text-xs text-gray-500">{{ $event['detail'] }}</p>
                            </li>
                        @endforeach
                    </ol>
                </section>
            </div>
        @endif
    @endif

    {{-- Candidate drawer --}}
    @if ($reviewCandidate)
        <x-demo.gads-drawer :title="$reviewCandidate['value']" :subtitle="($reviewCandidate['kind_label'] ?? '').' · '.($reviewCandidate['provenance'] ?? '')">
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Observed</p>
                <p class="mt-1 text-gray-800 dark:text-white/90">{{ $reviewCandidate['observed'] ?? $reviewCandidate['value'] }}</p>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Sources</p>
                <p class="mt-1 text-gray-800 dark:text-white/90">{{ $reviewCandidate['source'] }}</p>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Current Brand Context</p>
                <p class="mt-1 text-gray-800 dark:text-white/90">{{ $reviewCandidate['current_context'] }}</p>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Suggested action</p>
                <p class="mt-1 text-gray-800 dark:text-white/90">{{ $reviewCandidate['suggested_action'] }}</p>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Provenance</p>
                <p class="mt-1 text-gray-800 dark:text-white/90">{{ $reviewCandidate['provenance'] }} — {{ $reviewCandidate['provenance_detail'] }}</p>
            </div>
            <p class="text-xs text-gray-400">Accepting records a human-approved Brand Context update. It does not write to Website or GBP.</p>

            @if (($reviewCandidate['status'] ?? '') === 'pending')
                <div class="flex flex-wrap gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <button type="button" wire:click="acceptDiscoveryCandidate('{{ $reviewCandidate['id'] }}')" class="rounded-lg bg-brand-500 px-3 py-2 text-xs font-medium text-white">Accept</button>
                    @if (! empty($reviewCandidate['map_target_label']))
                        <button type="button" wire:click="mapDiscoveryCandidate('{{ $reviewCandidate['id'] }}', '{{ $reviewCandidate['map_target_label'] }}')" class="rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">
                            Map to existing · {{ $reviewCandidate['map_target_label'] }}
                        </button>
                    @elseif (($reviewCandidate['kind'] ?? '') === 'offering')
                        <div class="w-full space-y-2">
                            <p class="text-xs font-medium text-gray-500">Map to existing Offering</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($existingOfferingsForMap as $offering)
                                    <button type="button" wire:click="mapDiscoveryCandidate('{{ $reviewCandidate['id'] }}', '{{ $offering['label'] }}')" class="rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">
                                        {{ $offering['label'] }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
                <div class="space-y-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <label for="ignore-reason" class="text-xs font-medium text-gray-500">Ignore reason</label>
                    <select id="ignore-reason" wire:model="ignoreReason" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90">
                        <option value="incorrect">Incorrect</option>
                        <option value="outdated">Outdated</option>
                        <option value="irrelevant">Irrelevant</option>
                        <option value="intentional">Intentional public variation</option>
                        <option value="duplicate">Duplicate</option>
                        <option value="other">Other</option>
                    </select>
                    <button type="button" wire:click="ignoreDiscoveryCandidate('{{ $reviewCandidate['id'] }}')" class="rounded-lg px-3 py-2 text-xs font-medium text-gray-600 hover:underline dark:text-gray-300">Ignore</button>
                </div>
            @endif
        </x-demo.gads-drawer>
    @endif

    {{-- Conflict drawer --}}
    @if ($reviewConflict)
        <x-demo.gads-drawer :title="$reviewConflict['field']" :subtitle="$reviewConflict['state']" :severity="$reviewConflict['state'] === 'Conflict' ? 'High' : 'Medium'">
            <div class="space-y-2">
                @foreach ($reviewConflict['values'] as $value)
                    <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                        <p class="text-[11px] font-medium uppercase tracking-wide text-gray-400">{{ $value['source'] }}</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $value['value'] }}</p>
                        <p class="text-[11px] text-gray-500">{{ $value['role'] }} · {{ $value['observed_at'] }}</p>
                    </div>
                @endforeach
            </div>
            <p class="text-xs text-gray-400">Do not edit Website or GBP externally from this workspace. Decisions update Demo review state only.</p>
            @if (($reviewConflict['resolution'] ?? 'open') === 'open')
                <div class="flex flex-wrap gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <button type="button" wire:click="resolveConflict('{{ $reviewConflict['id'] }}', 'keep_canonical')" class="rounded-lg bg-brand-500 px-3 py-2 text-xs font-medium text-white">Keep canonical</button>
                    <button type="button" wire:click="resolveConflict('{{ $reviewConflict['id'] }}', 'accept_source')" class="rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Accept source value</button>
                    <button type="button" wire:click="resolveConflict('{{ $reviewConflict['id'] }}', 'create_recommendation')" class="rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Create internal Recommendation</button>
                    <button type="button" wire:click="resolveConflict('{{ $reviewConflict['id'] }}', 'ignore')" class="rounded-lg px-3 py-2 text-xs font-medium text-gray-500 hover:underline">Ignore difference</button>
                </div>
            @endif
        </x-demo.gads-drawer>
    @endif
</div>
