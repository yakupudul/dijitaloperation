@php
    $data = $data ?? [];
    $counts = $data['findings']['counts'] ?? [];
    $open = $data['findings']['open'] ?? collect();
    $groups = $data['findings']['health_groups'] ?? [];
    $acknowledged = $data['findings']['acknowledged'] ?? collect();
    $resolved = $data['findings']['resolved'] ?? collect();
    $recommendations = $data['recommendations'] ?? collect();
    $diagnosis = $data['diagnosis'] ?? [];
    $ai = $data['ai_guidance'] ?? ['available' => false];
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
                        <div class="mox-muted">
                            @if ($recommendation->source_module === \MoxDop\Website\Ai\WebsiteAiRecommendationConfig::MODULE_ID)
                                AI-assisted
                            @else
                                Deterministic
                            @endif
                        </div>
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

    @if (($ai['available'] ?? false) || ($ai['failed'] ?? null) !== null)
        <div class="mox-section-head mox-section-head--tight">
            <div>
                <h3 class="mox-section-title">AI Guidance</h3>
                <p class="mox-section-sub">
                    Advisory interpretation grounded in Findings, Evidence, and Brand context.
                    <span class="mox-badge-ai">AI-generated</span>
                </p>
            </div>
        </div>

        @if (($ai['failed'] ?? null) !== null)
            <section class="mox-panel">
                <div class="mox-panel__head"><h4>Latest AI request failed</h4></div>
                <p class="mox-muted">{{ $ai['failed']['message'] ?? 'AI request failed.' }}</p>
            </section>
        @endif

        @if ($ai['available'] ?? false)
            <section class="mox-panel">
                <div class="mox-panel__head">
                    <h4>Executive summary</h4>
                    <span class="mox-muted">
                        Generated {{ $ai['generated_human'] ?? 'recently' }}
                        · {{ (int) ($ai['finding_count'] ?? 0) }} Findings
                        · {{ (int) ($ai['evidence_count'] ?? 0) }} Evidence
                        @if (is_array($ai['brand_completeness'] ?? null))
                            · Brand context {{ $ai['brand_completeness']['completed'] ?? 0 }}/{{ $ai['brand_completeness']['total'] ?? 8 }}
                        @endif
                    </span>
                </div>
                <p>{{ $ai['executive_summary'] ?? '—' }}</p>
                @if (is_array($ai['brand_completeness'] ?? null) && ($ai['brand_completeness']['completed'] ?? 0) < ($ai['brand_completeness']['total'] ?? 8))
                    <p class="mox-muted">Complete Brand Intelligence for more business-specific AI guidance.</p>
                @endif
            </section>

            @foreach ($ai['interpretations'] ?? [] as $interpretation)
                <section class="mox-panel">
                    <div class="mox-panel__head">
                        <h4>
                            <span class="mox-sev mox-sev--{{ $interpretation['severity'] ?? 'medium' }}">{{ strtoupper((string) ($interpretation['suggested_priority'] ?? $interpretation['severity'] ?? 'medium')) }}</span>
                            {{ $interpretation['finding_title'] ?? 'Finding' }}
                        </h4>
                        <span class="mox-badge-ai">AI-generated</span>
                    </div>
                    <p><strong>AI interpretation</strong><br>{{ $interpretation['explanation'] ?? '—' }}</p>
                    <p><strong>Why this matters</strong><br>{{ $interpretation['business_relevance'] ?? '—' }}</p>
                    @if (! empty($interpretation['recommendation_draft']['action']))
                        <p><strong>Suggested action</strong><br>{{ $interpretation['recommendation_draft']['action'] }}</p>
                    @endif
                    @if (! empty($interpretation['watch_metrics']))
                        <p class="mox-muted"><strong>Watch</strong> · {{ implode(', ', $interpretation['watch_metrics']) }}</p>
                    @endif
                    @if (! empty($interpretation['evidence_ids']))
                        <p class="mox-muted">Evidence #{{ implode(', #', $interpretation['evidence_ids']) }}</p>
                    @endif
                    @if (($interpretation['can_accept'] ?? false) && ! empty($interpretation['recommendation_draft']))
                        <button
                            type="button"
                            class="mox-btn mox-btn--secondary"
                            wire:click="acceptAiRecommendationDraft({{ (int) $interpretation['finding_id'] }})"
                        >
                            Create recommendation
                        </button>
                    @elseif (! empty($interpretation['existing_recommendation']))
                        <p class="mox-muted">AI-assisted recommendation already recorded ({{ $interpretation['existing_recommendation']->status }}).</p>
                    @endif
                </section>
            @endforeach
        @endif
    @endif
</div>
