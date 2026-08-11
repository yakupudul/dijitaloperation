@php
    use MoxDop\MetaAds\Support\MetaPercentage;

    /** @var array<string, mixed> $data */
    $data = $data ?? [];
    $identity = $data['account_identity'] ?? [];
    $coverage = $data['data_coverage'] ?? [];
    $workspaceState = $data['workspace_state'] ?? 'no_connection';
    $kpis = $data['kpis'] ?? [];
    $primary = $data['primary_result'] ?? null;
    $findingsOpen = $data['findings']['open'] ?? collect();
    $findingGroups = $data['finding_groups'] ?? [];
    $recommendations = $data['recommendations'] ?? collect();
    $campaigns = $data['campaigns'] ?? [];
    $caveats = $data['caveats'] ?? [];
    $comparison = $data['comparison'] ?? ['available' => false];

    $formatKpi = function (array $kpi) {
        $value = $kpi['value'] ?? null;
        if (! is_numeric($value)) {
            return '—';
        }
        return match ($kpi['type'] ?? 'count') {
            'percentage_point' => MetaPercentage::format($value),
            'currency' => number_format((float) $value, 2),
            'decimal' => number_format((float) $value, 2),
            default => number_format((float) $value, 0),
        };
    };

    $coverageLabels = [
        'account' => 'Account',
        'campaigns' => 'Campaigns',
        'adsets' => 'Ad Sets',
        'ads' => 'Ads',
        'creative' => 'Creative',
        'attribution' => 'Attribution',
        'result_interpretation' => 'Result Interpretation',
    ];

    $stateLabels = [
        'no_connection' => 'No Meta Ad Account connected yet.',
        'no_data' => 'Connected — no data collected yet. Run Collect live data.',
        'collection_failed' => 'Last collection failed. Try Collect live data again.',
        'collection_partial' => 'Latest collection is partial — some areas below are incomplete.',
        'data_available' => null,
    ];
@endphp

