@php
    $journeys = $data['journeys'] ?? [];
    $isList = array_is_list($journeys);
    $paths = $isList ? $journeys : ($journeys['paths'] ?? $journeys['aggregated_paths'] ?? []);
    $funnels = $isList ? [] : ($journeys['funnels'] ?? []);
    $absent = ['Missing', 'Incomplete', 'Not configured'];
    $stepLabel = fn (mixed $step): string => is_array($step) ? (string) ($step['label'] ?? '') : (string) $step;
@endphp

<div class="space-y-10">
    {{-- 1. The usual paths through the site (aggregated, no personal data). --}}
    <section>
        <div class="section-head">
            <h2 class="section-title" title="Toplu yollar; kişisel veri yok, yalnız kurulu ölçüm">Ziyaret yolları <span class="section-count">{{ count($paths) }}</span></h2>
            <span class="text-[13px] text-gray-500">Toplu, kişisel veri yok</span>
        </div>
        <ul class="card">
            @forelse ($paths as $path)
                @php $missingActions = array_key_exists('mapped_actions', $path) && $path['mapped_actions'] === null; @endphp
                <li class="card-row flex-wrap items-start sm:flex-nowrap">
                    <div class="min-w-0 flex-1">
                        @if (! empty($path['steps']))
                            <p class="flex flex-wrap items-center gap-x-1.5 text-sm font-medium text-gray-900 dark:text-white">
                                @foreach ($path['steps'] as $i => $step)
                                    @if ($i > 0)<span class="font-normal text-gray-400" aria-hidden="true">›</span>@endif
                                    <span title="{{ $say($path['roles'][$i] ?? null) }}">{{ $stepLabel($step) }}</span>
                                @endforeach
                            </p>
                        @elseif (! empty($path['lanes']))
                            <p class="flex flex-wrap items-center gap-x-1.5 text-sm font-medium">
                                @foreach ($path['lanes'] as $i => $lane)
                                    @if ($i > 0)<span class="font-normal text-gray-400" aria-hidden="true">›</span>@endif
                                    <span @class(['text-gray-400' => in_array($lane['state'] ?? '', $absent, true), 'text-gray-900 dark:text-white' => ! in_array($lane['state'] ?? '', $absent, true)])>{{ $lane['label'] }}</span>
                                @endforeach
                            </p>
                        @else
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $path['path'] ?? $path['label'] ?? $path['name'] ?? '' }}</p>
                        @endif
                        <p class="mt-0.5 text-[13px] tabular-nums text-gray-500">
                            {{ $count($path, 'sessions') }} oturum{{ array_key_exists('mapped_actions', $path) ? ', '.($missingActions ? 'aksiyon verisi yok' : $count($path, 'mapped_actions').' aksiyon') : '' }}
                        </p>
                        @if (! empty($path['note']))
                            <p class="mt-0.5 text-[13px] text-warning-700 dark:text-warning-400">{{ $path['note'] }}</p>
                        @endif
                    </div>
                    @if ($missingActions)
                        <x-ta.pill tone="look">Ölçüm eksik</x-ta.pill>
                    @endif
                </li>
            @empty
                <li><x-ta.empty-state icon="none" title="Bu dönem için yol verisi yok" /></li>
            @endforelse
        </ul>
    </section>

    {{-- 2. Funnels set up in Analytics (never invented here). --}}
    @if ($funnels !== [])
        <section>
            <div class="section-head">
                <h2 class="section-title">Kurulu huniler <span class="section-count">{{ count($funnels) }}</span></h2>
                <span class="text-[13px] text-gray-500">Yalnız kurulmuş olanlar</span>
            </div>
            <div class="space-y-4">
                @foreach ($funnels as $funnel)
                    <div class="card">
                        <div class="card-row">
                            <p class="min-w-0 flex-1 text-sm font-medium text-gray-900 dark:text-white">{{ $funnel['name'] }}</p>
                            @if (! empty($funnel['configured']))
                                <x-ta.pill tone="ok">Kurulu</x-ta.pill>
                            @endif
                            @if (! empty($funnel['incomplete']) || ($funnel['state'] ?? '') === 'Incomplete measurement')
                                <x-ta.pill tone="look">{{ $say($funnel['state'] ?? 'Incomplete measurement') }}</x-ta.pill>
                            @endif
                        </div>
                        @foreach ($funnel['steps'] ?? [] as $step)
                            @php $stepAbsent = in_array($step['state'] ?? '', $absent, true); @endphp
                            <div class="card-row py-2.5 text-sm">
                                <span class="min-w-0 flex-1 text-gray-700 dark:text-gray-300">{{ $step['label'] }}</span>
                                <span @class(['tabular-nums', 'text-gray-400' => $stepAbsent, 'font-medium text-gray-900 dark:text-white' => ! $stepAbsent])>
                                    @if ($stepAbsent)
                                        {{ $say($step['state']) }}
                                    @elseif (array_key_exists('value', $step) && $step['value'] !== null)
                                        {{ $step['value'] }}
                                    @else
                                        Veri yok
                                    @endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</div>
