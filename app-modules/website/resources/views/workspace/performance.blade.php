@php
    $data = $data ?? [];
@endphp

<div class="mox-website-workspace">
    <div class="mox-section-head">
        <div>
            <h3 class="mox-section-title">Website performance</h3>
            <p class="mox-section-sub">{{ $data['period_label'] ?? '' }}</p>
        </div>
    </div>

    <section class="mox-panel">
        <div class="mox-panel__head"><h4>GA4 + Search Console KPIs</h4></div>
        @include('website::workspace.partials.kpi-grid', ['kpis' => $data['kpis'] ?? []])
    </section>

    @php $opps = $data['seo_opportunities'] ?? []; @endphp
    <section class="mox-panel">
        <div class="mox-panel__head">
            <h4>SEO Opportunities</h4>
            <span class="mox-muted">Heuristic striking distance · {{ $opps['count'] ?? 0 }} queries</span>
        </div>
        <p class="mox-section-sub">{{ $opps['note'] ?? 'Striking distance is a MoxDOP heuristic, not a Google-defined metric.' }}</p>
        @if (($opps['opportunities'] ?? []) === [])
            <div class="mox-empty mox-empty--compact">No striking-distance opportunities in the current Search Console Evidence.</div>
        @else
            <div class="mox-table-wrap">
                <table class="mox-table">
                    <thead>
                        <tr>
                            <th>Query</th>
                            <th>Page</th>
                            <th class="mox-num">Position</th>
                            <th class="mox-num">Impressions</th>
                            <th class="mox-num">Clicks</th>
                            <th class="mox-num">CTR</th>
                            <th>Opportunity</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($opps['opportunities'] as $row)
                            <tr>
                                <td><strong>{{ $row['query'] }}</strong></td>
                                <td>
                                    @if (! empty($row['page']))
                                        <a class="mox-link" href="{{ $row['page'] }}" title="{{ $row['page'] }}" target="_blank" rel="noopener noreferrer">{{ $row['page_path'] ?? $row['page'] }}</a>
                                    @else
                                        <span class="mox-muted">—</span>
                                    @endif
                                </td>
                                <td class="mox-num">{{ $row['position_label'] }}</td>
                                <td class="mox-num">{{ $row['impressions_label'] }}</td>
                                <td class="mox-num">{{ $row['clicks_label'] }}</td>
                                <td class="mox-num">{{ $row['ctr_label'] }}</td>
                                <td class="mox-muted">{{ \Illuminate\Support\Str::limit($row['opportunity'], 90) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <div class="mox-grid-2">
        <section class="mox-panel">
            <div class="mox-panel__head">
                <h4>Search Console daily trend</h4>
                <span class="mox-muted">Clicks</span>
            </div>
            @include('website::workspace.partials.sparkline', ['values' => $data['gsc_daily']['clicks'] ?? [], 'label' => 'Clicks'])
            <div class="mox-panel__head mox-panel__head--spaced">
                <span class="mox-muted">Impressions</span>
            </div>
            @include('website::workspace.partials.sparkline', ['values' => $data['gsc_daily']['impressions'] ?? [], 'label' => 'Impressions'])
        </section>

        <section class="mox-panel">
            <div class="mox-panel__head"><h4>Acquisition</h4></div>
            @include('website::workspace.partials.metric-table', [
                'rows' => $data['acquisition'] ?? [],
                'columns' => [
                    ['key' => 'sessionDefaultChannelGroup', 'label' => 'Channel'],
                    ['key' => 'sessions', 'label' => 'Sessions', 'format' => 'number', 'class' => 'mox-num'],
                    ['key' => 'totalUsers', 'label' => 'Users', 'format' => 'number', 'class' => 'mox-num'],
                    ['key' => 'engagedSessions', 'label' => 'Engaged', 'format' => 'number', 'class' => 'mox-num'],
                ],
            ])
        </section>
    </div>

    <div class="mox-grid-2">
        <section class="mox-panel">
            <div class="mox-panel__head"><h4>Landing pages</h4></div>
            @include('website::workspace.partials.metric-table', [
                'rows' => $data['landing_pages'] ?? [],
                'columns' => [
                    ['key' => 'landingPage', 'label' => 'Landing page'],
                    ['key' => 'sessions', 'label' => 'Sessions', 'format' => 'number', 'class' => 'mox-num'],
                    ['key' => 'totalUsers', 'label' => 'Users', 'format' => 'number', 'class' => 'mox-num'],
                    ['key' => 'engagedSessions', 'label' => 'Engaged', 'format' => 'number', 'class' => 'mox-num'],
                    ['key' => 'engagementRate', 'label' => 'Eng. rate', 'format' => 'percent_ratio', 'class' => 'mox-num'],
                ],
            ])
        </section>

        <section class="mox-panel">
            <div class="mox-panel__head"><h4>Search queries</h4></div>
            @include('website::workspace.partials.metric-table', [
                'rows' => $data['queries'] ?? [],
                'columns' => [
                    ['key' => 'query', 'label' => 'Query'],
                    ['key' => 'clicks', 'label' => 'Clicks', 'format' => 'number', 'class' => 'mox-num'],
                    ['key' => 'impressions', 'label' => 'Impressions', 'format' => 'number', 'class' => 'mox-num'],
                    ['key' => 'ctr', 'label' => 'CTR', 'format' => 'percent_ratio', 'class' => 'mox-num'],
                    ['key' => 'position', 'label' => 'Position', 'format' => 'position', 'class' => 'mox-num'],
                ],
            ])
        </section>
    </div>

    <section class="mox-panel">
        <div class="mox-panel__head"><h4>Search pages</h4></div>
        @include('website::workspace.partials.metric-table', [
            'rows' => $data['pages'] ?? [],
            'columns' => [
                ['key' => 'page', 'label' => 'Page'],
                ['key' => 'clicks', 'label' => 'Clicks', 'format' => 'number', 'class' => 'mox-num'],
                ['key' => 'impressions', 'label' => 'Impressions', 'format' => 'number', 'class' => 'mox-num'],
                ['key' => 'ctr', 'label' => 'CTR', 'format' => 'percent_ratio', 'class' => 'mox-num'],
                ['key' => 'position', 'label' => 'Position', 'format' => 'position', 'class' => 'mox-num'],
            ],
        ])
    </section>
</div>