<div class="mox-website-workspace mox-meta-workspace">
    <div class="mox-section-head">
        <div>
            <h3 class="mox-section-title">
                {{ $identity['name'] ?? 'Meta Ad Account' }}
                @if (! empty($identity['external_id']))
                    <span class="mox-muted">({{ $identity['external_id'] }})</span>
                @endif
            </h3>
            <p class="mox-section-sub">
                @if (! empty($identity['business_name']))
                    Meta Business: {{ $identity['business_name'] }} ·
                @endif
                @if (! empty($identity['currency']))
                    Currency {{ $identity['currency'] }} ·
                @endif
                @if (! empty($identity['timezone']))
                    Timezone {{ $identity['timezone'] }} ·
                @endif
                {{ $data['period_label'] ?? 'Last 28 complete days vs previous period' }}
            </p>
        </div>
        @if (! empty($data['last_updated_human']))
            <div class="mox-meta-pill">Updated {{ $data['last_updated_human'] }}</div>
        @endif
    </div>

    @if (($stateLabels[$workspaceState] ?? null) !== null)
        <div class="mox-empty">{{ $stateLabels[$workspaceState] }}</div>
    @endif

    <section class="mox-panel">
        <div class="mox-panel__head"><h4>Data coverage</h4></div>
        <div class="mox-kpi-grid">
            @foreach ($coverageLabels as $key => $label)
                <div class="mox-kpi">
                    <div class="mox-kpi__label">{{ $label }}</div>
                    <div class="mox-kpi__value">{{ $coverage[$key] ?? 'Unknown' }}</div>
                </div>
            @endforeach
        </div>
    </section>

    @if ($caveats !== [])
        <p class="mox-muted">{{ implode(' · ', $caveats) }}</p>
    @endif

    <div class="mox-kpi-grid">
        @forelse ($kpis as $kpi)
            <div class="mox-kpi">
                <div class="mox-kpi__label">{{ $kpi['label'] ?? '' }}</div>
                <div class="mox-kpi__value">{{ $formatKpi($kpi) }}</div>
                @if (is_numeric($kpi['delta_percent'] ?? null))
                    <div class="mox-muted">{{ number_format((float) $kpi['delta_percent'], 1) }}% vs prior</div>
                @endif
            </div>
        @empty
            <div class="mox-empty">No Meta Ads performance Evidence yet. Collect live data (read-only).</div>
        @endforelse
    </div>

    @if (! $comparison['available'] && $kpis !== [])
        <p class="mox-muted">No complete prior period is available yet — deltas above are hidden until one exists.</p>
    @endif

    <section class="mox-panel">
        <div class="mox-panel__head"><h4>Primary result (platform)</h4></div>
        @if ($primary === null)
            <div class="mox-empty mox-empty--compact">No account Evidence yet.</div>
        @elseif ($primary['status'] === 'deferred')
            <p>Deferred — account level lacks a campaign objective. See Performance for campaign/ad set level results.</p>
        @elseif ($primary['status'] === 'unresolved')
            <p>Mixed / Unresolved — {{ $primary['reason'] ?? 'objective and optimization goal disagree on a result type.' }}</p>
        @elseif ($primary['status'] === 'none')
            <p>No Meta actions observed in this period.</p>
        @else
            <p>
                <strong>{{ number_format((float) ($primary['count'] ?? 0), 0) }}</strong>
                {{ $primary['human_label'] ?? 'Meta-attributed results' }}
                @if (is_numeric($primary['cost_per_result'] ?? null))
                    · Cost/result {{ number_format((float) $primary['cost_per_result'], 2) }}
                @endif
            </p>
            @if ($primary['status'] === 'zero')
                <p class="mox-muted">Resolved result type observed zero count this period.</p>
            @endif
        @endif
    </section>

    <div class="mox-grid-2">
        <section class="mox-panel">
            <div class="mox-panel__head"><h4>Top campaigns by spend</h4></div>
            @if ($campaigns === [])
                <div class="mox-empty mox-empty--compact">No campaign Evidence.</div>
            @else
                <div class="mox-table-wrap">
                    <table class="mox-table">
                        <thead>
                            <tr>
                                <th>Campaign</th>
                                <th class="mox-num">Spend</th>
                                <th>Primary result</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (array_slice($campaigns, 0, 8) as $row)
                                <tr>
                                    <td>{{ $row['name'] ?? '—' }}</td>
                                    <td class="mox-num">{{ is_numeric($row['spend'] ?? null) ? number_format((float) $row['spend'], 2) : '—' }}</td>
                                    <td>
                                        @if (($row['primary_result_status'] ?? '') === 'unresolved')
                                            Mixed / Unresolved
                                        @elseif (($row['primary_result_status'] ?? '') === 'deferred')
                                            Deferred
                                        @elseif (is_numeric($row['primary_result_count'] ?? null))
                                            {{ number_format((float) $row['primary_result_count'], 0) }}
                                            @if (! empty($row['primary_result_human_label']))
                                                <span class="mox-muted">({{ $row['primary_result_human_label'] }})</span>
                                            @endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="mox-panel">
            <div class="mox-panel__head"><h4>Attention</h4></div>
            @if ($findingGroups !== [])
                @foreach (array_slice($findingGroups, 0, 6) as $group)
                    <div class="mox-finding-row">
                        <span class="mox-sev mox-sev--{{ $group['severity'] }}">{{ strtoupper($group['severity']) }}</span>
                        <span>
                            {{ $group['title'] }}
                            @if ($group['count'] > 1)
                                <span class="mox-muted">× {{ $group['count'] }}</span>
                            @endif
                        </span>
                    </div>
                @endforeach
            @else
                @forelse ($findingsOpen->take(6) as $finding)
                    <div class="mox-finding-row">
                        <span class="mox-sev mox-sev--{{ $finding->severity }}">{{ strtoupper($finding->severity) }}</span>
                        <span>{{ $finding->title }}</span>
                    </div>
                @empty
                    <div class="mox-empty mox-empty--compact">No open Findings.</div>
                @endforelse
            @endif
            <p class="mox-muted" style="margin-top:0.75rem;">Open Recommendations: {{ $recommendations->count() }} · Read-only — no Meta mutations.</p>
        </section>
    </div>
</div>
