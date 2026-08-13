@php
    $acq = $data['acquisition'] ?? [];
    $glance = $acq['glance'] ?? [];
    $channels = $channelRows ?? $acq['channels'] ?? [];
    $sourceMedium = $acq['source_medium'] ?? [];
    $campaigns = $acq['campaigns'] ?? [];
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Acquisition</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $acq['subtitle'] ?? 'Channels, source/medium, and related paid assets' }}</p>
    </div>

    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <x-ta.metric-card label="Sessions" :value="$glance['sessions']['value'] ?? ($glance['sessions'] ?? '—')" :delta="$glance['sessions']['secondary'] ?? null" :tone="$glance['sessions']['tone'] ?? 'neutral'" />
        <x-ta.metric-card label="Users" :value="$glance['users']['value'] ?? ($glance['users'] ?? '—')" :delta="$glance['users']['secondary'] ?? null" :tone="$glance['users']['tone'] ?? 'neutral'" />
        <x-ta.metric-card label="Engaged rate" :value="$glance['engaged_rate']['value'] ?? ($glance['engaged_rate'] ?? '—')" :delta="$glance['engaged_rate']['secondary'] ?? null" :tone="$glance['engaged_rate']['tone'] ?? 'neutral'" />
        <x-ta.metric-card label="Key events" :value="$glance['key_events']['value'] ?? ($glance['key_events'] ?? '—')" :delta="$glance['key_events']['secondary'] ?? null" :tone="$glance['key_events']['tone'] ?? 'neutral'" />
    </div>

    <section>
        <h3 class="mb-2 text-sm font-semibold text-gray-900 dark:text-white">Channels</h3>
        <x-ta.table>
            <x-slot:head>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Channel</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Sessions</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Engaged %</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Key events</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Related asset</th>
            </x-slot:head>
            @foreach ($channels as $row)
                <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                    <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $row['channel'] ?? $row['label'] }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">
                        @if (array_key_exists('sessions', $row) && $row['sessions'] !== null)
                            {{ number_format($row['sessions']) }}
                        @else
                            <span class="text-slate-400">No data</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ isset($row['engaged_rate']) ? $row['engaged_rate'].'%' : ($row['engaged'] ?? '—') }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">
                        @if (array_key_exists('key_events', $row) && $row['key_events'] !== null)
                            {{ number_format($row['key_events']) }}
                        @else
                            <span class="text-slate-400">No data</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5">
                        @if (! empty($row['related']))
                            <div class="flex items-center gap-2">
                                <x-demo.digital-asset-mark :type="$row['related']['type'] ?? 'website'" size="sm" />
                                @if (! empty($row['related']['route']))
                                    <a href="{{ route($row['related']['route'], $row['related']['params'] ?? []) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $row['related']['label'] }}</a>
                                @else
                                    <span class="text-xs text-gray-500">{{ $row['related']['label'] }}</span>
                                @endif
                            </div>
                        @else
                            <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ta.table>
    </section>

    <section>
        <h3 class="mb-2 text-sm font-semibold text-gray-900 dark:text-white">Source / medium</h3>
        <x-ta.table>
            <x-slot:head>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Source / medium</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Sessions</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Engaged %</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Related Digital Asset</th>
            </x-slot:head>
            @foreach ($sourceMedium as $row)
                <tr>
                    <td class="px-4 py-2.5">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $row['source'] ?? '' }}{{ isset($row['medium']) ? ' / '.$row['medium'] : '' }}</p>
                        @if (! empty($row['label']))
                            <p class="text-[11px] text-gray-400">{{ $row['label'] }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">
                        @if (array_key_exists('sessions', $row) && $row['sessions'] !== null)
                            {{ number_format($row['sessions']) }}
                        @else
                            <span class="text-slate-400">No data</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ isset($row['engaged_rate']) ? $row['engaged_rate'].'%' : '—' }}</td>
                    <td class="px-4 py-2.5">
                        @if (! empty($row['related']))
                            <div class="flex items-center gap-2">
                                <x-demo.digital-asset-mark :type="$row['related']['type'] ?? 'ga4'" size="sm" />
                                @if (! empty($row['related']['route']))
                                    <a href="{{ route($row['related']['route'], $row['related']['params'] ?? []) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $row['related']['label'] }}</a>
                                @else
                                    <span class="text-xs text-gray-500">{{ $row['related']['label'] }}</span>
                                @endif
                            </div>
                        @else
                            <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ta.table>
    </section>

    <section>
        <h3 class="mb-2 text-sm font-semibold text-gray-900 dark:text-white">Campaigns (measured)</h3>
        <x-ta.table>
            <x-slot:head>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Campaign</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Channel</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Sessions</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Key events</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Paid workspace</th>
            </x-slot:head>
            @foreach ($campaigns as $row)
                <tr>
                    <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $row['name'] ?? $row['campaign'] }}</td>
                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ $row['channel'] ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">
                        @if (array_key_exists('sessions', $row) && $row['sessions'] !== null)
                            {{ number_format($row['sessions']) }}
                        @else
                            <span class="text-slate-400">No data</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">
                        @if (array_key_exists('key_events', $row) && $row['key_events'] !== null)
                            {{ number_format($row['key_events']) }}
                        @else
                            <span class="text-slate-400">No data</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5">
                        @if (! empty($row['related']))
                            <div class="flex items-center gap-2">
                                <x-demo.digital-asset-mark :type="$row['related']['type'] ?? 'google_ads'" size="sm" />
                                @if (! empty($row['related']['route']))
                                    <a href="{{ route($row['related']['route'], $row['related']['params'] ?? []) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                                        {{ $row['related']['label'] ?? (str_contains($row['related']['type'] ?? '', 'meta') ? 'Open Meta' : 'Open Google Ads') }}
                                    </a>
                                @elseif (($row['related']['type'] ?? '') === 'meta_ads' || ($row['related']['type'] ?? '') === 'meta')
                                    <a href="{{ route('demo.meta.overview') }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open Meta</a>
                                @elseif (($row['related']['type'] ?? '') === 'google_ads' || ($row['related']['type'] ?? '') === 'gads')
                                    <a href="{{ route('demo.google-ads.overview') }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open Google Ads</a>
                                @endif
                            </div>
                        @elseif (! empty($row['open_google_ads']))
                            <a href="{{ route('demo.google-ads.overview') }}" wire:navigate class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                                <x-demo.digital-asset-mark type="google_ads" size="sm" />
                                Open Google Ads
                            </a>
                        @elseif (! empty($row['open_meta']))
                            <a href="{{ route('demo.meta.overview') }}" wire:navigate class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                                <x-demo.digital-asset-mark type="meta_ads" size="sm" />
                                Open Meta
                            </a>
                        @else
                            <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ta.table>
    </section>
</div>
