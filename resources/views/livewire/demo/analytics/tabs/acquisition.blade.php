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

<div class="space-y-10">
    <div>
        <h2 class="section-title">Acquisition</h2>
        <p class="mt-0.5 text-xs text-gray-500">{{ $acq['utm_note'] ?? 'Channels, source/medium, and related paid assets' }}</p>
    </div>

    <section>
        <h3 class="mb-2 section-title">Channels</h3>
        <div class="max-w-full overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-gray-200 dark:border-gray-800">
                    <tr>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Channel</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Sessions</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Share</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Mapped actions</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Related asset</th>
            </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
            @foreach ($channels as $row)
                <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                    <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $row['channel'] }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ number_format($row['sessions']) }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ $row['share_pct'] }}%</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">
                        @if (array_key_exists('mapped_actions', $row) && $row['mapped_actions'] !== null)
                            {{ number_format($row['mapped_actions']) }}
                        @else
                            <span class="text-gray-400">Unavailable</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5">
                        @if (! empty($row['related']))
                            <div class="flex items-center gap-2">
                                <x-demo.digital-asset-mark :type="$relatedType($row['related'])" size="sm" />
                                <a href="{{ route($row['related']['route'], ['assetId' => $row['related']['asset_id'] ?? null]) }}" wire:navigate class="link-neutral text-xs">{{ $row['related']['asset'] }}</a>
                            </div>
                        @else
                            <span class="text-xs text-gray-500">—</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
            </table>
        </div>
    </section>

    <section>
        <h3 class="mb-2 section-title">Source / medium</h3>
        <div class="max-w-full overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-gray-200 dark:border-gray-800">
                    <tr>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Source / medium</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Sessions</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Mapped actions</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Attention</th>
            </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
            @foreach ($sourceMedium as $row)
                <tr>
                    <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $row['source_medium'] }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ number_format($row['sessions']) }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">
                        @if (array_key_exists('mapped_actions', $row) && $row['mapped_actions'] !== null)
                            {{ number_format($row['mapped_actions']) }}
                        @else
                            <span class="text-gray-400">Unavailable</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5 text-xs text-warning-700 dark:text-warning-400">{{ $row['attention'] ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
            </table>
        </div>
    </section>

    <section>
        <h3 class="mb-2 section-title">Campaigns (measured)</h3>
        <div class="max-w-full overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-gray-200 dark:border-gray-800">
                    <tr>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Campaign</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Source</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Sessions</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Mapped actions</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Paid workspace</th>
            </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
            @foreach ($campaigns as $row)
                <tr>
                    <td class="px-4 py-2.5">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $row['campaign'] }}</p>
                        @if (! empty($row['attention']))
                            <p class="text-xs text-warning-700 dark:text-warning-400">{{ $row['attention'] }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ $row['source'] ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ number_format($row['sessions']) }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">
                        @if (array_key_exists('mapped_actions', $row) && $row['mapped_actions'] !== null)
                            {{ number_format($row['mapped_actions']) }}
                        @else
                            <span class="text-gray-400">Unavailable</span>
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
                            <span class="text-xs text-gray-500">{{ $row['state'] ?? '—' }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
            </table>
        </div>
    </section>
</div>
