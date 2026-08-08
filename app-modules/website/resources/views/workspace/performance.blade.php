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
