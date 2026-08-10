@php
    /** @var array<string, mixed> $data */
    $data = $data ?? [];
    $kpis = $data['kpis'] ?? [];
    $findingsOpen = $data['findings']['open'] ?? collect();
    $recommendations = $data['recommendations'] ?? collect();
    $campaigns = $data['campaigns'] ?? [];
    $searchTerms = array_slice($data['search_terms'] ?? [], 0, 8);
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

    <div class="mox-kpi-grid">
        @forelse ($kpis as $kpi)
            <div class="mox-kpi">
                <div class="mox-kpi__label">{{ $kpi['label'] ?? '' }}</div>
                <div class="mox-kpi__value">
                    @php $v = $kpi['value'] ?? null; @endphp
                    @if (is_numeric($v))
                        @if (in_array($kpi['key'] ?? '', ['ctr'], true))
                            {{ number_format(((float) $v) * 100, 2) }}%
                        @elseif (in_array($kpi['key'] ?? '', ['cost', 'average_cpc', 'conversion_value'], true))
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
            <div class="mox-empty">No Google Ads performance Evidence yet. Collect live data (read-only).</div>
        @endforelse
    </div>

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
                                <th class="mox-num">Conv.</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (array_slice($campaigns, 0, 8) as $row)
                                <tr>
                                    <td>{{ $row['campaign_name'] ?? '—' }}</td>
                                    <td class="mox-num">{{ is_numeric($row['cost'] ?? null) ? number_format((float) $row['cost'], 2) : '—' }}</td>
                                    <td class="mox-num">{{ is_numeric($row['clicks'] ?? null) ? number_format((float) $row['clicks'], 0) : '—' }}</td>
                                    <td class="mox-num">{{ is_numeric($row['conversions'] ?? null) ? number_format((float) $row['conversions'], 1) : '—' }}</td>
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
            <p class="mox-muted" style="margin-top:0.75rem;">Open Recommendations: {{ $recommendations->count() }} · Read-only — no Ads mutations.</p>
        </section>
    </div>

    <section class="mox-panel">
        <div class="mox-panel__head"><h4>Search terms (bounded)</h4></div>
        @if ($searchTerms === [])
            <div class="mox-empty mox-empty--compact">No search-term Evidence yet.</div>
        @else
            <div class="mox-table-wrap">
                <table class="mox-table">
                    <thead>
                        <tr>
                            <th>Search term</th>
                            <th>Campaign</th>
                            <th class="mox-num">Spend</th>
                            <th class="mox-num">Conv.</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($searchTerms as $row)
                            <tr>
                                <td>{{ $row['search_term'] ?? '—' }}</td>
                                <td>{{ $row['campaign_name'] ?? '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['cost'] ?? null) ? number_format((float) $row['cost'], 2) : '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['conversions'] ?? null) ? number_format((float) $row['conversions'], 1) : '—' }}</td>
                                <td>{{ $row['targeting_status'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
