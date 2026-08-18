@php
    $search = $data['search'];
    $maxIntent = 100;
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ 'Search & demand' }}</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $search['subtitle'] }}</p>
        <p class="mt-1 text-xs text-violet-700 dark:text-violet-300">Intent · {{ $search['intent_provenance'] }}</p>
    </div>

    <div class="inline-flex rounded-lg ring-1 ring-inset ring-gray-300 dark:ring-gray-700" role="tablist">
        @foreach (['terms' => 'Search terms', 'keywords' => 'Keyword coverage', 'inbox' => 'Decision Inbox', 'drift' => 'Intent trends'] as $key => $label)
            <button type="button" wire:click="setSearchSub('{{ $key }}')" @class([
                'px-3 py-2 text-xs font-medium',
                'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white' => $search_sub === $key,
                'text-gray-600 dark:text-gray-300' => $search_sub !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($search_sub === 'inbox')
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-ta.metric-card label="Negative candidates" :value="(string) $search['inbox_summary']['negative']" />
            <x-ta.metric-card label="Keyword candidates" :value="(string) $search['inbox_summary']['keyword']" />
            <x-ta.metric-card label="Content opportunities" :value="(string) $search['inbox_summary']['content']" />
            <x-ta.metric-card label="Strategy-review" :value="(string) $search['inbox_summary']['strategy']" />
        </div>
        <ul class="space-y-2">
            @foreach ($search['clusters'] as $cluster)
                <li class="flex items-center justify-between gap-3 rounded-2xl border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-white/[0.03]">
                    <div>
                        <x-ta.badge :color="match($cluster['type']) { 'Negative candidate' => 'error', 'Content opportunity' => 'info', default => 'warning' }" size="sm">{{ $cluster['type'] }}</x-ta.badge>
                        <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $cluster['title'] }}</p>
                        <p class="text-xs text-gray-500">{{ $cluster['campaign'] }} · ₺{{ number_format($cluster['spend']) }} · {{ $cluster['terms'] }} terms</p>
                    </div>
                    <button type="button" wire:click="openCluster('{{ $cluster['id'] }}')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Review cluster</button>
                </li>
            @endforeach
        </ul>
        <p class="text-xs text-gray-400">Accept / dismiss are internal decisions only — no Google Ads write.</p>
    @elseif ($search_sub === 'drift')
        <div class="grid gap-3 lg:grid-cols-2">
            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Search intent distribution</h3>
                <ul class="mt-3 space-y-2">
                    @foreach ($search['intent_distribution'] as $row)
                        <li>
                            <div class="mb-1 flex justify-between text-xs"><span>{{ $row['label'] }}</span><span>{{ $row['pct'] }}%</span></div>
                            <x-ta.progress-bar :value="$row['pct']" :max="$maxIntent" tone="primary" />
                        </li>
                    @endforeach
                </ul>
            </section>
            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Search intent drift</h3>
                <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">Search intent drift detected</p>
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($search['intent_drift'] as $row)
                        <li class="flex justify-between gap-2"><span>{{ $row['label'] }}</span><span class="tabular-nums font-medium">{{ $row['from'] }}% → {{ $row['to'] }}%</span></li>
                    @endforeach
                </ul>
            </section>
            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03] lg:col-span-2">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Spend requiring review</h3>
                <p class="mt-1 text-xs text-gray-400">Categories may overlap — not summed into a fake total.</p>
                <ul class="mt-3 grid gap-2 sm:grid-cols-2">
                    @foreach ($search['reviewable_spend'] as $row)
                        <li class="flex justify-between rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/[0.03]">
                            <span>{{ $row['label'] }}</span>
                            <span class="font-semibold tabular-nums">₺{{ number_format($row['amount']) }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>
    @elseif ($search_sub === 'keywords')
        <x-ta.table>
            <x-slot:head>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Keyword</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Match</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Spend</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Leads</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Search-term coverage</th>
            </x-slot:head>
            @foreach ($search['keywords'] as $kw)
                <tr>
                    <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $kw['keyword'] }}</td>
                    <td class="px-4 py-2.5 text-xs">{{ $kw['match'] }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">₺{{ number_format($kw['spend']) }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ $kw['leads'] }}</td>
                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ $kw['observed'] }} observed · {{ $kw['aligned'] }} aligned · {{ $kw['review'] }} review · {{ $kw['misaligned'] }} misaligned</td>
                </tr>
            @endforeach
        </x-ta.table>
    @else
        <div class="flex flex-wrap gap-2">
            <label class="text-xs text-gray-500">Intent
                <select wire:model.live="intent_filter" class="mt-1 block rounded-lg border-gray-200 text-sm dark:border-gray-700 dark:bg-gray-900">
                    <option value="all">All</option>
                    @foreach (collect($search['terms'])->pluck('intent')->unique()->sort() as $intent)
                        <option value="{{ $intent }}">{{ $intent }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-xs text-gray-500">Strategy fit
                <select wire:model.live="fit_filter" class="mt-1 block rounded-lg border-gray-200 text-sm dark:border-gray-700 dark:bg-gray-900">
                    <option value="all">All</option>
                    <option value="Aligned">Aligned</option>
                    <option value="Review">Review</option>
                    <option value="Misaligned">Misaligned</option>
                </select>
            </label>
            <label class="text-xs text-gray-500">Decision
                <select wire:model.live="decision_filter" class="mt-1 block rounded-lg border-gray-200 text-sm dark:border-gray-700 dark:bg-gray-900">
                    <option value="all">All</option>
                    <option value="None">None</option>
                    <option value="Negative candidate">Negative candidate</option>
                    <option value="Keyword candidate">Keyword candidate</option>
                    <option value="Content opportunity">Content opportunity</option>
                    <option value="Strategy review">Strategy review</option>
                    <option value="Monitor">Monitor</option>
                </select>
            </label>
        </div>

        <x-ta.table>
            <x-slot:head>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Search term</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Campaign</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Spend</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Clicks</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Leads</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Intent</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Strategy fit</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Decision</th>
            </x-slot:head>
            @foreach ($termRows as $row)
                <tr>
                    <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $row['term'] }}</td>
                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ $row['campaign'] }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">₺{{ number_format($row['spend']) }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ $row['clicks'] }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ $row['leads'] }}</td>
                    <td class="px-4 py-2.5 text-xs">{{ $row['intent'] }} <span class="text-violet-600 dark:text-violet-300">Derived</span></td>
                    <td class="px-4 py-2.5"><x-ta.badge :color="match($row['fit']) { 'Aligned' => 'success', 'Misaligned' => 'error', default => 'warning' }" size="sm">{{ $row['fit'] }}</x-ta.badge></td>
                    <td class="px-4 py-2.5 text-xs">{{ $row['decision'] }}</td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif
