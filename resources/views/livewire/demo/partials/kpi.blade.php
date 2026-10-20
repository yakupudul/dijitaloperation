@php
    // Flat stat (sade panel 2026-10-20): x-ta.metric-card, change as coloured text, no box, no icon tile.
    $tone = match ($kpi['tone'] ?? 'neutral') {
        'good' => 'positive',
        'bad' => 'negative',
        'warn' => 'warning',
        default => 'neutral',
    };
    $delta = $kpi['delta'] ?? null;
    $value = $kpi['value'] ?? '—';
    $format = $kpi['format'] ?? 'text';
    $display = match ($format) {
        'try' => is_numeric($value) ? '₺'.number_format((float) $value, (fmod((float) $value, 1) == 0.0 ? 0 : 2)) : $value,
        'int' => is_numeric($value) ? number_format((float) $value) : $value,
        'float' => is_numeric($value) ? number_format((float) $value, 2) : $value,
        'pct' => is_numeric($value) ? number_format((float) $value, 2).'%' : $value,
        default => $value,
    };
    $deltaText = $delta !== null ? (($delta > 0 ? '+' : ($delta < 0 ? '−' : '')).number_format(abs((float) $delta), 1).'%') : null;
@endphp

<x-ta.metric-card :label="$kpi['label'] ?? ''" :value="$display" :delta="$deltaText" :tone="$tone" :spark="$kpi['spark'] ?? null" />
