@props([
    'severity' => 'low',
    'title',
    'body' => null,
    'entity' => null,
])

{{--
    Single "what needs attention" entry — a Finding/Recommendation surfaced
    inline near the data it concerns, rather than only in a disconnected
    Intelligence tab (docs/product/META_ADS_EXPERT_WORKSPACE.md).
--}}
@php
    $severityKey = strtolower((string) $severity);

    $tone = match ($severityKey) {
        'critical', 'high' => 'critical',
        'medium' => 'warning',
        default => 'neutral',
    };
@endphp

<article {{ $attributes->class(['mox-attention-card', 'mox-attention-card--' . $severityKey]) }}>
    <x-moxdop.status-pill :label="strtoupper($severityKey)" :tone="$tone" />

    <div>
        <p class="mox-attention-card__title">{{ $title }}</p>

        @if (filled($body))
            <p class="mox-attention-card__body">{{ $body }}</p>
        @endif

        @if (filled($entity))
            <p class="mox-attention-card__entity">{{ $entity }}</p>
        @endif

        @isset($action)
            <div class="mox-attention-card__action">
                {{ $action }}
            </div>
        @endisset
    </div>
</article>
