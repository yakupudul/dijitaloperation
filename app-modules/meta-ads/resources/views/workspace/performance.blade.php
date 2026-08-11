@php
    $campaigns = $data['campaigns'] ?? [];
    $adsets = $data['adsets'] ?? [];
    $ads = $data['ads'] ?? [];
    $caveats = $data['caveats'] ?? [];
@endphp

<div class="mox-website-workspace">
    <div class="mox-section-head">
        <div>
            <h3 class="mox-section-title">Performance</h3>
            <p class="mox-section-sub">{{ $data['period_label'] ?? 'Analyzed period from Evidence' }} · Platform metrics only (not verified business profit).</p>
        </div>
    </div>

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
                            <th class="mox-num">Impr.</th>
                            <th class="mox-num">Reach</th>
                            <th class="mox-num">Clicks</th>
                            <th class="mox-num">CTR</th>
                            <th>Primary result</th>
                            <th>Actions (not summed)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($campaigns as $row)
                            <tr>
                                <td>{{ $row['name'] ?? '—' }}</td>
                                <td>{{ $row['status'] ?? '—' }}</td>
                                <td>{{ $row['objective'] ?? '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['spend'] ?? null) ? number_format((float) $row['spend'], 2) : '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['impressions'] ?? null) ? number_format((float) $row['impressions'], 0) : '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['reach'] ?? null) ? number_format((float) $row['reach'], 0) : '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['clicks'] ?? null) ? number_format((float) $row['clicks'], 0) : '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['ctr'] ?? null) ? number_format(((float) $row['ctr']) * 100, 2).'%' : '—' }}</td>
                                <td>
                                    @if (($row['primary_result_status'] ?? '') === 'unresolved')
                                        Mixed / Unresolved
                                    @elseif (is_numeric($row['primary_result_count'] ?? null))
                                        {{ number_format((float) $row['primary_result_count'], 0) }}
                                        @if (is_numeric($row['primary_result_cost'] ?? null))
                                            · {{ number_format((float) $row['primary_result_cost'], 2) }}/result
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    @forelse ($row['actions'] ?? [] as $action)
                                        <div class="mox-muted">{{ $action['raw_action_type'] ?? '—' }}: {{ is_numeric($action['count'] ?? null) ? number_format((float) $action['count'], 0) : '—' }}</div>
                                    @empty
                                        —
                                    @endforelse
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
                            <th class="mox-num">Spend</th>
                            <th class="mox-num">Clicks</th>
                            <th>Primary result</th>
                            <th>Actions (not summed)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($adsets as $row)
                            <tr>
                                <td>{{ $row['name'] ?? '—' }}</td>
                                <td>{{ $row['campaign_name'] ?? '—' }}</td>
                                <td>{{ $row['status'] ?? '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['spend'] ?? null) ? number_format((float) $row['spend'], 2) : '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['clicks'] ?? null) ? number_format((float) $row['clicks'], 0) : '—' }}</td>
                                <td>
                                    @if (($row['primary_result_status'] ?? '') === 'unresolved')
                                        Mixed / Unresolved
                                    @elseif (is_numeric($row['primary_result_count'] ?? null))
                                        {{ number_format((float) $row['primary_result_count'], 0) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    @forelse ($row['actions'] ?? [] as $action)
                                        <div class="mox-muted">{{ $action['raw_action_type'] ?? '—' }}: {{ is_numeric($action['count'] ?? null) ? number_format((float) $action['count'], 0) : '—' }}</div>
                                    @empty
                                        —
                                    @endforelse
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
                            <th class="mox-num">Spend</th>
                            <th class="mox-num">Clicks</th>
                            <th>Primary result</th>
                            <th>Actions (not summed)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ads as $row)
                            <tr>
                                <td>{{ $row['name'] ?? '—' }}</td>
                                <td>{{ $row['adset_name'] ?? '—' }}</td>
                                <td>{{ $row['campaign_name'] ?? '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['spend'] ?? null) ? number_format((float) $row['spend'], 2) : '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['clicks'] ?? null) ? number_format((float) $row['clicks'], 0) : '—' }}</td>
                                <td>
                                    @if (($row['primary_result_status'] ?? '') === 'unresolved')
                                        Mixed / Unresolved
                                    @elseif (is_numeric($row['primary_result_count'] ?? null))
                                        {{ number_format((float) $row['primary_result_count'], 0) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    @forelse ($row['actions'] ?? [] as $action)
                                        <div class="mox-muted">{{ $action['raw_action_type'] ?? '—' }}: {{ is_numeric($action['count'] ?? null) ? number_format((float) $action['count'], 0) : '—' }}</div>
                                    @empty
                                        —
                                    @endforelse
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
