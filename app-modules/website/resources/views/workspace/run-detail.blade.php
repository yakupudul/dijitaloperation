@php
    $presentation = $presentation ?? [];
    $run = $run ?? null;
@endphp

<div class="mox-website-workspace">
    <div class="mox-section-head">
        <div>
            <h3 class="mox-section-title">{{ $presentation['title'] ?? 'Website activity' }}</h3>
            <p class="mox-section-sub">{{ $presentation['period_label'] ?? '' }}</p>
        </div>
    </div>

    @include('website::workspace.partials.kpi-grid', ['kpis' => $presentation['kpis'] ?? []])

    @if (($presentation['capability'] ?? null) === 'search_console')
        <div class="mox-grid-2">
            <section class="mox-panel">
                <div class="mox-panel__head"><h4>Daily clicks</h4></div>
                @include('website::workspace.partials.sparkline', ['values' => $presentation['gsc_daily']['clicks'] ?? []])
            </section>
            <section class="mox-panel">
                <div class="mox-panel__head"><h4>Top queries</h4></div>
                @include('website::workspace.partials.metric-table', [
                    'rows' => array_slice($presentation['queries'] ?? [], 0, 10),
                    'columns' => [
                        ['key' => 'query', 'label' => 'Query'],
                        ['key' => 'clicks', 'label' => 'Clicks', 'format' => 'number', 'class' => 'mox-num'],
                        ['key' => 'impressions', 'label' => 'Impr.', 'format' => 'number', 'class' => 'mox-num'],
                        ['key' => 'ctr', 'label' => 'CTR', 'format' => 'percent_ratio', 'class' => 'mox-num'],
                        ['key' => 'position', 'label' => 'Pos.', 'format' => 'position', 'class' => 'mox-num'],
                    ],
                ])
            </section>
        </div>
        <section class="mox-panel">
            <div class="mox-panel__head"><h4>Top pages</h4></div>
            @include('website::workspace.partials.metric-table', [
                'rows' => array_slice($presentation['pages'] ?? [], 0, 10),
                'columns' => [
                    ['key' => 'page', 'label' => 'Page'],
                    ['key' => 'clicks', 'label' => 'Clicks', 'format' => 'number', 'class' => 'mox-num'],
                    ['key' => 'impressions', 'label' => 'Impressions', 'format' => 'number', 'class' => 'mox-num'],
                    ['key' => 'ctr', 'label' => 'CTR', 'format' => 'percent_ratio', 'class' => 'mox-num'],
                    ['key' => 'position', 'label' => 'Position', 'format' => 'position', 'class' => 'mox-num'],
                ],
            ])
        </section>
    @endif

    @if (($presentation['capability'] ?? null) === 'ga4')
        <div class="mox-grid-2">
            <section class="mox-panel">
                <div class="mox-panel__head"><h4>Acquisition</h4></div>
                @include('website::workspace.partials.metric-table', [
                    'rows' => $presentation['acquisition'] ?? [],
                    'columns' => [
                        ['key' => 'sessionDefaultChannelGroup', 'label' => 'Channel'],
                        ['key' => 'sessions', 'label' => 'Sessions', 'format' => 'number', 'class' => 'mox-num'],
                        ['key' => 'totalUsers', 'label' => 'Users', 'format' => 'number', 'class' => 'mox-num'],
                    ],
                ])
            </section>
            <section class="mox-panel">
                <div class="mox-panel__head"><h4>Landing pages</h4></div>
                @include('website::workspace.partials.metric-table', [
                    'rows' => array_slice($presentation['landing_pages'] ?? [], 0, 10),
                    'columns' => [
                        ['key' => 'landingPage', 'label' => 'Landing page'],
                        ['key' => 'sessions', 'label' => 'Sessions', 'format' => 'number', 'class' => 'mox-num'],
                        ['key' => 'totalUsers', 'label' => 'Users', 'format' => 'number', 'class' => 'mox-num'],
                    ],
                ])
            </section>
        </div>
    @endif

    @if (! empty($presentation['findings_lifecycle']))
        <section class="mox-panel">
            <div class="mox-panel__head"><h4>Findings from this refresh</h4></div>
            <div class="mox-muted">
                Opened {{ data_get($presentation, 'findings_lifecycle.opened', 0) }},
                updated {{ data_get($presentation, 'findings_lifecycle.updated', 0) }},
                reopened {{ data_get($presentation, 'findings_lifecycle.reopened', 0) }},
                resolved {{ data_get($presentation, 'findings_lifecycle.resolved', 0) }}.
            </div>
        </section>
    @endif
</div>
