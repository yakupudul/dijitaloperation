@php
    /** @var array<string, array{label: string, tone: string, title: string}> $channels  PortfolioSignalsReader channel row */
    // Compact channel pills (panel redesign 2026-10-19): only the channels the brand has, one short label each, the data state
    // as the pill colour and the full status in the title. Channels without an asset are left out (their title stays readable
    // for screen readers in the summary).
    $pillTone = [
        'ok' => 'bg-success-50 text-success-700 ring-success-200 dark:bg-success-500/10 dark:text-success-300 dark:ring-success-500/20',
        'warn' => 'bg-warning-50 text-warning-800 ring-warning-200 dark:bg-warning-500/10 dark:text-warning-300 dark:ring-warning-500/20',
        'bad' => 'bg-error-50 text-error-700 ring-error-200 dark:bg-error-500/10 dark:text-error-300 dark:ring-error-500/20',
        'muted' => 'bg-gray-50 text-gray-500 ring-gray-200 dark:bg-white/5 dark:text-gray-400 dark:ring-gray-700',
    ];
    $present = collect($channels)->reject(fn (array $channel): bool => $channel['tone'] === 'none');
    $missing = collect($channels)->filter(fn (array $channel): bool => $channel['tone'] === 'none');
@endphp
<span class="inline-flex min-w-0 flex-wrap items-center gap-1" data-channel-dots>
    @foreach ($channels as $capability => $channel)
        @continue($channel['tone'] === 'none')
        <span class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[11px] font-medium leading-4 ring-1 ring-inset {{ $pillTone[$channel['tone']] ?? $pillTone['muted'] }}" title="{{ $channel['title'] }}" data-channel="{{ $capability }}" data-tone="{{ $channel['tone'] }}">
            {{ $channel['label'] }}<span class="sr-only">: {{ $channel['title'] }}</span>
        </span>
    @endforeach
    @if ($present->isEmpty())
        <span class="text-xs text-gray-400" title="{{ $missing->pluck('title')->implode(' · ') }}">Kanal yok</span>
    @endif
</span>
