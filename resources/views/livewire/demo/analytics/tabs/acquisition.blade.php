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
    {{-- 1. Which channels bring the visits and the actions. --}}
    <section>
        <div class="section-head">
            <h2 class="section-title" title="{{ $acq['utm_note'] ?? '' }}">Kanallar <span class="section-count">{{ count($channels) }}</span></h2>
        </div>
        <div class="card overflow-x-auto">
            @if ($channels === [])
                <x-ta.empty-state title="Kanal verisi yok" message="Analytics oturumları kanallara göre gelince burada görünür." />
            @else
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Kanal</th>
                            <th class="text-right!">Oturum</th>
                            <th class="hidden text-right! sm:table-cell">Pay</th>
                            <th class="text-right!">Aksiyon</th>
                            <th class="hidden md:table-cell">İlgili varlık</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($channels as $row)
                            <tr>
                                <td class="font-medium text-gray-900 dark:text-white">{{ $say($row['channel']) }}</td>
                                <td class="text-right tabular-nums">{{ $num($row['sessions']) }}</td>
                                <td class="hidden text-right tabular-nums text-gray-600 sm:table-cell dark:text-gray-300">%{{ $row['share_pct'] }}</td>
                                <td @class(['text-right tabular-nums', 'text-gray-400' => $count($row, 'mapped_actions') === '—'])>{{ $count($row, 'mapped_actions') }}</td>
                                <td class="hidden md:table-cell">
                                    @if (! empty($row['related']))
                                        <a href="{{ route($row['related']['route'], ['assetId' => $row['related']['asset_id'] ?? null]) }}" wire:navigate class="inline-flex items-center gap-2 text-[13px] link-neutral">
                                            <x-demo.digital-asset-mark :type="$relatedType($row['related'])" size="xs" />
                                            {{ $row['related']['asset'] }}
                                        </a>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </section>

    {{-- 2. The same visits by source / medium. --}}
    <section>
        <div class="section-head">
            <h2 class="section-title">Kaynak / ortam <span class="section-count">{{ count($sourceMedium) }}</span></h2>
        </div>
        <div class="card overflow-x-auto">
            @if ($sourceMedium === [])
                <x-ta.empty-state size="sm" title="Kaynak / ortam verisi yok" />
            @else
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Kaynak / ortam</th>
                            <th class="text-right!">Oturum</th>
                            <th class="text-right!">Aksiyon</th>
                            <th class="hidden md:table-cell">Uyarı</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sourceMedium as $row)
                            <tr>
                                <td class="font-medium text-gray-900 dark:text-white">{{ $row['source_medium'] }}</td>
                                <td class="text-right tabular-nums">{{ $num($row['sessions']) }}</td>
                                <td @class(['text-right tabular-nums', 'text-gray-400' => $count($row, 'mapped_actions') === '—'])>{{ $count($row, 'mapped_actions') }}</td>
                                <td class="hidden text-[13px] md:table-cell">
                                    @if (! empty($row['attention']))
                                        <span class="text-warning-700 dark:text-warning-400">{{ $row['attention'] }}</span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </section>

    {{-- 3. Paid campaigns as Analytics measured them, with a way to the ad account. --}}
    <section>
        <div class="section-head">
            <h2 class="section-title">Kampanyalar <span class="section-count">{{ count($campaigns) }}</span></h2>
            <span class="text-[13px] text-gray-500">Analytics'in ölçtüğü</span>
        </div>
        <div class="card overflow-x-auto">
            @if ($campaigns === [])
                <x-ta.empty-state size="sm" title="Kampanya verisi yok" />
            @else
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Kampanya</th>
                            <th class="hidden sm:table-cell">Kaynak</th>
                            <th class="text-right!">Oturum</th>
                            <th class="hidden text-right! sm:table-cell">Aksiyon</th>
                            <th><span class="sr-only">Reklam hesabı</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($campaigns as $row)
                            <tr>
                                <td class="min-w-[12rem]">
                                    <p class="font-medium text-gray-900 dark:text-white">{{ $row['campaign'] }}</p>
                                    @if (! empty($row['attention']))
                                        <p class="text-[13px] text-warning-700 dark:text-warning-400">{{ $row['attention'] }}</p>
                                    @endif
                                </td>
                                <td class="hidden text-[13px] text-gray-500 sm:table-cell">{{ $row['source'] ?? '—' }}</td>
                                <td class="text-right tabular-nums">{{ $num($row['sessions']) }}</td>
                                <td @class(['hidden text-right tabular-nums sm:table-cell', 'text-gray-400' => $count($row, 'mapped_actions') === '—'])>{{ $count($row, 'mapped_actions') }}</td>
                                <td class="text-right whitespace-nowrap">
                                    @if (! empty($row['route']) && ! empty($row['related_asset']))
                                        @php $type = str_contains(strtolower($row['related_asset']), 'meta') ? 'meta_ads' : 'google_ads'; @endphp
                                        <a href="{{ route($row['route'], ['assetId' => $row['related_asset_id'] ?? null]) }}" wire:navigate class="btn h-7! gap-1.5! px-2.5! text-xs!">
                                            <x-demo.digital-asset-mark :type="$type" size="xs" class="h-4! w-4!" />
                                            {{ $type === 'meta_ads' ? 'Meta' : 'Google Ads' }}
                                        </a>
                                    @else
                                        <span class="text-[13px] text-gray-500">{{ $say($row['state'] ?? null) }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </section>
</div>
