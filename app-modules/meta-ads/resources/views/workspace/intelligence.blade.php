@php
    $open = $data['findings']['open'] ?? collect();
    $acknowledged = $data['findings']['acknowledged'] ?? collect();
    $resolved = $data['findings']['resolved'] ?? collect();
    $recommendations = $data['recommendations'] ?? collect();
    $ai = $data['ai_guidance'] ?? [];
@endphp

<div class="mox-website-workspace">
    <div class="mox-section-head">
        <div>
            <h3 class="mox-section-title">Intelligence</h3>
            <p class="mox-section-sub">Deterministic Findings and human-gated Recommendations. No external Meta writes.</p>
        </div>
    </div>

    <div class="mox-grid-2">
        <section class="mox-panel">
            <div class="mox-panel__head"><h4>Open Findings ({{ $open->count() }})</h4></div>
            @forelse ($open as $finding)
                <div class="mox-finding-row">
                    <span class="mox-sev mox-sev--{{ $finding->severity }}">{{ strtoupper($finding->severity) }}</span>
                    <div>
                        <div>{{ $finding->title }}</div>
                        <div class="mox-muted">{{ \Illuminate\Support\Str::limit((string) $finding->summary, 160) }}</div>
                    </div>
                </div>
            @empty
                <div class="mox-empty mox-empty--compact">No open Findings.</div>
            @endforelse
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
                    <p><strong>Observation</strong><br>{{ $interpretation['explanation'] ?? '—' }}</p>
                    <p><strong>Why it matters</strong><br>{{ $interpretation['business_relevance'] ?? '—' }}</p>
                    @if (! empty($interpretation['recommendation_draft']['action']))
                        <p><strong>Recommended action</strong><br>{{ $interpretation['recommendation_draft']['action'] }}</p>
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
