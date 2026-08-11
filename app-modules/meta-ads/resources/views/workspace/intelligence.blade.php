@php
    /** @var array<string, mixed> $data */
    $open = $data['findings']['open'] ?? collect();
    $acknowledged = $data['findings']['acknowledged'] ?? collect();
    $resolved = $data['findings']['resolved'] ?? collect();
    $findingGroups = $data['finding_groups'] ?? [];
    $recommendations = $data['recommendations'] ?? collect();
    $ai = $data['ai_guidance'] ?? [];

    $bySeverity = $open->groupBy('severity');
    $severityOrder = ['critical', 'high', 'medium', 'low'];
@endphp

<div class="mox-website-workspace mox-meta-workspace">
    <div class="mox-section-head">
        <div>
            <h3 class="mox-section-title">Intelligence</h3>
            <p class="mox-section-sub">Deterministic Findings and human-gated Recommendations. No external Meta writes.</p>
        </div>
    </div>

    <p class="mox-muted">
        Reminder: Meta results are platform-attributed engagement/conversion signals, not verified business outcomes.
        Treat them as directional — reconcile against your business systems before acting.
    </p>

    @if ($findingGroups !== [])
        <section class="mox-panel">
            <div class="mox-panel__head"><h4>Measurement &amp; result-interpretation summary</h4></div>
            @foreach ($findingGroups as $group)
                <div class="mox-finding-row">
                    <span class="mox-sev mox-sev--{{ $group['severity'] }}">{{ strtoupper($group['severity']) }}</span>
                    <div>
                        <div>
                            {{ $group['title'] }}
                            @if ($group['count'] > 1)
                                <span class="mox-muted">— {{ $group['count'] }} occurrences</span>
                            @endif
                        </div>
                        <div class="mox-muted">{{ \Illuminate\Support\Str::limit((string) $group['sample_summary'], 160) }}</div>
                    </div>
                </div>
            @endforeach
        </section>
    @endif

    <div class="mox-grid-2">
        <section class="mox-panel">
            <div class="mox-panel__head"><h4>Findings by severity ({{ $open->count() }} open)</h4></div>
            @forelse ($severityOrder as $severity)
                @php $group = $bySeverity->get($severity, collect()); @endphp
                @if ($group->isNotEmpty())
                    <p class="mox-muted" style="margin-top:0.5rem;">{{ strtoupper($severity) }} ({{ $group->count() }})</p>
                    @foreach ($group as $finding)
                        <div class="mox-finding-row">
                            <span class="mox-sev mox-sev--{{ $finding->severity }}">{{ strtoupper($finding->severity) }}</span>
                            <div>
                                <div>{{ $finding->title }}</div>
                                <div class="mox-muted">{{ \Illuminate\Support\Str::limit((string) $finding->summary, 160) }}</div>
                            </div>
                        </div>
                    @endforeach
                @endif
            @empty
            @endforelse
            @if ($open->isEmpty())
                <div class="mox-empty mox-empty--compact">No open Findings.</div>
            @endif
        </section>

        <section class="mox-panel">
            <div class="mox-panel__head"><h4>Recommendations</h4></div>
            @forelse ($recommendations as $rec)
                <div class="mox-finding-row">
                    <span class="mox-sev mox-sev--{{ $rec->priority }}">{{ strtoupper($rec->priority) }}</span>
                    <div>
                        <div>{{ $rec->title }}</div>
                        <div class="mox-muted">{{ \Illuminate\Support\Str::limit((string) $rec->action, 160) }}</div>
                    </div>
                </div>
            @empty
                <div class="mox-empty mox-empty--compact">No open recommendations.</div>
            @endforelse
        </section>
    </div>

    @if (($ai['available'] ?? false) || ($ai['failed'] ?? null) !== null)
        <div class="mox-section-head mox-section-head--tight">
            <div>
                <h3 class="mox-section-title">AI Guidance</h3>
                <p class="mox-section-sub">
                    {{ $ai['agent_name'] ?? 'Meta Ads Analyst' }}
                    @if (! empty($ai['agent_version'])) · v{{ $ai['agent_version'] }} @endif
                    · {{ $ai['ai_route_name'] ?? 'Meta Ads AI Guidance' }}
                    @if (! empty($ai['provider'])) · {{ \App\Support\Ai\AiProviderCatalog::label($ai['provider']) }} @endif
                    @if (! empty($ai['model'])) · {{ \App\Support\Ai\AiProviderCatalog::humanModelLabel($ai['model']) }} @endif
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
                    <span class="mox-muted">Generated {{ $ai['generated_human'] ?? 'recently' }}</span>
                </div>
                <p>{{ $ai['executive_summary'] ?? '—' }}</p>
                @if (! empty($ai['skill_versions']) && is_array($ai['skill_versions']))
                    <p class="mox-muted">Skills: {{ implode(', ', $ai['skill_versions']) }}</p>
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

                    <p><strong>WHAT</strong><br>{{ $interpretation['explanation'] ?? '—' }}</p>
                    <p><strong>WHY</strong><br>{{ $interpretation['business_relevance'] ?? '—' }}</p>
                    @if (! empty($interpretation['recommendation_draft']['action']))
                        <p><strong>CHECK</strong><br>{{ $interpretation['recommendation_draft']['action'] }}</p>
                    @endif
                    @if (! empty($interpretation['evidence_ids']))
                        <p class="mox-muted"><strong>EVIDENCE</strong><br>Evidence #{{ implode(', #', $interpretation['evidence_ids']) }}</p>
                    @endif
                    <p><strong>CAVEATS</strong><br>
                        Uncertainty: {{ ucfirst((string) ($interpretation['uncertainty'] ?? 'medium')) }}. Meta results are platform-attributed —
                        confirm against business systems before acting.
                    </p>
                    @if (! empty($interpretation['watch_metrics']))
                        <p><strong>WATCH</strong><br>{{ implode(', ', $interpretation['watch_metrics']) }}</p>
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
