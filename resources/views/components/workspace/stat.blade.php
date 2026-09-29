@props(['label', 'value', 'delta' => null, 'note' => null, 'tone' => null])
{{-- Durum: one number, its label, optional change (±%) and a short note (e.g. "9/20"). --}}
<div {{ $attributes->merge(['class' => 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800']) }} data-workspace-stat>
    <p class="truncate text-xs font-medium uppercase tracking-wide text-gray-400">{{ $label }}</p>
    <p @class([
        'mt-1 text-2xl font-bold',
        'text-error-600 dark:text-error-400' => $tone === 'error',
        'text-warning-600 dark:text-warning-400' => $tone === 'warning',
        'text-gray-800 dark:text-white/90' => ! in_array($tone, ['error', 'warning'], true),
    ])>{{ $value }}</p>
    @if ($delta !== null || filled($note))
        <p class="mt-0.5 truncate text-xs text-gray-500">
            @if ($delta !== null)<span @class(['font-medium', 'text-success-600' => $delta > 0, 'text-error-600' => $delta < 0])>{{ $delta > 0 ? '+' : '' }}%{{ $delta }}</span>@endif
            @if (filled($note)){{ $note }}@endif
        </p>
    @endif
</div>
