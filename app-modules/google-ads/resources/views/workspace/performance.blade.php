@php
    $campaigns = $data['campaigns'] ?? [];
    $kpis = $data['kpis'] ?? [];
    $conversions = $data['conversion_actions'] ?? [];
@endphp

<div class="mox-website-workspace">
    <div class="mox-section-head">
        <div>
            <h3 class="mox-section-title">Performance</h3>
            <p class="mox-section-sub">{{ $data['period_label'] ?? 'Analyzed period from Evidence' }} · Platform metrics only (not verified business profit).</p>
        </div>
    </div>

    <section class="mox-panel">
        <div class="mox-panel__head"><h4>Campaign performance</h4></div>
        @if ($campaigns === [])
            <div class="mox-empty">No campaign Evidence.</div>
        @else
            <div class="mox-table-wrap">
                <table class="mox-table">
                    <thead>
                        <tr>
                            <th>Campaign</th>
                            <th>Status</th>
                            <th>Channel</th>
                            <th class="mox-num">Spend</th>
                            <th class="mox-num">Impr.</th>
                            <th class="mox-num">Clicks</th>
                            <th class="mox-num">CTR</th>
                            <th class="mox-num">Conv.</th>
                            <th class="mox-num">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($campaigns as $row)
                            <tr>
                                <td>{{ $row['campaign_name'] ?? '—' }}</td>
                                <td>{{ $row['status'] ?? '—' }}</td>
                                <td>{{ $row['channel'] ?? '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['cost'] ?? null) ? number_format((float) $row['cost'], 2) : '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['impressions'] ?? null) ? number_format((float) $row['impressions'], 0) : '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['clicks'] ?? null) ? number_format((float) $row['clicks'], 0) : '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['ctr'] ?? null) ? number_format(((float) $row['ctr']) * 100, 2).'%' : '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['conversions'] ?? null) ? number_format((float) $row['conversions'], 1) : '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['conversion_value'] ?? null) ? number_format((float) $row['conversion_value'], 2) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="mox-panel">
        <div class="mox-panel__head"><h4>Measurement configuration</h4></div>
        @if (! ($conversions['available'] ?? false))
            <div class="mox-empty">No conversion-action Evidence.</div>
        @else
            <p class="mox-muted">
                Actions {{ $conversions['action_count'] ?? 0 }} · Enabled {{ $conversions['enabled_count'] ?? 0 }} ·
                Primary/included {{ $conversions['usable_primary_or_included_count'] ?? 0 }}.
                Configuration only — does not prove browser tags fire.
            </p>
            <div class="mox-table-wrap">
                <table class="mox-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Status</th>
                            <th>Category</th>
                            <th>Origin</th>
                            <th>Primary</th>
                            <th>In conversions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (($conversions['actions'] ?? []) as $action)
                            <tr>
                                <td>{{ $action['name'] ?? $action['conversion_action_id'] ?? '—' }}</td>
                                <td>{{ $action['status'] ?? '—' }}</td>
                                <td>{{ $action['category'] ?? '—' }}</td>
                                <td>{{ $action['origin'] ?? '—' }}</td>
                                <td>{{ isset($action['primary_for_goal']) ? ($action['primary_for_goal'] ? 'yes' : 'no') : '—' }}</td>
                                <td>{{ isset($action['include_in_conversions_metric']) ? ($action['include_in_conversions_metric'] ? 'yes' : 'no') : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
