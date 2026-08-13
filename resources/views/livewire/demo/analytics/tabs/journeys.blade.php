@php
    $journeys = $data['journeys'] ?? [];
    $isList = array_is_list($journeys);
    $paths = $isList ? $journeys : ($journeys['paths'] ?? $journeys['aggregated_paths'] ?? []);
    $funnels = $isList ? [] : ($journeys['funnels'] ?? []);
@endphp

<div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Journeys</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Aggregated paths · no PII · configured measurement only</p>
        </div>
        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-medium text-slate-600 dark:bg-white/5 dark:text-gray-300">No PII · aggregated only</span>
    </div>

    <section>
        <h3 class="mb-2 text-sm font-semibold text-gray-900 dark:text-white">Aggregated paths</h3>
        <div class="grid gap-3 lg:grid-cols-2">
            @forelse ($paths as $path)
                <article class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $path['path'] ?? $path['label'] ?? $path['name'] }}</h4>
                            <p class="mt-0.5 text-[11px] text-gray-400">
                                @if (array_key_exists('sessions', $path) && $path['sessions'] !== null)
                                    {{ number_format($path['sessions']) }} sessions
                                @else
                                    Sessions · Unavailable
                                @endif
                                @if (array_key_exists('mapped_actions', $path))
                                    ·
                                    @if ($path['mapped_actions'] === null)
                                        <span class="text-amber-700 dark:text-amber-400">actions Unavailable</span>
                                    @else
                                        {{ number_format($path['mapped_actions']) }} mapped actions
                                    @endif
                                @endif
                            </p>
                        </div>
                        @if (array_key_exists('mapped_actions', $path) && $path['mapped_actions'] === null)
                            <x-ta.badge color="warning" size="sm">Incomplete measurement</x-ta.badge>
                        @endif
                    </div>

                    @if (! empty($path['steps']))
                        <div class="mt-3 flex flex-wrap items-center gap-1.5">
                            @foreach ($path['steps'] as $i => $step)
                                @if ($i > 0)
                                    <span class="text-gray-300 dark:text-gray-600" aria-hidden="true">→</span>
                                @endif
                                <span class="inline-flex items-center rounded-lg bg-white px-2.5 py-1.5 text-xs font-medium text-gray-800 ring-1 ring-inset ring-gray-200 dark:bg-white/[0.04] dark:text-white/90 dark:ring-gray-700">
                                    {{ is_array($step) ? ($step['label'] ?? '') : $step }}
                                    @if (! empty($path['roles'][$i]))
                                        <span class="ml-1 text-gray-400">{{ $path['roles'][$i] }}</span>
                                    @endif
                                </span>
                            @endforeach
                        </div>
                    @elseif (! empty($path['lanes']))
                        <div class="mt-3 flex flex-wrap items-center gap-1.5">
                            @foreach ($path['lanes'] as $i => $lane)
                                @if ($i > 0)
                                    <span class="text-gray-300 dark:text-gray-600" aria-hidden="true">→</span>
                                @endif
                                <span @class([
                                    'inline-flex items-center rounded-lg px-2.5 py-1.5 text-xs font-medium ring-1 ring-inset',
                                    'bg-slate-50 text-slate-400 ring-slate-200 dark:bg-white/[0.02] dark:ring-gray-700' => in_array($lane['state'] ?? '', ['Missing', 'Incomplete', 'Not configured'], true),
                                    'bg-white text-gray-800 ring-gray-200 dark:bg-white/[0.04] dark:text-white/90 dark:ring-gray-700' => ! in_array($lane['state'] ?? '', ['Missing', 'Incomplete', 'Not configured'], true),
                                ])>
                                    {{ $lane['label'] }}
                                </span>
                            @endforeach
                        </div>
                    @endif

                    @if (! empty($path['note']))
                        <p class="mt-2 text-[11px] text-amber-700 dark:text-amber-400">{{ $path['note'] }}</p>
                    @endif
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-gray-200 px-4 py-6 text-sm text-gray-500 dark:border-gray-700 lg:col-span-2">
                    No aggregated paths for this period
                </div>
            @endforelse
        </div>
    </section>

    @if ($funnels !== [])
        <section>
            <div class="mb-2 flex items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Configured funnels</h3>
                <span class="text-[11px] text-gray-400">Configured only · not invented</span>
            </div>
            <div class="space-y-3">
                @foreach ($funnels as $funnel)
                    <article class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $funnel['name'] }}</h4>
                            <div class="flex flex-wrap items-center gap-1.5">
                                @if (! empty($funnel['configured']))
                                    <x-ta.badge color="success" size="sm">Configured</x-ta.badge>
                                @endif
                                @if (! empty($funnel['incomplete']) || ($funnel['state'] ?? '') === 'Incomplete measurement')
                                    <x-ta.badge color="warning" size="sm">{{ $funnel['state'] ?? 'Incomplete measurement' }}</x-ta.badge>
                                @endif
                            </div>
                        </div>
                        @if (! empty($funnel['steps']))
                            <ol class="mt-3 space-y-2">
                                @foreach ($funnel['steps'] as $step)
                                    <li class="flex items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm dark:bg-white/[0.03]">
                                        <span class="text-gray-700 dark:text-gray-300">{{ $step['label'] }}</span>
                                        <span @class([
                                            'font-semibold tabular-nums',
                                            'text-slate-400' => in_array($step['state'] ?? '', ['Missing', 'Incomplete', 'Not configured'], true),
                                            'text-gray-900 dark:text-white' => ! in_array($step['state'] ?? '', ['Missing', 'Incomplete', 'Not configured'], true),
                                        ])>
                                            @if (in_array($step['state'] ?? '', ['Missing', 'Incomplete', 'Not configured'], true))
                                                {{ $step['state'] }}
                                            @elseif (array_key_exists('value', $step) && $step['value'] !== null)
                                                {{ $step['value'] }}
                                            @else
                                                Unavailable
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
    @endif
</div>
