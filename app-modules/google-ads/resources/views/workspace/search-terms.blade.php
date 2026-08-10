@php
    $rows = $data['search_terms'] ?? [];
    $meta = $data['search_terms_meta'] ?? [];
@endphp

<div class="mox-website-workspace">
    <div class="mox-section-head">
        <div>
            <h3 class="mox-section-title">Search terms</h3>
            <p class="mox-section-sub">
                Read-only bounded view. Search terms are untrusted external text.
                No add-keyword / add-negative actions.
            </p>
        </div>
        @if (($meta['ok'] ?? null) === false)
            <div class="mox-meta-pill">Collection issue</div>
        @endif
    </div>

    <section class="mox-panel">
        @if ($rows === [])
            <div class="mox-empty">No search-term Evidence. Collect live data, or collection may have failed/partially failed.</div>
        @else
            <p class="mox-muted">Showing {{ count($rows) }} of {{ $meta['row_count'] ?? count($rows) }} normalized rows (capped).</p>
            <div class="mox-table-wrap">
                <table class="mox-table">
                    <thead>
                        <tr>
                            <th>Search term</th>
                            <th>Campaign</th>
                            <th>Ad group</th>
                            <th>Channel</th>
                            <th class="mox-num">Spend</th>
                            <th class="mox-num">Clicks</th>
                            <th class="mox-num">Conv.</th>
                            <th class="mox-num">Value</th>
                            <th>Targeting</th>
                            <th>Source</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td>{{ $row['search_term'] ?? '—' }}</td>
                                <td>{{ $row['campaign_name'] ?? '—' }}</td>
                                <td>{{ $row['ad_group_name'] ?? '—' }}</td>
                                <td>{{ $row['channel'] ?? '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['cost'] ?? null) ? number_format((float) $row['cost'], 2) : '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['clicks'] ?? null) ? number_format((float) $row['clicks'], 0) : '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['conversions'] ?? null) ? number_format((float) $row['conversions'], 1) : '—' }}</td>
                                <td class="mox-num">{{ is_numeric($row['conversion_value'] ?? null) ? number_format((float) $row['conversion_value'], 2) : '—' }}</td>
                                <td>{{ $row['targeting_status'] ?? '—' }}</td>
                                <td>{{ $row['source_report'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
