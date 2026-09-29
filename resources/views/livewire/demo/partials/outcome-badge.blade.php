{{-- Sonuç rozeti (Faz 9): verdict = worked | not_worked | unclear --}}
<span data-outcome="{{ $verdict }}" @class([
    'inline-flex shrink-0 rounded px-1.5 py-0.5 text-xs font-medium',
    'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' => $verdict === 'worked',
    'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => $verdict === 'not_worked',
    'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' => $verdict !== 'worked' && $verdict !== 'not_worked',
])>{{ \App\Services\Outcomes\OutcomeTracker::VERDICT_LABELS[$verdict] ?? $verdict }}</span>
