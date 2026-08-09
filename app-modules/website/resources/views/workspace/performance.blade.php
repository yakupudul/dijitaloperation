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

    @php
        $seo = $data['seo_intelligence'] ?? [];
        $seoState = $seo['state'] ?? 'no_data';
    @endphp
    <section class="mox-panel">
        <div class="mox-panel__head">
            <h4>Organic Visibility</h4>
            <span class="mox-muted">
                External SEO intelligence
                @if (! empty($seo['market']['label']))
                    · {{ $seo['market']['label'] }}
                @endif
            </span>
        </div>
        <p class="mox-section-sub">{{ $seo['estimate_disclaimer'] ?? 'Estimated DataForSEO metrics are not GA4 measured traffic.' }}</p>

        @if ($seoState === 'dataforseo_not_configured')
            <div class="mox-empty mox-empty--compact">
                {{ $seo['state_message'] ?? 'Connect DataForSEO in Settings → Integrations to enable market-wide keyword visibility.' }}
            </div>
        @elseif ($seoState === 'seo_market_not_configured')
            <div class="mox-empty mox-empty--compact">
                {{ $seo['state_message'] ?? 'Choose the Website\'s SEO market and language before running external keyword analysis.' }}
                <div class="mox-conn-card__actions" style="margin-top: .75rem;">
                    <span class="mox-muted">Open Settings → Configure SEO market</span>
                </div>
            </div>
        @elseif ($seoState === 'no_data')
            <div class="mox-empty mox-empty--compact">
                {{ $seo['state_message'] ?? 'No external SEO intelligence yet.' }}
                Use <strong>Refresh SEO intelligence</strong> when you are ready (provider credits may be used on a cache miss).
            </div>
        @elseif ($seoState === 'no_results')
            <div class="mox-empty mox-empty--compact">{{ $seo['state_message'] }}</div>
        @else
            @if (($seo['kpis'] ?? []) !== [])
                <div class="mox-kpi-grid">
                    @foreach ($seo['kpis'] as $kpi)
                        <div class="mox-kpi">
                            <div class="mox-kpi__label">{{ $kpi['label'] }}</div>
                            <div class="mox-kpi__value">{{ $kpi['value'] }}</div>
                            @if (! empty($kpi['note']))
                                <div class="mox-muted mox-kpi__delta">{{ $kpi['note'] }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @php
                $ranked = $seo['ranked_keywords'] ?? [];
                $rankedColumns = $seo['ranked_columns'] ?? [];
            @endphp
            @if ($ranked !== [])
                <div class="mox-panel__head mox-panel__head--spaced">
                    <h4>Ranked keywords</h4>
                    <span class="mox-muted">Bounded organic rankings</span>
                </div>
                <div class="mox-table-wrap">
                    <table class="mox-table">
                        <thead>
                            <tr>
                                <th>Keyword</th>
                                <th class="mox-num">Position</th>
                                <th>Page</th>
                                @if (in_array('search_volume_label', $rankedColumns, true))
                                    <th class="mox-num">Search volume</th>
                                @endif
                                @if (in_array('keyword_difficulty', $rankedColumns, true))
                                    <th class="mox-num">Difficulty</th>
                                @endif
                                @if (in_array('cpc_label', $rankedColumns, true))
                                    <th class="mox-num">CPC</th>
                                @endif
                                @if (in_array('trend_label', $rankedColumns, true))
                                    <th class="mox-num">Trend</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($ranked as $row)
                                <tr>
                                    <td><strong>{{ $row['keyword'] }}</strong></td>
                                    <td class="mox-num">{{ $row['position_label'] }}</td>
                                    <td>
                                        @if (! empty($row['page']))
                                            <a class="mox-link" href="{{ $row['page'] }}" title="{{ $row['page'] }}" target="_blank" rel="noopener noreferrer">{{ $row['page_path'] ?? $row['page'] }}</a>
                                        @else
                                            <span class="mox-muted">—</span>
                                        @endif
                                    </td>
                                    @if (in_array('search_volume_label', $rankedColumns, true))
                                        <td class="mox-num">{{ $row['search_volume_label'] ?? '—' }}</td>
                                    @endif
                                    @if (in_array('keyword_difficulty', $rankedColumns, true))
                                        <td class="mox-num">{{ $row['keyword_difficulty'] ?? '—' }}</td>
                                    @endif
                                    @if (in_array('cpc_label', $rankedColumns, true))
                                        <td class="mox-num">{{ $row['cpc_label'] ?? '—' }}</td>
                                    @endif
                                    @if (in_array('trend_label', $rankedColumns, true))
                                        <td class="mox-num">{{ $row['trend_label'] ?? '—' }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @php $kwOpps = $seo['keyword_opportunities'] ?? []; @endphp
            <div class="mox-panel__head mox-panel__head--spaced">
                <h4>Keyword Opportunities</h4>
                <span class="mox-muted">Cross-source heuristic · {{ $kwOpps['count'] ?? 0 }}</span>
            </div>
            <p class="mox-section-sub">{{ $kwOpps['note'] ?? '' }}</p>
            @if (($kwOpps['opportunities'] ?? []) === [])
                <div class="mox-empty mox-empty--compact">No qualifying keyword opportunities for the current Evidence set.</div>
            @else
                <div class="mox-table-wrap">
                    <table class="mox-table">
                        <thead>
                            <tr>
                                <th>Keyword</th>
                                <th>Category</th>
                                <th>Priority</th>
                                <th class="mox-num">Search volume</th>
                                <th class="mox-num">DFS rank</th>
                                <th>Why</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($kwOpps['opportunities'] as $row)
                                <tr>
                                    <td><strong>{{ $row['keyword'] }}</strong></td>
                                    <td>{{ $row['category_label'] }}</td>
                                    <td>{{ $row['priority'] }}</td>
                                    <td class="mox-num">{{ $row['search_volume_label'] }}</td>
                                    <td class="mox-num">{{ $row['dfs_rank_label'] }}</td>
                                    <td class="mox-muted">{{ \Illuminate\Support\Str::limit($row['why'], 100) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
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
