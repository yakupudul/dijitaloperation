@php
    $data = $data ?? [];
    $counts = $data['findings']['counts'] ?? [];
    $open = $data['findings']['open'] ?? collect();
    $groups = $data['findings']['health_groups'] ?? [];
    $acknowledged = $data['findings']['acknowledged'] ?? collect();
    $resolved = $data['findings']['resolved'] ?? collect();
    $recommendations = $data['recommendations'] ?? collect();
    $diagnosis = $data['diagnosis'] ?? [];
@endphp

<div class="mox-website-workspace">
    <div class="mox-section-head">
        <div>
            <h3 class="mox-section-title">Operational health</h3>
            <p class="mox-section-sub">Findings, recommendations, and technical diagnosis for this website.</p>
        </div>
    </div>

    <div class="mox-kpi-grid mox-kpi-grid--compact">
        <div class="mox-kpi-card">
            <div class="mox-kpi-card__label">High priority open</div>
            <div class="mox-kpi-card__value">{{ $counts['high'] ?? 0 }}</div>
        </div>
        <div class="mox-kpi-card">
            <div class="mox-kpi-card__label">Medium open</div>
            <div class="mox-kpi-card__value">{{ $counts['medium'] ?? 0 }}</div>
        </div>
        <div class="mox-kpi-card">
            <div class="mox-kpi-card__label">Acknowledged</div>
            <div class="mox-kpi-card__value">{{ $counts['acknowledged'] ?? 0 }}</div>
        </div>
        <div class="mox-kpi-card">
            <div class="mox-kpi-card__label">Resolved</div>
            <div class="mox-kpi-card__value">{{ $counts['resolved'] ?? 0 }}</div>
        </div>
    </div>

    <section class="mox-panel">
        <div class="mox-panel__head"><h4>Technical diagnosis</h4></div>
        <p>{{ $diagnosis['summary'] ?? '—' }}</p>
    </section>

    @if ($groups !== [])
        <div class="mox-section-head mox-section-head--tight">
            <div>
                <h3 class="mox-section-title">Open issues by area</h3>
                <p class="mox-section-sub">Grouped for technical, Document Head, indexability, structured data, and social metadata.</p>
            </div>
        </div>
        <div class="mox-health-groups">
            @foreach ($groups as $group)
                <section class="mox-panel">
                    <div class="mox-panel__head">
                        <h4>{{ $group['label'] }}</h4>
                        <span class="mox-muted">{{ count($group['findings']) }}</span>
                    </div>
                    @foreach ($group['findings'] as $finding)
                        <a class="mox-finding-row" href="{{ $finding['url'] }}">
                            <span class="mox-sev mox-sev--{{ $finding['severity'] }}">{{ strtoupper($finding['severity']) }}</span>
                            <span>
                                <strong>{{ $finding['title'] }}</strong>
                                <div class="mox-muted">{{ \Illuminate\Support\Str::limit((string) $finding['summary'], 160) }}</div>
                                @if (! empty($finding['recommendation']))
                                    <div class="mox-rec-inline">{{ \Illuminate\Support\Str::limit((string) $finding['recommendation'], 180) }}</div>
                                @endif
                            </span>
                        </a>
                    @endforeach
                </section>
            @endforeach
        </div>
    @else
        <section class="mox-panel">
            <div class="mox-panel__head"><h4>Open findings</h4></div>
            <div class="mox-empty mox-empty--compact">No open findings.</div>
        </section>
    @endif

    <div class="mox-grid-2">
        <section class="mox-panel">
            <div class="mox-panel__head"><h4>Recommendations</h4></div>
            @forelse ($recommendations as $recommendation)
                <div class="mox-finding-row">
                    <span class="mox-sev mox-sev--{{ $recommendation->priority }}">{{ strtoupper($recommendation->priority) }}</span>
                    <span>
                        <strong>{{ $recommendation->title }}</strong>
                        <div class="mox-muted">{{ \Illuminate\Support\Str::limit((string) $recommendation->action, 180) }}</div>
                    </span>
                </div>
            @empty
                <div class="mox-empty mox-empty--compact">No open recommendations.</div>
            @endforelse
        </section>

        <div class="mox-stack">
            <section class="mox-panel">
                <div class="mox-panel__head"><h4>Acknowledged</h4></div>
                @forelse ($acknowledged as $finding)
                    <div class="mox-finding-row">
                        <span class="mox-sev mox-sev--{{ $finding->severity }}">{{ strtoupper($finding->severity) }}</span>
                        <span>{{ $finding->title }}</span>
                    </div>
                @empty
                    <div class="mox-empty mox-empty--compact">None</div>
                @endforelse
            </section>
            <section class="mox-panel">
                <div class="mox-panel__head"><h4>Recently resolved</h4></div>
                @forelse ($resolved->take(8) as $finding)
                    <div class="mox-finding-row">
                        <span class="mox-ok">RESOLVED</span>
                        <span>{{ $finding->title }}</span>
                    </div>
                @empty
                    <div class="mox-empty mox-empty--compact">None</div>
                @endforelse
            </section>
        </div>
    </div>
</div>
