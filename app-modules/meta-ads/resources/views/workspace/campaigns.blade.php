@php
    use MoxDop\MetaAds\Support\MetaPercentage;

    /** @var array<string, mixed> $data */
    $campaigns = $data['campaigns'] ?? [];
    $adsets = $data['adsets'] ?? [];
    $ads = $data['ads'] ?? [];
    $needsAnalyze = (bool) ($data['needs_analyze'] ?? false);
    $periodMatched = (bool) ($data['period_matched'] ?? false);
    $async = $data['async_collection'] ?? null;

    $num = fn ($v, int $decimals = 0) => is_numeric($v) ? number_format((float) $v, $decimals) : '—';
    $pct = fn ($v) => is_numeric($v) ? MetaPercentage::format($v) : '—';
@endphp

<div class="mox-website-workspace mox-meta-workspace mox-meta-expert" x-data="{ openCampaign: null, openAdset: null }">
    <div class="mox-section-head">
        <div>
            <h3 class="mox-section-title">Campaigns</h3>
            <p class="mox-section-sub">{{ $data['period_label'] ?? '' }} · Default: delivered in selected period</p>
        </div>
    </div>

    @include('meta-ads::workspace.partials.filter-bar', ['data' => $data])

    @if ($needsAnalyze || ! $periodMatched)
        <div class="mox-meta-analyze">
            <div>
                <h4>Campaign metrics for this period are not loaded</h4>
                <p class="mox-muted">Avoiding stale numbers from another date range.</p>
            </div>
            @if (! $async)
                <button type="button" class="mox-btn mox-btn--primary" wire:click="analyzeMetaSelectedPeriod">Analyze this period</button>
            @endif
        </div>
    @elseif ($campaigns === [])
        <div class="mox-empty">No delivered campaigns match the current filters.</div>
    @else
        <div class="mox-table-wrap">
            <table class="mox-table mox-table--campaigns">
                <thead>
                    <tr>
                        <th>Campaign</th>
                        <th>Status</th>
                        <th>Objective</th>
                        <th class="mox-num">Spend</th>
                        <th>Primary result</th>
                        <th class="mox-num">Results</th>
                        <th class="mox-num">Cost / result</th>
                        <th class="mox-num">Reach</th>
                        <th class="mox-num">Frequency</th>
                        <th class="mox-num">Link clicks</th>
                        <th class="mox-num">Link CTR</th>
                        <th class="mox-num">All clicks</th>
                        <th class="mox-num">All clicks CTR</th>
                        <th class="mox-num">CPC (All)</th>
                        <th class="mox-num">CPM</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($campaigns as $row)
                        @php $cid = (string) ($row['campaign_id'] ?? $row['entity_id'] ?? ''); @endphp
                        <tr>
                            <td>{{ $row['name'] ?? '—' }}</td>
                            <td>{{ $row['effective_status'] ?? $row['status'] ?? '—' }}</td>
                            <td>{{ $row['objective'] ?? '—' }}</td>
                            <td class="mox-num">{{ $num($row['spend'] ?? null, 2) }}</td>
                            <td>{{ $row['primary_result_human_label'] ?? ($row['primary_result_status'] ?? '—') }}</td>
                            <td class="mox-num">{{ $num($row['primary_result_count'] ?? null) }}</td>
                            <td class="mox-num">{{ $num($row['primary_result_cost'] ?? null, 2) }}</td>
                            <td class="mox-num">{{ $num($row['reach'] ?? null) }}</td>
                            <td class="mox-num">{{ $num($row['frequency'] ?? null, 2) }}</td>
                            <td class="mox-num">{{ $num($row['inline_link_clicks'] ?? null) }}</td>
                            <td class="mox-num">{{ $pct($row['inline_link_click_ctr'] ?? null) }}</td>
                            <td class="mox-num">{{ $num($row['clicks'] ?? null) }}</td>
                            <td class="mox-num">{{ $pct($row['ctr'] ?? null) }}</td>
                            <td class="mox-num">{{ $num($row['cpc'] ?? null, 2) }}</td>
                            <td class="mox-num">{{ $num($row['cpm'] ?? null, 2) }}</td>
                            <td>
                                <button type="button" class="mox-btn mox-btn--ghost" x-on:click="openCampaign = openCampaign === '{{ $cid }}' ? null : '{{ $cid }}'; openAdset = null">
                                    Inspect
                                </button>
                            </td>
                        </tr>
                        <tr x-show="openCampaign === '{{ $cid }}'" x-cloak class="mox-drilldown-row">
                            <td colspan="16">
                                <div class="mox-drilldown">
                                    <h5>Ad sets · {{ $row['name'] ?? '' }}</h5>
                                    @php
                                        $childAdsets = collect($adsets)->filter(fn ($a) => (string) ($a['campaign_id'] ?? '') === $cid)->values();
                                    @endphp
                                    @if ($childAdsets->isEmpty())
                                        <div class="mox-empty">No ad set rows for this campaign in the selected period.</div>
                                    @else
                                        <table class="mox-table">
                                            <thead>
                                                <tr>
                                                    <th>Ad set</th>
                                                    <th>Status</th>
                                                    <th class="mox-num">Spend</th>
                                                    <th>Primary result</th>
                                                    <th class="mox-num">Results</th>
                                                    <th class="mox-num">Cost / result</th>
                                                    <th class="mox-num">Link CTR</th>
                                                    <th></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($childAdsets as $adset)
                                                    @php $asid = (string) ($adset['adset_id'] ?? $adset['entity_id'] ?? ''); @endphp
                                                    <tr>
                                                        <td>{{ $adset['name'] ?? '—' }}</td>
                                                        <td>{{ $adset['effective_status'] ?? $adset['status'] ?? '—' }}</td>
                                                        <td class="mox-num">{{ $num($adset['spend'] ?? null, 2) }}</td>
                                                        <td>{{ $adset['primary_result_human_label'] ?? ($adset['primary_result_status'] ?? '—') }}</td>
                                                        <td class="mox-num">{{ $num($adset['primary_result_count'] ?? null) }}</td>
                                                        <td class="mox-num">{{ $num($adset['primary_result_cost'] ?? null, 2) }}</td>
                                                        <td class="mox-num">{{ $pct($adset['inline_link_click_ctr'] ?? null) }}</td>
                                                        <td>
                                                            <button type="button" class="mox-btn mox-btn--ghost" x-on:click="openAdset = openAdset === '{{ $asid }}' ? null : '{{ $asid }}'">
                                                                Ads
                                                            </button>
                                                        </td>
                                                    </tr>
                                                    <tr x-show="openAdset === '{{ $asid }}'" x-cloak>
                                                        <td colspan="8">
                                                            @php
                                                                $childAds = collect($ads)->filter(fn ($a) => (string) ($a['adset_id'] ?? '') === $asid)->values();
                                                            @endphp
                                                            @if ($childAds->isEmpty())
                                                                <div class="mox-empty">No ads for this ad set in the selected period.</div>
                                                            @else
                                                                <table class="mox-table">
                                                                    <thead>
                                                                        <tr>
                                                                            <th>Ad</th>
                                                                            <th>Status</th>
                                                                            <th class="mox-num">Spend</th>
                                                                            <th>Primary result</th>
                                                                            <th class="mox-num">Cost / result</th>
                                                                            <th class="mox-num">Link CTR</th>
                                                                            <th class="mox-num">All clicks CTR</th>
                                                                            <th class="mox-num">Frequency</th>
                                                                            <th>Creative</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        @foreach ($childAds as $ad)
                                                                            <tr>
                                                                                <td>{{ $ad['name'] ?? '—' }}</td>
                                                                                <td>{{ $ad['effective_status'] ?? $ad['status'] ?? '—' }}</td>
                                                                                <td class="mox-num">{{ $num($ad['spend'] ?? null, 2) }}</td>
                                                                                <td>{{ $ad['primary_result_human_label'] ?? ($ad['primary_result_status'] ?? '—') }}</td>
                                                                                <td class="mox-num">{{ $num($ad['primary_result_cost'] ?? null, 2) }}</td>
                                                                                <td class="mox-num">{{ $pct($ad['inline_link_click_ctr'] ?? null) }}</td>
                                                                                <td class="mox-num">{{ $pct($ad['ctr'] ?? null) }}</td>
                                                                                <td class="mox-num">{{ $num($ad['frequency'] ?? null, 2) }}</td>
                                                                                <td>{{ $ad['creative_name'] ?? $ad['creative_id'] ?? '—' }}</td>
                                                                            </tr>
                                                                        @endforeach
                                                                    </tbody>
                                                                </table>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
