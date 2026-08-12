@props([
    'title',
    'phase' => null,
    'rows' => [],
    'elapsed' => null,
    'status' => 'queued',
])

{{--
    Async operation progress surface (docs/product/OPERATOR_ASYNC_EXECUTION.md
    states: queued/running/completed/partial/failed/cancelled). "rows" is a
    list of ['label' => string, 'done' => int|float|null, 'total' => int|float|null];
    a row without a numeric done/total still renders its label honestly
    (Missing != zero) instead of a fabricated 0% bar.
--}}
@php
    $statusKey = strtolower((string) $status);

    $statusTone = match ($statusKey) {
        'completed' => 'ok',
        'partial' => 'attention',
        'failed' => 'critical',
        'cancelled' => 'neutral',
        'running' => 'traffic',
        default => 'neutral', // queued
    };
@endphp

<div {{ $attributes->class(['mox-operation-progress']) }}>
    <div class="mox-operation-progress__head">
        <div>
            <p class="mox-operation-progress__title">{{ $title }}</p>

            @if (filled($phase))
                <p class="mox-operation-progress__phase">{{ $phase }}</p>
            @endif
        </div>

        <x-moxdop.status-pill :label="ucfirst($statusKey)" :tone="$statusTone" />
    </div>

    @if (! empty($rows))
        <div class="mox-operation-progress__rows">
            @foreach ($rows as $row)
                @php
                    $done = $row['done'] ?? null;
                    $total = $row['total'] ?? null;
                    $hasProgress = is_numeric($done) && is_numeric($total) && (float) $total > 0;
                    $percent = $hasProgress ? min(100, max(0, ((float) $done / (float) $total) * 100)) : null;
                @endphp
                <div class="mox-operation-progress__row">
                    <div class="mox-operation-progress__row-head">
                        <span>{{ $row['label'] ?? '—' }}</span>
                        <span>
                            @if ($hasProgress)
                                {{ number_format((float) $done) }} / {{ number_format((float) $total) }}
                            @else
                                —
                            @endif
                        </span>
                    </div>
                    <div class="mox-operation-progress__bar">
                        <span
                            class="mox-operation-progress__bar-fill"
                            style="--mox-operation-progress-value: {{ $percent !== null ? round($percent, 1) . '%' : '0%' }}"
                        ></span>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if (filled($elapsed))
        <div class="mox-operation-progress__foot">
            <span>Elapsed</span>
            <span>{{ $elapsed }}</span>
        </div>
    @endif
</div>
