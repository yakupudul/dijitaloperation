@php
    /** @var array{digital_assets: int, healthy_connected_assets: int, open_findings: int, open_recommendations: int, open_tasks: int}|null $summary */
    $summary = $summary ?? null;
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
