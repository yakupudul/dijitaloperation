@php
    /** @var array<string, mixed> $data */
    $data = $data ?? [];
    $last = $data['last_run'] ?? null;
    $facts = $data['fact_candidates'] ?? collect();
    $inferences = $data['inference_candidates'] ?? collect();
    $competitors = $data['competitor_candidates'] ?? collect();
    $summary = $data['summary'] ?? null;
    $editingCandidateId = $editingCandidateId ?? null;
    $editingValue = $editingValue ?? '';
@endphp

<div class="mox-website-workspace">
    <div class="mox-section-head">
        <div>
            <h3 class="mox-section-title">Public discovery</h3>
            <p class="mox-section-sub">Outside-in signals from the public Website. Candidates require human Accept before Brand Context changes.</p>
        </div>
        @if (! empty($data['retrieved_human']))
            <div class="mox-meta-pill">Retrieved {{ $data['retrieved_human'] }}</div>
        @endif
    </div>

    <div class="mox-kpi-grid mox-kpi-grid--compact">
        <div class="mox-kpi-card">
            <div class="mox-kpi-card__label">Last status</div>
            <div class="mox-kpi-card__value">{{ $data['status_label'] ?? 'Not run' }}</div>
        </div>
        <div class="mox-kpi-card">
            <div class="mox-kpi-card__label">Pages inspected</div>
            <div class="mox-kpi-card__value">{{ (int) ($data['pages_inspected'] ?? 0) }}</div>
        </div>
        <div class="mox-kpi-card">
            <div class="mox-kpi-card__label">Fact candidates</div>
            <div class="mox-kpi-card__value">{{ (int) ($data['fact_count'] ?? 0) }}</div>
        </div>
        <div class="mox-kpi-card">
            <div class="mox-kpi-card__label">Inferences</div>
            <div class="mox-kpi-card__value">{{ (int) ($data['inference_count'] ?? 0) }}</div>
        </div>
    </div>

    @if (is_array($summary))
        <section class="mox-panel">
            <div class="mox-panel__head"><h4>Last public discovery</h4></div>
            <p class="mox-muted">
                Seed: {{ $summary['seed_url'] ?? '—' }}
                · Competitor provider: {{ $summary['competitor_provider'] ?? 'not configured' }}
                @if (! empty($summary['competitor_message']))
                    · {{ $summary['competitor_message'] }}
                @endif
            </p>
            @if (! empty($data['ai_label']))
                <p class="mox-muted">AI: {{ $data['ai_label'] }}</p>
            @endif
        </section>
    @else
        <section class="mox-panel">
            <div class="mox-panel__head"><h4>No public discovery yet</h4></div>
            <p class="mox-muted">Use <strong>Discover public context</strong> to inspect publicly available Website information. First-party connections are not required.</p>
        </section>
    @endif

    <div class="mox-section-head mox-section-head--tight">
        <div>
            <h3 class="mox-section-title">Discovered facts</h3>
            <p class="mox-section-sub">Deterministic signals from public pages. Labeled <span class="mox-badge">FACT</span></p>
        </div>
    </div>

    @forelse ($facts as $candidate)
        @include('website::workspace.partials.discovery-candidate', [
            'candidate' => $candidate,
            'editingCandidateId' => $editingCandidateId,
            'editingValue' => $editingValue,
        ])
    @empty
        <section class="mox-panel">
            <div class="mox-empty mox-empty--compact">No fact candidates yet.</div>
        </section>
    @endforelse

    <div class="mox-section-head mox-section-head--tight">
        <div>
            <h3 class="mox-section-title">AI-derived context candidates</h3>
            <p class="mox-section-sub">Interpretations only. Labeled <span class="mox-badge-ai">INFERENCE</span></p>
        </div>
    </div>

    @forelse ($inferences as $candidate)
        @include('website::workspace.partials.discovery-candidate', [
            'candidate' => $candidate,
            'editingCandidateId' => $editingCandidateId,
            'editingValue' => $editingValue,
        ])
    @empty
        <section class="mox-panel">
            <div class="mox-empty mox-empty--compact">No AI inference candidates.</div>
        </section>
    @endforelse

    <div class="mox-section-head mox-section-head--tight">
        <div>
            <h3 class="mox-section-title">Competitor candidates</h3>
            <p class="mox-section-sub">Provider-backed domain overlap only. Never auto-accepted. No competitor Website crawl.</p>
        </div>
    </div>

    @forelse ($competitors as $candidate)
        @include('website::workspace.partials.discovery-candidate', [
            'candidate' => $candidate,
            'editingCandidateId' => $editingCandidateId,
            'editingValue' => $editingValue,
        ])
    @empty
        <section class="mox-panel">
            <div class="mox-empty mox-empty--compact">{{ $data['competitor_empty_message'] ?? 'No competitor candidates.' }}</div>
        </section>
    @endforelse
</div>
