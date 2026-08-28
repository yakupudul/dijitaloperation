@if($activeCount > 0)
    <div wire:poll.3s class="hidden sm:flex min-w-0 items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-800 dark:border-blue-500/20 dark:bg-blue-500/10 dark:text-blue-300" title="{{ implode(' · ', $providers) }}">
        <span class="h-2 w-2 shrink-0 animate-pulse rounded-full bg-blue-500"></span>
        <span class="max-w-44 truncate font-semibold">{{ app()->getLocale() === 'tr' ? $activeCount.' veri güncellemesi çalışıyor' : $activeCount.' data update'.($activeCount > 1 ? 's' : '').' running' }}</span>
        @if($progress !== null)<span class="shrink-0 font-bold tabular-nums">%{{ $progress }}</span>@endif
    </div>
@endif
