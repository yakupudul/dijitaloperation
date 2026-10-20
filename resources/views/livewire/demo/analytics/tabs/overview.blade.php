@php
    $glance = $data['glance'] ?? [];
    $acqBars = $data['acquisition_mix'] ?? [];
    $landingPulse = $data['landing_pulse'] ?? [];
    $actionMatrix = $data['business_actions'] ?? [];
    $maxAcq = max(1, (float) collect($acqBars)->max('sessions'));
@endphp

<div class="space-y-10">
    <div class="grid grid-cols-2 gap-x-8 gap-y-6 sm:grid-cols-3 xl:grid-cols-4">
        <x-ta.metric-card label="Kullanıcı" :value="$glance['users']['value'] ?? '—'" :delta="$glance['users']['secondary'] ?? null" :tone="$glance['users']['tone'] ?? 'neutral'" />
        <x-ta.metric-card label="Oturum" :value="$glance['sessions']['value'] ?? '—'" :delta="$glance['sessions']['secondary'] ?? null" :tone="$glance['sessions']['tone'] ?? 'neutral'" />
        <x-ta.metric-card label="İş aksiyonu" :value="$glance['business_actions']['value'] ?? '—'" :delta="$glance['business_actions']['secondary'] ?? null" :tone="$glance['business_actions']['tone'] ?? 'neutral'" />
        <x-ta.metric-card label="Ölçüm" :value="$glance['measurement_state']['value'] ?? '—'" :delta="$glance['measurement_state']['secondary'] ?? null" :tone="$glance['measurement_state']['tone'] ?? 'warning'" />
    </div>

    <div class="grid gap-10 lg:grid-cols-12">
        <section class="min-w-0 lg:col-span-7">
            <div class="mb-2 flex items-center justify-between gap-2">
                <h2 class="section-title">Needs attention</h2>
                <span class="text-xs text-gray-500">{{ count($data['needs_attention'] ?? []) }} signals</span>
            </div>
            <ul class="divide-y divide-gray-200 dark:divide-gray-800">
                @foreach ($data['needs_attention'] ?? [] as $item)
                    <li class="flex items-center justify-between gap-3 py-2">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <x-ta.pill :tone="['error' => 'critical', 'warning' => 'look', 'success' => 'ok'][match($item['severity'] ?? '') { 'Critical', 'critical', 'High', 'high' => 'error', 'Medium', 'medium' => 'warning', default => 'light' }] ?? 'info'">{{ $item['severity'] }}</x-ta.pill>
                                <span class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $item['title'] }}</span>
                            </div>
                            <p class="mt-0.5 truncate text-xs text-gray-600 dark:text-gray-300">{{ $item['metric'] }}</p>
                            <p class="truncate text-xs text-gray-500">{{ $item['scope'] ?? '' }}</p>
                        </div>
                        <button type="button" wire:click="openAttention('{{ $item['id'] }}')" class="link-neutral shrink-0 text-xs">{{ $item['action'] ?? 'Inspect' }}</button>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="min-w-0 lg:col-span-5">
            <div class="mb-2 flex items-center justify-between gap-2">
                <h2 class="section-title">Acquisition mix</h2>
                <button type="button" wire:click="setTab('acquisition')" class="link-neutral text-xs">Open</button>
            </div>
            <ul class="space-y-2">
                @foreach ($acqBars as $row)
                    <li>
                        <div class="mb-1 flex items-center justify-between gap-2 text-xs">
                            <span class="font-medium text-gray-800 dark:text-white/90">{{ $row['channel'] }}</span>
                            <span class="tabular-nums text-gray-500">{{ number_format($row['sessions']) }}, {{ $row['share_pct'] }}%</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5">
                            <div class="h-full rounded-full bg-gray-400" style="width: {{ min(100, $row['bar'] ?? round(($row['sessions'] / $maxAcq) * 100)) }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    <section class="min-w-0">
        <div class="mb-2 flex items-center justify-between gap-2">
            <h2 class="section-title">Sessions trend</h2>
            <span class="text-xs text-gray-500">{{ $data['period_label'] ?? '' }}, {{ $data['compare_label'] ?? $data['performance_trend']['note'] ?? '' }}</span>
        </div>
        <div data-chart='@json($performanceChartOptions)' aria-label="Sessions and mapped business actions trend" class="min-h-[220px]"></div>
    </section>

    <section>
        <div class="mb-2 flex items-center justify-between">
            <h2 class="section-title">Landing pulse</h2>
            <button type="button" wire:click="setTab('behavior')" class="link-neutral text-xs">Open behavior</button>
        </div>
        <div class="max-w-full overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-gray-200 dark:border-gray-800">
                    <tr>
                <th class="px-3 py-2 text-left text-xs font-normal text-gray-500">Landing</th>
                <th class="px-3 py-2 text-left text-xs font-normal text-gray-500">Sessions</th>
                <th class="px-3 py-2 text-left text-xs font-normal text-gray-500">Engaged</th>
                <th class="px-3 py-2 text-left text-xs font-normal text-gray-500">Actions</th>
                <th class="px-3 py-2 text-left text-xs font-normal text-gray-500">Attention</th>
                <th class="px-3 py-2 text-left text-xs font-normal text-gray-500"></th>
            </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
            @foreach ($landingPulse as $row)
                <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                    <td class="px-3 py-2">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $row['path'] }}</p>
                        <p class="text-xs text-gray-500">{{ $row['content_role'] }}, {{ $row['title'] ?? '' }}</p>
                    </td>
                    <td class="px-3 py-2 text-sm tabular-nums">{{ number_format($row['sessions']) }}</td>
                    <td class="px-3 py-2 text-sm tabular-nums">{{ $row['engaged_rate'] }}%</td>
                    <td class="px-3 py-2 text-sm tabular-nums">{{ number_format($row['mapped_actions']) }}</td>
                    <td class="px-3 py-2 text-xs text-gray-500">{{ $row['attention'] ?? '—' }}</td>
                    <td class="px-3 py-2"><button type="button" wire:click="openLanding('{{ $row['path'] }}')" class="link-neutral text-xs">Inspect</button></td>
                </tr>
            @endforeach
        </tbody>
            </table>
        </div>
    </section>

    <div class="grid gap-10 lg:grid-cols-2">
        <section class="min-w-0">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="section-title">Business action matrix</h2>
                <button type="button" wire:click="setMeasSub('business_actions')" class="link-neutral text-xs">Measurement</button>
            </div>
            <ul class="space-y-2">
                @foreach ($actionMatrix as $row)
                    <li class="flex items-center justify-between gap-2">
                        <button type="button" wire:click="openAction('{{ $row['action'] }}')" class="min-w-0 text-left">
                            <span class="block truncate text-sm font-medium text-gray-900 dark:text-white">{{ $row['action'] }}</span>
                            <span class="block truncate text-xs text-gray-500">{{ $row['ga4_event'] ?? 'Unavailable' }}, {{ $row['role'] }}</span>
                        </button>
                        <x-ta.pill :tone="['error' => 'critical', 'warning' => 'look', 'success' => 'ok'][match($row['state'] ?? '') { 'Healthy' => 'success', 'Review', 'Not mapped', 'Partial' => 'warning', 'Broken', 'Unavailable' => 'error', default => 'light' }] ?? 'info'">{{ $row['state'] }}</x-ta.pill>
                    </li>
                @endforeach
            </ul>
            <p class="mt-3 text-xs font-medium text-gray-700 dark:text-gray-300">{{ $data['missing_note'] ?? 'Missing ≠ zero' }}</p>
        </section>

        <section class="min-w-0">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="section-title">Journey snapshot</h2>
                <button type="button" wire:click="setTab('journeys')" class="link-neutral text-xs">Open journeys</button>
            </div>
            <ul class="space-y-3">
                @foreach (array_slice($data['journeys'] ?? [], 0, 4) as $journey)
                    <li class="border-b border-gray-200 py-2.5 dark:border-gray-800">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $journey['path'] }}</span>
                            <span class="text-xs tabular-nums text-gray-500">{{ number_format($journey['sessions']) }}</span>
                        </div>
                        <div class="mt-1.5 flex flex-wrap items-center gap-1 text-xs text-gray-500">
                            @foreach ($journey['steps'] ?? [] as $i => $step)
                                @if ($i > 0)<span class="text-gray-400" aria-hidden="true">›</span>@endif
                                <span>{{ $step }}</span>
                            @endforeach
                        </div>
                        @if (array_key_exists('mapped_actions', $journey) && $journey['mapped_actions'] === null)
                            <p class="mt-1 text-xs text-warning-700 dark:text-warning-400">{{ $journey['note'] ?? 'Mapped actions unavailable' }}</p>
                        @elseif (! empty($journey['note']))
                            <p class="mt-1 text-xs text-warning-700 dark:text-warning-400">{{ $journey['note'] }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="min-w-0 lg:col-span-2">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="section-title">Recent outcomes</h2>
                <button type="button" wire:click="setOps('outcomes')" class="link-neutral text-xs">Operations</button>
            </div>
            <ul class="space-y-2">
                @foreach ($data['recent_outcomes'] ?? [] as $o)
                    <li class="flex items-center justify-between gap-2 text-sm">
                        <span class="text-gray-800 dark:text-white/90">{{ $o['title'] }}</span>
                        <span @class([
                            'shrink-0 text-xs font-semibold',
                            'text-success-700 dark:text-success-400' => ($o['state'] ?? '') === 'Improvement observed',
                            'text-warning-700 dark:text-warning-400' => ($o['state'] ?? '') !== 'Improvement observed',
                        ])>{{ $o['state'] }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    @include('livewire.demo.analytics.tabs.relationships')

</div>
