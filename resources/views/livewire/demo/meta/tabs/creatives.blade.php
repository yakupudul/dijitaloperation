@php
    $creatives = $data['creatives'];
    $maxAngle = max(1, (float) collect($creatives['angles'] ?? [])->max('spend'));
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Creatives</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $creatives['subtitle'] }}</p>
    </div>

    <div class="flex flex-wrap gap-2">
        <label class="text-xs text-gray-500">Format
            <select wire:model.live="creative_filter" class="mt-1 block rounded-lg border-gray-200 text-sm dark:border-gray-700 dark:bg-gray-900">
                <option value="all">All formats</option>
                <option value="image">Image</option>
                <option value="video">Video</option>
                <option value="carousel">Carousel</option>
                <option value="attention">With attention</option>
            </select>
        </label>
        @if (! empty($creatives['filters']))
            @foreach ($creatives['filters'] as $filter)
                <label class="text-xs text-gray-500">{{ $filter['label'] }}
                    <select wire:change="setCreativeFilter('{{ $filter['key'] }}', $event.target.value)" class="mt-1 block rounded-lg border-gray-200 text-sm dark:border-gray-700 dark:bg-gray-900">
                        @foreach ($filter['options'] as $opt)
                            <option value="{{ $opt['value'] }}" @selected(($filter['value'] ?? 'all') === $opt['value'])>{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                </label>
            @endforeach
        @endif
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($creativeRows as $cr)
            <button type="button" wire:click="openCreative('{{ $cr['id'] }}')" class="overflow-hidden rounded-2xl border border-gray-200 bg-white text-left transition hover:bg-gray-50 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:bg-white/[0.05]">
                <div class="relative">
                    <x-demo.meta-creative-thumb :gradient="$cr['thumb']" :name="$cr['name']" class="h-36 !aspect-auto" />
                    <span class="absolute left-2 top-2 rounded-md bg-black/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">{{ $cr['format'] }}</span>
                    @if (! empty($cr['signal']))
                        <span class="absolute bottom-2 right-2 rounded-md bg-amber-500/90 px-2 py-0.5 text-[10px] font-semibold text-white">{{ $cr['signal'] }}</span>
                    @endif
                </div>
                <div class="space-y-2 p-3">
                    <div>
                        <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $cr['name'] }}</p>
                        <p class="truncate text-[11px] text-gray-400">{{ $cr['campaign'] }}</p>
                    </div>
                    @if (! empty($cr['headline']))
                        <p class="line-clamp-2 text-xs text-gray-600 dark:text-gray-300">{{ $cr['headline'] }}</p>
                    @endif
                    <dl class="grid grid-cols-2 gap-2 text-xs">
                        <div><dt class="text-gray-400">Spend</dt><dd class="font-medium tabular-nums">₺{{ number_format($cr['spend']) }}</dd></div>
                        <div><dt class="text-gray-400">{{ $cr['result_label'] }}</dt><dd class="font-medium tabular-nums">{{ number_format($cr['result'] ?? $cr['results'] ?? 0) }}</dd></div>
                        <div><dt class="text-gray-400">Cost / result</dt><dd class="font-medium tabular-nums">₺{{ number_format($cr['cost_result']) }}</dd></div>
                        <div><dt class="text-gray-400">Angle</dt><dd class="font-medium">{{ $cr['angle'] ?? '—' }}</dd></div>
                    </dl>
                </div>
            </button>
        @endforeach
    </div>

    <div class="grid gap-3 lg:grid-cols-12">
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03] lg:col-span-7">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Angle performance</h3>
            <p class="mt-0.5 text-[11px] text-gray-400">Spend by creative angle · typed results stay separate</p>
            <x-ta.table class="mt-3 border-0">
                <x-slot:head>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Angle</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Creatives</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Spend</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Result</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Share</th>
                </x-slot:head>
                @foreach ($creatives['angles'] ?? [] as $angle)
                    <tr>
                        <td class="px-3 py-2 text-sm font-medium text-gray-900 dark:text-white">{{ $angle['label'] }}</td>
                        <td class="px-3 py-2 text-xs tabular-nums">{{ $angle['creatives'] }}</td>
                        <td class="px-3 py-2 text-sm tabular-nums">₺{{ number_format($angle['spend']) }}</td>
                        <td class="px-3 py-2 text-sm tabular-nums">{{ number_format($angle['results']) }} <span class="text-[11px] text-gray-400">{{ $angle['result_label'] }}</span></td>
                        <td class="px-3 py-2">
                            <div class="flex items-center gap-2">
                                <div class="h-1.5 w-16 overflow-hidden rounded-full bg-slate-100 dark:bg-white/5">
                                    <div class="h-full rounded-full bg-emerald-500" style="width: {{ min(100, round(($angle['spend'] / $maxAngle) * 100)) }}%"></div>
                                </div>
                                <span class="text-xs tabular-nums text-gray-500">{{ $angle['share'] }}%</span>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-ta.table>
        </section>

        <div class="space-y-3 lg:col-span-5">
            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Coverage</h3>
                <ul class="mt-3 space-y-1.5 text-sm">
                    @foreach ($creatives['coverage'] ?? [] as $row)
                        <li class="flex items-center justify-between gap-2">
                            <span class="text-gray-700 dark:text-gray-300">{{ $row['label'] }}</span>
                            <x-ta.badge :color="match($row['state']) { 'Present', 'Strong' => 'success', 'Partial', 'Thin' => 'warning', default => 'light' }" size="sm">{{ $row['state'] }}</x-ta.badge>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Persona coverage</h3>
                <ul class="mt-3 space-y-2">
                    @foreach ($creatives['persona_coverage'] ?? [] as $row)
                        <li>
                            <div class="mb-1 flex justify-between text-xs text-gray-500">
                                <span>{{ $row['persona'] }}</span>
                                <span>{{ $row['state'] }}</span>
                            </div>
                            <x-ta.progress-bar :value="$row['share']" :max="100" :tone="($row['state'] ?? '') === 'Thin' ? 'warning' : 'primary'" />
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Active tests</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($creatives['active_tests'] ?? [] as $test)
                        <li class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-white/[0.03]">
                            <p class="font-medium text-gray-900 dark:text-white">{{ $test['name'] }}</p>
                            <p class="text-[11px] text-gray-400">{{ $test['status'] }} · {{ $test['note'] }}</p>
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>
    </div>
</div>