</div>

@if ($selectedCluster)
    <x-demo.gads-drawer :title="$selectedCluster['title']" :subtitle="$selectedCluster['campaign']" :severity="$selectedCluster['type']">
        <div>
            <p class="text-xs text-gray-400">Why surfaced</p>
            <p class="text-gray-800 dark:text-white/90">{{ $selectedCluster['why'] }}</p>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div><p class="text-xs text-gray-400">Spend</p><p class="font-semibold">₺{{ number_format($selectedCluster['spend']) }}</p></div>
            <div><p class="text-xs text-gray-400">Related terms</p><p class="font-semibold">{{ $selectedCluster['terms'] }}</p></div>
        </div>
        <div>
            <p class="text-xs text-gray-400">Evidence terms</p>
            <ul class="mt-1 list-disc pl-4 text-sm text-gray-700 dark:text-gray-300">
                @foreach ($selectedCluster['terms_list'] as $t)
                    <li>{{ $t }}</li>
                @endforeach
            </ul>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="markClusterReviewed('{{ $selectedCluster['id'] }}')" class="rounded-lg bg-brand-500 px-3 py-2 text-xs font-medium text-white">Mark reviewed</button>
            <button type="button" wire:click="createRecommendation('{{ $selectedCluster['title'] }}')" class="rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Create Recommendation</button>
            @if ($selectedCluster['type'] === 'Content opportunity')
                <a href="{{ route('operator.website', ['tab' => 'content']) }}" wire:navigate class="rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Open in Website</a>
            @endif
        </div>
        <p class="text-[11px] text-gray-400">External Google Ads keyword writes remain disabled.</p>
    </x-demo.gads-drawer>
@endif
