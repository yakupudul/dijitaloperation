@php
    $journeys = $data['journeys'] ?? [];
    $isList = array_is_list($journeys);
    $paths = $isList ? $journeys : ($journeys['paths'] ?? $journeys['aggregated_paths'] ?? []);
    $funnels = $isList ? [] : ($journeys['funnels'] ?? []);
@endphp

<div class="space-y-10">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
            <h2 class="section-title" title="Aggregated paths, no PII, configured measurement only">Journeys</h2>
        </div>
        <span class="text-xs text-gray-500">No PII, aggregated only</span>
    </div>

    <section>
        <h3 class="mb-2 section-title">Aggregated paths</h3>
        <div class="grid gap-10 lg:grid-cols-2">
            @forelse ($paths as $path)
                <article class="min-w-0">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $path['path'] ?? $path['label'] ?? $path['name'] }}</h4>
                            <p class="mt-0.5 text-xs text-gray-500">
                                @if (array_key_exists('sessions', $path) && $path['sessions'] !== null)
                                    {{ number_format($path['sessions']) }} sessions
                                @else
                                    Sessions, Unavailable
                                @endif
                                @if (array_key_exists('mapped_actions', $path))
                                    <span class="ml-2"></span>
                                    @if ($path['mapped_actions'] === null)
                                        <span class="text-warning-700 dark:text-warning-400">actions Unavailable</span>
                                    @else
                                        {{ number_format($path['mapped_actions']) }} mapped actions
                                    @endif
                                @endif
                            </p>
                        </div>
                        @if (array_key_exists('mapped_actions', $path) && $path['mapped_actions'] === null)
                            <x-ta.pill tone="look">Incomplete measurement</x-ta.pill>
                        @endif
                    </div>

                    @if (! empty($path['steps']))
                        <div class="mt-3 flex flex-wrap items-center gap-1.5">
                            @foreach ($path['steps'] as $i => $step)
                                @if ($i > 0)
                                    <span class="text-gray-400" aria-hidden="true">›</span>
                                @endif
                                <span class="inline-flex items-center text-xs font-medium text-gray-800 dark:text-white/90">
                                    {{ is_array($step) ? ($step['label'] ?? '') : $step }}
                                    @if (! empty($path['roles'][$i]))
                                        <span class="ml-1 text-gray-500">{{ $path['roles'][$i] }}</span>
                                    @endif
                                </span>
                            @endforeach
                        </div>
                    @elseif (! empty($path['lanes']))
                        <div class="mt-3 flex flex-wrap items-center gap-1.5">
                            @foreach ($path['lanes'] as $i => $lane)
                                @if ($i > 0)
                                    <span class="text-gray-400" aria-hidden="true">›</span>
                                @endif
                                <span @class([
                                    'inline-flex items-center text-xs font-medium',
                                    'text-gray-400' => in_array($lane['state'] ?? '', ['Missing', 'Incomplete', 'Not configured'], true),
                                    'text-gray-800 dark:text-white/90' => ! in_array($lane['state'] ?? '', ['Missing', 'Incomplete', 'Not configured'], true),
                                ])>
                                    {{ $lane['label'] }}
                                </span>
                            @endforeach
                        </div>
                    @endif

                    @if (! empty($path['note']))
                        <p class="mt-2 text-xs text-warning-700 dark:text-warning-400">{{ $path['note'] }}</p>
                    @endif
                </article>
            @empty
                <div class="py-6 text-sm text-gray-500 dark:border-gray-700 lg:col-span-2">
                    No aggregated paths for this period
                </div>
            @endforelse
        </div>
    </section>

    @if ($funnels !== [])
        <section>
            <div class="mb-2 flex items-center justify-between gap-2">
                <h3 class="section-title">Configured funnels</h3>
                <span class="text-xs text-gray-500">Configured only, not invented</span>
            </div>
            <div class="space-y-3">
                @foreach ($funnels as $funnel)
                    <article class="min-w-0">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $funnel['name'] }}</h4>
                            <div class="flex flex-wrap items-center gap-1.5">
                                @if (! empty($funnel['configured']))
                                    <x-ta.pill tone="ok">Configured</x-ta.pill>
                                @endif
                                @if (! empty($funnel['incomplete']) || ($funnel['state'] ?? '') === 'Incomplete measurement')
                                    <x-ta.pill tone="look">{{ $funnel['state'] ?? 'Incomplete measurement' }}</x-ta.pill>
                                @endif
                            </div>
                        </div>
                        @if (! empty($funnel['steps']))
                            <ol class="mt-3 space-y-2">
                                @foreach ($funnel['steps'] as $step)
                                    <li class="flex items-center justify-between gap-2 border-b border-gray-200 py-2.5 text-sm dark:border-gray-800">
                                        <span class="text-gray-700 dark:text-gray-300">{{ $step['label'] }}</span>
                                        <span @class([
                                            'font-semibold tabular-nums',
                                            'text-gray-400' => in_array($step['state'] ?? '', ['Missing', 'Incomplete', 'Not configured'], true),
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
