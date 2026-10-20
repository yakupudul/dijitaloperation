@php
    /** @var array<string, array{label: string, tone: string, title: string}> $channels  PortfolioSignalsReader channel row */
    // Channels (sade panel 2026-10-20): only the channels the brand has, one short label each with the data state as a small
    // dot and the full status in the title. Channels without an asset are left out (their title stays readable for screen
    // readers in the summary).
    $dotTone = [
        'ok' => 'bg-success-500',
        'warn' => 'bg-warning-500',
        'bad' => 'bg-error-500',
        'muted' => 'bg-gray-300 dark:bg-gray-600',
    ];
    $present = collect($channels)->reject(fn (array $channel): bool => $channel['tone'] === 'none');
    $missing = collect($channels)->filter(fn (array $channel): bool => $channel['tone'] === 'none');
@endphp
<span class="inline-flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1" data-channel-dots>
    @foreach ($channels as $capability => $channel)
        @continue($channel['tone'] === 'none')
        <span @class(['inline-flex items-center gap-1.5 text-xs leading-4', 'text-error-700 dark:text-error-400' => $channel['tone'] === 'bad', 'text-gray-600 dark:text-gray-300' => $channel['tone'] !== 'bad']) title="{{ $channel['title'] }}" data-channel="{{ $capability }}" data-tone="{{ $channel['tone'] }}">
            <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dotTone[$channel['tone']] ?? $dotTone['muted'] }}" aria-hidden="true"></span>{{ $channel['label'] }}<span class="sr-only">: {{ $channel['title'] }}</span>
        </span>
    @endforeach
    @if ($present->isEmpty())
        <span class="text-xs text-gray-500" title="{{ $missing->pluck('title')->implode(', ') }}">Kanal yok</span>
    @endif
</span>
