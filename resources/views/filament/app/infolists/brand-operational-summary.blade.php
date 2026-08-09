@php
    /** @var array{digital_assets: int, healthy_connected_assets: int, open_findings: int, open_recommendations: int, open_tasks: int}|null $summary */
    $summary = $summary ?? null;
    /** @var \App\Support\BrandIntelligence\BrandIntelligenceSnapshot|null $intelligence */
    $intelligence = $intelligence ?? null;
@endphp

@if (is_array($summary))
    <div class="mox-workspace-summary" role="list" aria-label="Brand operational summary">
        <div class="mox-workspace-summary__item" role="listitem">
            <div class="mox-workspace-summary__label">Digital assets</div>
            <div class="mox-workspace-summary__value">{{ $summary['digital_assets'] }}</div>
            <div class="mox-workspace-summary__hint">Assets under this brand</div>
        </div>
        <div class="mox-workspace-summary__item" role="listitem">
            <div class="mox-workspace-summary__label">Healthy connections</div>
            <div class="mox-workspace-summary__value">{{ $summary['healthy_connected_assets'] }}</div>
            <div class="mox-workspace-summary__hint">Assets with an enabled connection and no recent issue</div>
        </div>
        <div class="mox-workspace-summary__item" role="listitem">
            <div class="mox-workspace-summary__label">Open findings</div>
            <div class="mox-workspace-summary__value">{{ $summary['open_findings'] }}</div>
            <div class="mox-workspace-summary__hint">Across brand digital assets</div>
        </div>
        <div class="mox-workspace-summary__item" role="listitem">
            <div class="mox-workspace-summary__label">Open recommendations</div>
            <div class="mox-workspace-summary__value">{{ $summary['open_recommendations'] }}</div>
            <div class="mox-workspace-summary__hint">Across brand digital assets</div>
        </div>
        <div class="mox-workspace-summary__item" role="listitem">
            <div class="mox-workspace-summary__label">Open tasks</div>
            <div class="mox-workspace-summary__value">{{ $summary['open_tasks'] }}</div>
            <div class="mox-workspace-summary__hint">Open, in progress, or blocked</div>
        </div>
    </div>
@endif

@if ($intelligence instanceof \App\Support\BrandIntelligence\BrandIntelligenceSnapshot)
    <section class="mox-panel" style="margin-top: 1rem;" aria-label="Brand intelligence summary">
        <div class="mox-panel__head">
            <h4>Business context</h4>
            <span class="mox-muted">{{ $intelligence->completeness['label'] }}</span>
        </div>
        @if (! $intelligence->hasContext)
            <div class="mox-empty mox-empty--compact">
                No structured Brand intelligence yet. Open the Intelligence tab to add factual business context.
            </div>
        @else
            <div class="mox-grid-3">
                <div>
                    <div class="mox-muted">Priority offerings</div>
                    <div>
                        @if ($intelligence->priorityOfferings === [])
                            —
                        @else
                            {{ implode(' · ', array_slice($intelligence->priorityOfferings, 0, 3)) }}
                        @endif
                    </div>
                </div>
                <div>
                    <div class="mox-muted">Target markets</div>
                    <div>
                        @if ($intelligence->targetMarkets === [])
                            —
                        @else
                            {{ collect($intelligence->targetMarkets)->pluck('name')->take(3)->implode(' · ') }}
                        @endif
                    </div>
                </div>
                <div>
                    <div class="mox-muted">Primary conversion goals</div>
                    <div>
                        @if ($intelligence->conversionGoals === [])
                            —
                        @else
                            {{ collect($intelligence->conversionGoals)->map(fn ($g) => $g['label'] ?: $g['type_label'])->take(3)->implode(' · ') }}
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </section>
@endif
