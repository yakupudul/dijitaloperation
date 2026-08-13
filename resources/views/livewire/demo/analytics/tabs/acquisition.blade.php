@php
    $acq = $data['acquisition'] ?? [];
    $channels = $channelRows ?? $acq['channels'] ?? [];
    $sourceMedium = $acq['source_medium'] ?? [];
    $campaigns = $acq['campaigns'] ?? [];

    $relatedType = static function (?array $related): string {
        $asset = strtolower((string) ($related['asset'] ?? ''));

        return match (true) {
            str_contains($asset, 'meta') => 'meta_ads',
            str_contains($asset, 'google ads') => 'google_ads',
            str_contains($asset, 'search console') || str_contains($asset, 'gsc') => 'gsc',
            str_contains($asset, 'website') => 'website',
            default => 'ga4',
        };
    };
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Acquisition</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $acq['utm_note'] ?? 'Channels, source/medium, and related paid assets' }}</p>
    </div>

    <section>
        <h3 class="mb-2 text-sm font-semibold text-gray-900 dark:text-white">Channels</h3>
        <x-ta.table>
            <x-slot:head>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Channel</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Sessions</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Share</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Mapped actions</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Related asset</th>
            </x-slot:head>
            @foreach ($channels as $row)
                <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                    <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $row['channel'] }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ number_format($row['sessions']) }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ $row['share_pct'] }}%</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">
                        @if (array_key_exists('mapped_actions', $row) && $row['mapped_actions'] !== null)
                            {{ number_format($row['mapped_actions']) }}
                        @else
                            <span class="text-slate-400">Unavailable</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5">
                        @if (! empty($row['related']))
                            <div class="flex items-center gap-2">
                                <x-demo.digital-asset-mark :type="$relatedType($row['related'])" size="sm" />
                                <a href="{{ route($row['related']['route'], ['assetId' => $row['related']['asset_id'] ?? null]) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $row['related']['asset'] }}</a>
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
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Mapped actions</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Attention</th>
            </x-slot:head>
            @foreach ($sourceMedium as $row)
                <tr>
                    <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $row['source_medium'] }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ number_format($row['sessions']) }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">
                        @if (array_key_exists('mapped_actions', $row) && $row['mapped_actions'] !== null)
                            {{ number_format($row['mapped_actions']) }}
                        @else
                            <span class="text-slate-400">Unavailable</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5 text-xs text-amber-700 dark:text-amber-400">{{ $row['attention'] ?? '—' }}</td>
                </tr>
            @endforeach
        </x-ta.table>
    </section>

    <section>
        <h3 class="mb-2 text-sm font-semibold text-gray-900 dark:text-white">Campaigns (measured)</h3>
        <x-ta.table>
            <x-slot:head>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Campaign</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Source</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Sessions</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Mapped actions</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Paid workspace</th>
            </x-slot:head>
            @foreach ($campaigns as $row)
                <tr>
                    <td class="px-4 py-2.5">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $row['campaign'] }}</p>
                        @if (! empty($row['attention']))
                            <p class="text-[11px] text-amber-700 dark:text-amber-400">{{ $row['attention'] }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ $row['source'] ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ number_format($row['sessions']) }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">
                        @if (array_key_exists('mapped_actions', $row) && $row['mapped_actions'] !== null)
                            {{ number_format($row['mapped_actions']) }}
                        @else
                            <span class="text-slate-400">Unavailable</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5">
                        @if (! empty($row['route']) && ! empty($row['related_asset']))
                            @php
                                $type = str_contains(strtolower($row['related_asset']), 'meta') ? 'meta_ads' : 'google_ads';
                                $cta = $type === 'meta_ads' ? 'Open Meta' : 'Open Google Ads';
                            @endphp
                            <a href="{{ route($row['route'], ['assetId' => $row['related_asset_id'] ?? null]) }}" wire:navigate class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                                <x-demo.digital-asset-mark :type="$type" size="sm" />
                                {{ $cta }}
                            </a>
                        @else
                            <span class="text-xs text-gray-400">{{ $row['state'] ?? '—' }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ta.table>
    </section>
</div>
