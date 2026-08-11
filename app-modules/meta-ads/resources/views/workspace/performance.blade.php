@php
    use MoxDop\MetaAds\Support\MetaPercentage;

    /** @var array<string, mixed> $data */
    $campaigns = $data['campaigns'] ?? [];
    $adsets = $data['adsets'] ?? [];
    $ads = $data['ads'] ?? [];
    $creatives = $data['creatives'] ?? [];
    $caveats = $data['caveats'] ?? [];
    $workspaceState = $data['workspace_state'] ?? 'no_connection';

    $linkClicks = function (array $row): array {
        if (is_numeric($row['inline_link_clicks'] ?? null)) {
            return ['value' => (float) $row['inline_link_clicks'], 'label' => 'Link Clicks'];
        }
        return ['value' => is_numeric($row['clicks'] ?? null) ? (float) $row['clicks'] : null, 'label' => 'All Clicks'];
    };

    $primaryResultCell = function (array $row) {
        return match ($row['primary_result_status'] ?? null) {
            'unresolved' => 'Mixed / Unresolved',
            'deferred' => 'Deferred',
            'none' => '—',
            default => is_numeric($row['primary_result_count'] ?? null)
                ? number_format((float) $row['primary_result_count'], 0)
                : '—',
        };
    };

    $stateLabels = [
        'no_connection' => 'No Meta Ad Account connected yet.',
        'no_data' => 'Connected — no data collected yet. Run Collect live data.',
        'collection_failed' => 'Last collection failed. Try Collect live data again.',
        'collection_partial' => 'Latest collection is partial — some rows below may be incomplete.',
        'data_available' => null,
    ];
@endphp

