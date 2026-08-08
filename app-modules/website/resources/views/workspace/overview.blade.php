@php
    /** @var array<string, mixed> $data */
    $data = $data ?? [];
    $kpis = $data['kpis'] ?? [];
    $findingsOpen = $data['findings']['open'] ?? collect();
    $recommendations = $data['recommendations'] ?? collect();
    $diagnosis = $data['diagnosis'] ?? [];
    $connections = $data['connections'] ?? [];
@endphp

<div class="mox-website-workspace">
    <div class="mox-section-head">
        <div>
            <h3 class="mox-section-title">Performance snapshot</h3>
            <p class="mox-section-sub">{{ $data['period_label'] ?? 'Last 28 complete days vs previous period' }}</p>
        </div>
        @if (! empty($data['last_updated_human']))
            <div class="mox-meta-pill">Updated {{ $data['last_updated_human'] }}</div>
        @endif
    </div>

    @include('website::workspace.partials.kpi-grid', ['kpis' => $kpis])

    <div class="mox-grid-2">
        <section class="mox-panel">
            <div class="mox-panel__head">
                <h4>Google Search performance</h4>
                <span class="mox-muted">Daily clicks</span>
            </div>
            @include('website::workspace.partials.sparkline', [
                'values' => $data['gsc_daily']['clicks'] ?? [],
                'label' => 'Search Console clicks',
            ])
        </section>

        <section class="mox-panel">
            <div class="mox-panel__head">
                <h4>Acquisition</h4>
                <span class="mox-muted">GA4 channels</span>
            </div>
            @include('website::workspace.partials.metric-table', [
                'rows' => array_slice($data['acquisition'] ?? [], 0, 6),
                'columns' => [
                    ['key' => 'sessionDefaultChannelGroup', 'label' => 'Channel'],
                    ['key' => 'sessions', 'label' => 'Sessions', 'format' => 'number', 'class' => 'mox-num'],
                    ['key' => 'totalUsers', 'label' => 'Users', 'format' => 'number', 'class' => 'mox-num'],
                ],
            ])
        </section>
    </div>

    <div class="mox-grid-2">
        <section class="mox-panel">
            <div class="mox-panel__head">
                <h4>Top search queries</h4>
            </div>
            @include('website::workspace.partials.metric-table', [
                'rows' => array_slice($data['queries'] ?? [], 0, 8),
                'columns' => [
                    ['key' => 'query', 'label' => 'Query'],
                    ['key' => 'clicks', 'label' => 'Clicks', 'format' => 'number', 'class' => 'mox-num'],
                    ['key' => 'impressions', 'label' => 'Impr.', 'format' => 'number', 'class' => 'mox-num'],
                    ['key' => 'ctr', 'label' => 'CTR', 'format' => 'percent_ratio', 'class' => 'mox-num'],
                    ['key' => 'position', 'label' => 'Pos.', 'format' => 'position', 'class' => 'mox-num'],
                ],
            ])
        </section>

        <section class="mox-panel">
            <div class="mox-panel__head">
                <h4>Top landing pages</h4>
            </div>
            @include('website::workspace.partials.metric-table', [
                'rows' => array_slice($data['landing_pages'] ?? [], 0, 8),
                'columns' => [
                    ['key' => 'landingPage', 'label' => 'Landing page'],
                    ['key' => 'sessions', 'label' => 'Sessions', 'format' => 'number', 'class' => 'mox-num'],
                    ['key' => 'totalUsers', 'label' => 'Users', 'format' => 'number', 'class' => 'mox-num'],
                    ['key' => 'engagedSessions', 'label' => 'Engaged', 'format' => 'number', 'class' => 'mox-num'],
                ],
            ])
        </section>
    </div>

    @php $opps = $data['seo_opportunities'] ?? []; @endphp
    @if (($opps['count'] ?? 0) > 0)
        <section class="mox-panel">
            <div class="mox-panel__head">
                <h4>SEO Opportunities</h4>
                <span class="mox-muted">{{ $opps['count'] }} queries close to stronger positions</span>
            </div>
            <ul class="mox-list">
                @foreach (($opps['overview'] ?? []) as $row)
                    <li>
                        <div class="mox-list__row">
                            <strong>{{ $row['query'] }}</strong>
                            <span class="mox-muted">pos {{ $row['position_label'] }} · {{ $row['impressions_label'] }} impr.</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="mox-grid-3">
        <section class="mox-panel">
            <div class="mox-panel__head">
                <h4>Needs attention</h4>
            </div>
            @if ($findingsOpen->isEmpty())
                <div class="mox-empty mox-empty--compact">No open issues right now.</div>
            @else
                <ul class="mox-list">
                    @foreach ($findingsOpen->take(5) as $finding)
                        <li>
                            <a href="{{ \App\Filament\App\Resources\Findings\FindingResource::getUrl('view', ['record' => $finding]) }}" class="mox-list__link">
                                <span class="mox-sev mox-sev--{{ $finding->severity }}">{{ strtoupper($finding->severity) }}</span>
                                <span>{{ $finding->title }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="mox-panel">
            <div class="mox-panel__head">
                <h4>Recommended actions</h4>
            </div>
            @if ($recommendations->isEmpty())
                <div class="mox-empty mox-empty--compact">No open recommendations.</div>
            @else
                <ul class="mox-list">
                    @foreach ($recommendations->take(5) as $recommendation)
                        <li>
                            <div class="mox-list__row">
                                <span class="mox-sev mox-sev--{{ $recommendation->priority }}">{{ strtoupper($recommendation->priority) }}</span>
                                <span>{{ $recommendation->title }}</span>
                            </div>
                            @if (filled($recommendation->action))
                                <div class="mox-muted mox-list__hint">{{ \Illuminate\Support\Str::limit($recommendation->action, 120) }}</div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="mox-panel">
            <div class="mox-panel__head">
                <h4>Health & connections</h4>
            </div>
            <div class="mox-stack">
                <div>
                    <div class="mox-muted">Technical health</div>
                    <div>{{ $diagnosis['summary'] ?? '—' }}</div>
                </div>
                <div>
                    <div class="mox-muted">Data sources</div>
                    <div>{{ $data['connection_health'] ?? '—' }}</div>
                </div>
                <ul class="mox-mini-status">
                    @foreach ($connections as $card)
                        <li>
                            <span>{{ $card['label'] }}</span>
                            <span class="{{ ($card['connected'] ?? false) ? 'mox-ok' : 'mox-warn' }}">
                                {{ ($card['connected'] ?? false) ? 'Connected' : 'Not connected' }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    </div>
</div>
