@php
    /** @var array<string, mixed> $data */
    $data = $data ?? [];
    $kpis = $data['kpis'] ?? [];
    $primary = $data['primary_result'] ?? null;
    $findingsOpen = $data['findings']['open'] ?? collect();
    $recommendations = $data['recommendations'] ?? collect();
    $campaigns = $data['campaigns'] ?? [];
    $caveats = $data['caveats'] ?? [];
@endphp

<div class="mox-website-workspace">
    <div class="mox-section-head">
        <div>
            <h3 class="mox-section-title">Account snapshot</h3>
            <p class="mox-section-sub">{{ $data['period_label'] ?? 'Last 28 complete days vs previous period' }}</p>
        </div>
        @if (! empty($data['last_updated_human']))
            <div class="mox-meta-pill">Updated {{ $data['last_updated_human'] }}</div>
        @endif
    </div>

    @if ($caveats !== [])
        <p class="mox-muted">{{ implode(' · ', $caveats) }}</p>
    @endif

    <div class="mox-kpi-grid">
        @forelse ($kpis as $kpi)
            <div class="mox-kpi">
                <div class="mox-kpi__label">{{ $kpi['label'] ?? '' }}</div>
                <div class="mox-kpi__value">
                    @php $v = $kpi['value'] ?? null; @endphp
                    @if (is_numeric($v))
                        @if (in_array($kpi['key'] ?? '', ['ctr'], true))
                            {{ number_format(((float) $v) * 100, 2) }}%
                        @elseif (in_array($kpi['key'] ?? '', ['spend', 'cpc', 'cpm'], true))
                            {{ number_format((float) $v, 2) }}
                        @else
                            {{ number_format((float) $v, 0) }}
                        @endif
                    @else
                        —
                    @endif
                </div>
                @if (is_numeric($kpi['delta_percent'] ?? null))
                    <div class="mox-muted">{{ number_format((float) $kpi['delta_percent'], 1) }}% vs prior</div>
                @endif
            </div>
        @empty
            <div class="mox-empty">No Meta Ads performance Evidence yet. Collect live data (read-only).</div>
        @endforelse

        @if ($primary !== null)
            <div class="mox-kpi">
                <div class="mox-kpi__label">Primary result (platform)</div>
                <div class="mox-kpi__value">
                    @if (($primary['status'] ?? '') === 'unresolved')
                        Mixed / Unresolved
                    @elseif (($primary['status'] ?? '') === 'none')
                        —
                    @elseif (is_numeric($primary['count'] ?? null))
                        {{ number_format((float) $primary['count'], 0) }}
                        @if (! empty($primary['raw_action_type']))
                            <span class="mox-muted">({{ $primary['raw_action_type'] }})</span>
                        @endif
                    @else
                        —
                    @endif
                </div>
                @if (is_numeric($primary['cost_per_result'] ?? null))
                    <div class="mox-muted">Cost/result {{ number_format((float) $primary['cost_per_result'], 2) }}</div>
                @endif
            </div>
        @endif
    </div>

    <p class="mox-muted">{{ $data['actions_note'] ?? '' }}</p>

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
                                <th class="mox-num">Clicks</th>
                                <th>Primary result</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (array_slice($campaigns, 0, 8) as $row)
                                <tr>
                                    <td>{{ $row['name'] ?? '—' }}</td>
                                    <td class="mox-num">{{ is_numeric($row['spend'] ?? null) ? number_format((float) $row['spend'], 2) : '—' }}</td>
                                    <td class="mox-num">{{ is_numeric($row['clicks'] ?? null) ? number_format((float) $row['clicks'], 0) : '—' }}</td>
                                    <td>
                                        @if (($row['primary_result_status'] ?? '') === 'unresolved')
                                            Mixed / Unresolved
                                        @elseif (is_numeric($row['primary_result_count'] ?? null))
                                            {{ number_format((float) $row['primary_result_count'], 0) }}
                                            @if (! empty($row['primary_result_type']))
                                                ({{ $row['primary_result_type'] }})
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
            @forelse ($findingsOpen->take(6) as $finding)
                <div class="mox-finding-row">
                    <span class="mox-sev mox-sev--{{ $finding->severity }}">{{ strtoupper($finding->severity) }}</span>
                    <span>{{ $finding->title }}</span>
                </div>
            @empty
                <div class="mox-empty mox-empty--compact">No open Findings.</div>
            @endforelse
            <p class="mox-muted" style="margin-top:0.75rem;">Open Recommendations: {{ $recommendations->count() }} · Read-only — no Meta mutations.</p>
        </section>
    </div>
</div>
