{{-- Sonuç (Faz 9; sade panel 2026-10-20: dot + text, no filled chip): verdict = worked | neutral | not_worked | unclear --}}
<span data-outcome="{{ $verdict }}" @class([
    'inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap text-xs',
    'text-success-700 dark:text-success-400' => $verdict === 'worked',
    'text-error-700 dark:text-error-400' => $verdict === 'not_worked',
    'text-gray-500 dark:text-gray-400' => $verdict !== 'worked' && $verdict !== 'not_worked',
])><span aria-hidden="true" @class([
    'h-1.5 w-1.5 rounded-full',
    'bg-success-500' => $verdict === 'worked',
    'bg-error-500' => $verdict === 'not_worked',
    'bg-gray-400' => $verdict !== 'worked' && $verdict !== 'not_worked',
])></span>{{ \App\Services\Outcomes\OutcomeTracker::VERDICT_LABELS[$verdict] ?? $verdict }}</span>