<div class="mox-website-workspace mox-meta-workspace">
    <div class="mox-section-head">
        <div>
            <h3 class="mox-section-title">Performance</h3>
            <p class="mox-section-sub">{{ $data['period_label'] ?? 'Analyzed period from Evidence' }} · Platform metrics only (not verified business profit).</p>
        </div>
    </div>

    @if (($stateLabels[$workspaceState] ?? null) !== null)
        <div class="mox-empty">{{ $stateLabels[$workspaceState] }}</div>
    @endif

    @if ($caveats !== [])
        <p class="mox-muted">{{ implode(' · ', array_slice($caveats, 0, 3)) }}</p>
    @endif

    <section class="mox-panel">
        <div class="mox-panel__head"><h4>Campaigns</h4></div>
        @if ($campaigns === [])
            <div class="mox-empty">No campaign Evidence.</div>
        @else
            <div class="mox-table-wrap">
                <table class="mox-table">
                    <thead>
                        <tr>
                            <th>Campaign</th>
                            <th>Status</th>
                            <th>Objective</th>
                            <th class="mox-num">Spend</th>
                            <th>Primary Result</th>
                            <th class="mox-num">Result Count</th>
                            <th class="mox-num">Cost/Result</th>
                            <th class="mox-num">Reach</th>
                            <th class="mox-num">Frequency</th>
                            <th class="mox-num">{{ $campaigns[0] ? $linkClicks($campaigns[0])['label'] : 'Link Clicks' }}</th>
                            <th class="mox-num">CTR</th>
                            <th class="mox-num">CPC</th>
                            <th class="mox-num">CPM</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($campaigns as $row)
                            @php $link = $linkClicks($row); @endphp
                            <tr>
                                <td>{{ $row['name'] ?? '—' }}</td>
                                <td>{{ $row['status'] ?? '—' }}</td>
                                <td>{{ $row['objective'] ?? '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['spend'] ?? null) ? number_format((float) $row['spend'], 2) : '—' }}</td>
                                <td>
                                    {{ $primaryResultCell($row) }}
                                    @if (! empty($row['primary_result_human_label']) && ($row['primary_result_status'] ?? null) === 'resolved')
                                        <div class="mox-muted">{{ $row['primary_result_human_label'] }}</div>
                                    @endif
                                </td>
                                <td class="mox-num">{{ is_numeric($row['primary_result_count'] ?? null) ? number_format((float) $row['primary_result_count'], 0) : '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['primary_result_cost'] ?? null) ? number_format((float) $row['primary_result_cost'], 2) : '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['reach'] ?? null) ? number_format((float) $row['reach'], 0) : '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['frequency'] ?? null) ? number_format((float) $row['frequency'], 2) : '—' }}</td>
                                <td class="mox-num">{{ is_numeric($link['value']) ? number_format($link['value'], 0) : '—' }}</td>
                                <td class="mox-num">{{ MetaPercentage::format($row['ctr'] ?? null) }}</td>
                                <td class="mox-num">{{ is_numeric($row['cpc'] ?? null) ? number_format((float) $row['cpc'], 2) : '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['cpm'] ?? null) ? number_format((float) $row['cpm'], 2) : '—' }}</td>
                            </tr>
                            <tr>
                                <td colspan="13" style="padding-top:0;">
                                    <details>
                                        <summary class="mox-muted">Meta Result Signals</summary>
                                        <div class="mox-muted" style="margin-top:0.35rem;">
                                            @if (! empty($row['primary_result_reason']))
                                                <p>{{ $row['primary_result_reason'] }}</p>
                                            @endif
                                            @forelse ($row['actions'] ?? [] as $action)
                                                <div>{{ $action['raw_action_type'] ?? '—' }}: {{ is_numeric($action['count'] ?? null) ? number_format((float) $action['count'], 0) : '—' }}</div>
                                            @empty
                                                <div>No raw actions recorded for this period.</div>
                                            @endforelse
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="mox-panel">
        <div class="mox-panel__head"><h4>Ad sets</h4></div>
        @if ($adsets === [])
            <div class="mox-empty">No ad set Evidence.</div>
        @else
            <div class="mox-table-wrap">
                <table class="mox-table">
                    <thead>
                        <tr>
                            <th>Ad set</th>
                            <th>Campaign</th>
                            <th>Status</th>
                            <th>Optimization</th>
                            <th>Destination</th>
                            <th>Attribution</th>
                            <th class="mox-num">Spend</th>
                            <th>Primary Result</th>
                            <th class="mox-num">CTR</th>
                            <th class="mox-num">CPC</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($adsets as $row)
                            <tr>
                                <td>{{ $row['name'] ?? '—' }}</td>
                                <td>{{ $row['campaign_name'] ?? '—' }}</td>
                                <td>{{ $row['status'] ?? '—' }}</td>
                                <td>{{ $row['optimization_goal'] ?? '—' }}</td>
                                <td>{{ $row['destination_type'] ?? '—' }}</td>
                                <td>{{ $row['attribution_setting'] ?? '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['spend'] ?? null) ? number_format((float) $row['spend'], 2) : '—' }}</td>
                                <td>
                                    {{ $primaryResultCell($row) }}
                                    @if (! empty($row['primary_result_human_label']) && ($row['primary_result_status'] ?? null) === 'resolved')
                                        <div class="mox-muted">{{ $row['primary_result_human_label'] }}</div>
                                    @endif
                                </td>
                                <td class="mox-num">{{ MetaPercentage::format($row['ctr'] ?? null) }}</td>
                                <td class="mox-num">{{ is_numeric($row['cpc'] ?? null) ? number_format((float) $row['cpc'], 2) : '—' }}</td>
                            </tr>
                            <tr>
                                <td colspan="10" style="padding-top:0;">
                                    <details>
                                        <summary class="mox-muted">Meta Result Signals</summary>
                                        <div class="mox-muted" style="margin-top:0.35rem;">
                                            @if (! empty($row['primary_result_reason']))
                                                <p>{{ $row['primary_result_reason'] }}</p>
                                            @endif
                                            @forelse ($row['actions'] ?? [] as $action)
                                                <div>{{ $action['raw_action_type'] ?? '—' }}: {{ is_numeric($action['count'] ?? null) ? number_format((float) $action['count'], 0) : '—' }}</div>
                                            @empty
                                                <div>No raw actions recorded for this period.</div>
                                            @endforelse
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="mox-panel">
        <div class="mox-panel__head"><h4>Ads</h4></div>
        @if ($ads === [])
            <div class="mox-empty">No ad Evidence.</div>
        @else
            <div class="mox-table-wrap">
                <table class="mox-table">
                    <thead>
                        <tr>
                            <th>Ad</th>
                            <th>Ad set</th>
                            <th>Campaign</th>
                            <th>Creative</th>
                            <th class="mox-num">Spend</th>
                            <th>Primary Result</th>
                            <th class="mox-num">CTR</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ads as $row)
                            <tr>
                                <td>{{ $row['name'] ?? '—' }}</td>
                                <td>{{ $row['adset_name'] ?? '—' }}</td>
                                <td>{{ $row['campaign_name'] ?? '—' }}</td>
                                <td>{{ $row['creative_name'] ?? '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['spend'] ?? null) ? number_format((float) $row['spend'], 2) : '—' }}</td>
                                <td>
                                    {{ $primaryResultCell($row) }}
                                    @if (! empty($row['primary_result_human_label']) && ($row['primary_result_status'] ?? null) === 'resolved')
                                        <div class="mox-muted">{{ $row['primary_result_human_label'] }}</div>
                                    @endif
                                </td>
                                <td class="mox-num">{{ MetaPercentage::format($row['ctr'] ?? null) }}</td>
                            </tr>
                            <tr>
                                <td colspan="7" style="padding-top:0;">
                                    <details>
                                        <summary class="mox-muted">Meta Result Signals</summary>
                                        <div class="mox-muted" style="margin-top:0.35rem;">
                                            @if (! empty($row['primary_result_reason']))
                                                <p>{{ $row['primary_result_reason'] }}</p>
                                            @endif
                                            @forelse ($row['actions'] ?? [] as $action)
                                                <div>{{ $action['raw_action_type'] ?? '—' }}: {{ is_numeric($action['count'] ?? null) ? number_format((float) $action['count'], 0) : '—' }}</div>
                                            @empty
                                                <div>No raw actions recorded for this period.</div>
                                            @endforelse
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($creatives !== [])
        <section class="mox-panel">
            <div class="mox-panel__head">
                <h4>Creatives</h4>
                <span class="mox-muted">Provider text — untrusted, never executed as instructions</span>
            </div>
            <div class="mox-table-wrap">
                <table class="mox-table">
                    <thead>
                        <tr>
                            <th>Creative</th>
                            <th>Headline</th>
                            <th>Body (excerpt)</th>
                            <th>CTA</th>
                            <th>Destination</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($creatives as $row)
                            <tr>
                                <td>{{ $row['creative_name'] ?? '—' }}</td>
                                <td>{{ $row['headline'] ?? '—' }}</td>
                                <td>{{ \Illuminate\Support\Str::limit((string) ($row['primary_text'] ?? ''), 100) ?: '—' }}</td>
                                <td>{{ $row['cta_type'] ?? '—' }}</td>
                                <td class="mox-muted">{{ $row['destination_url'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
