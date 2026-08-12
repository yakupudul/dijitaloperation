@php
    $tone = $kpi['tone'] ?? 'neutral';
    $family = $kpi['family'] ?? 'delivery';
    $iconBg = match ($family) {
        'spend' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
        'result' => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-400',
        'efficiency' => 'bg-purple-50 text-purple-600 dark:bg-purple-500/15 dark:text-purple-400',
        'delivery' => 'bg-blue-light-50 text-blue-light-600 dark:bg-blue-light-500/15 dark:text-blue-light-400',
        default => 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300',
    };
    $delta = $kpi['delta'] ?? null;
    $deltaClass = match ($tone) {
        'good' => 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-400',
        'bad' => 'bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-400',
        'warn' => 'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400',
        default => 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300',
    };
    $value = $kpi['value'] ?? '—';
    $format = $kpi['format'] ?? 'text';
    $display = match ($format) {
        'try' => is_numeric($value) ? '₺'.number_format((float) $value, (fmod((float) $value, 1) == 0.0 ? 0 : 2)) : $value,
        'int' => is_numeric($value) ? number_format((float) $value) : $value,
        'float' => is_numeric($value) ? number_format((float) $value, 2) : $value,
        'pct' => is_numeric($value) ? number_format((float) $value, 2).'%' : $value,
        default => $value,
    };
@endphp

<x-ta.card>
    <div class="flex items-center justify-between">
        <span class="text-sm text-gray-500 dark:text-gray-400">{{ $kpi['label'] ?? '' }}</span>
        <span class="flex h-8 w-8 items-center justify-center rounded-lg {{ $iconBg }}">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/></svg>
        </span>
    </div>
    <h4 class="mt-3 text-title-sm font-bold text-gray-800 dark:text-white/90">{{ $display }}</h4>
    @if ($delta !== null)
        <span class="mt-2 inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $deltaClass }}">
            {{ $delta > 0 ? '+' : '' }}{{ number_format((float) $delta, 1) }}%
        </span>
    @endif
</x-ta.card>
