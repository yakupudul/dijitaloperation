{{-- Sonuç rozeti (Faz 9): verdict = worked | neutral | not_worked | unclear --}}
<span data-outcome="{{ $verdict }}" @class([
    'inline-flex shrink-0 rounded px-1.5 py-0.5 text-xs font-medium',
    'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' => $verdict === 'worked',
    'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-300' => $verdict === 'not_worked',
    'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' => $verdict !== 'worked' && $verdict !== 'not_worked',
])>{{ \App\Services\Outcomes\OutcomeTracker::VERDICT_LABELS[$verdict] ?? $verdict }}</span>
