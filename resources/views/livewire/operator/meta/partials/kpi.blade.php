@php
    /** @var array<string, mixed> $kpi */
    $value = $kpi['value'] ?? null;
    $type = $kpi['type'] ?? 'count';

    $formatted = '—';
    if (is_numeric($value)) {
        $formatted = match ($type) {
            'currency' => '$'.number_format((float) $value, 2),
            'percentage_point' => number_format((float) $value, 2).'%',
            'decimal' => number_format((float) $value, 2),
            default => number_format((float) $value),
        };
    }

    $delta = $kpi['delta_percent'] ?? null;
    $deltaText = null;
    if (is_numeric($delta)) {
        $deltaText = ((float) $delta >= 0 ? '+' : '−').number_format(abs((float) $delta), 1).'%';
    }

    $tone = match ($kpi['delta_sentiment'] ?? null) {
        'positive' => 'positive',
        'negative' => 'negative',
        default => 'neutral',
    };

    $costPerResult = isset($kpi['cost_per_result']) && is_numeric($kpi['cost_per_result'])
        ? '$'.number_format((float) $kpi['cost_per_result'], 2).' / result'
        : null;
@endphp

<x-ta.metric-card :label="$kpi['label'] ?? ''" :value="$formatted" :delta="$deltaText ?? $costPerResult" :tone="$tone" />
