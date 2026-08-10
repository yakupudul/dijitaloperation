@php
    $connections = $data['connections'] ?? [];
    $landing = $data['landing'] ?? [];
@endphp

<div class="mox-website-workspace">
    <div class="mox-section-head">
        <div>
            <h3 class="mox-section-title">Connections</h3>
            <p class="mox-section-sub">Read-only Google Ads bindings. Credentials are never shown.</p>
        </div>
    </div>

    <section class="mox-panel">
        @if ($connections === [])
            <div class="mox-empty">No Google Ads bindings on this asset.</div>
        @else
            <div class="mox-table-wrap">
                <table class="mox-table">
                    <thead>
                        <tr>
                            <th>Resource</th>
                            <th>Customer ID</th>
                            <th>Integration</th>
                            <th>Binding status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($connections as $row)
                            <tr>
                                <td>{{ $row['resource_name'] ?? '—' }}</td>
                                <td>{{ $row['external_id'] ?? '—' }}</td>
                                <td>{{ $row['integration_name'] ?? '—' }}</td>
                                <td>{{ $row['status'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="mox-panel">
        <div class="mox-panel__head"><h4>Landing final URLs (bounded)</h4></div>
        <p class="mox-muted">{{ (int) ($landing['final_url_count'] ?? 0) }} URLs collected.</p>
        <ul>
            @foreach (($landing['urls'] ?? []) as $url)
                <li>{{ $url }}</li>
            @endforeach
        </ul>
    </section>
</div>
