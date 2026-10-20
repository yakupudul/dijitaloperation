@props(['label', 'value', 'delta' => null, 'note' => null, 'tone' => null])
{{-- Durum: one number, its label, optional change (±%) and a short note (e.g. "9/20"). Flat, no box (sade panel). --}}
<div {{ $attributes->merge(['class' => 'min-w-0 py-1']) }} data-workspace-stat>
    <p @class([
        'text-[26px] leading-8 font-semibold tabular-nums tracking-tight',
        'text-error-600 dark:text-error-400' => $tone === 'error',
        'text-warning-600 dark:text-warning-400' => $tone === 'warning',
        'text-gray-900 dark:text-white' => ! in_array($tone, ['error', 'warning'], true),
    ])>{{ $value }}</p>
    <p class="mt-1 truncate text-xs text-gray-500">
        <span>{{ $label }}</span>
        @if ($delta !== null)<span @class(['ml-1 tabular-nums', 'text-success-700 dark:text-success-400' => $delta > 0, 'text-error-700 dark:text-error-400' => $delta < 0])>{{ $delta > 0 ? '+' : ($delta < 0 ? '−' : '') }}%{{ abs($delta) }}</span>@endif
        @if (filled($note))<span class="ml-1">{{ $note }}</span>@endif
    </p>
</div>
