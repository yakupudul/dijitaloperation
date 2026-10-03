@php
    /** @var array<string, array{label: string, tone: string, title: string}> $channels  PortfolioSignalsReader channel row */
    $dotTone = ['ok' => 'bg-emerald-500', 'warn' => 'bg-amber-500', 'bad' => 'bg-rose-500', 'muted' => 'bg-gray-300 dark:bg-gray-600', 'none' => 'border border-gray-300 dark:border-gray-600'];
@endphp
<span class="inline-flex flex-wrap items-center gap-x-2.5 gap-y-1 text-[11px] text-gray-500" data-channel-dots>
    @foreach ($channels as $capability => $channel)
        <span class="inline-flex items-center gap-1" title="{{ $channel['title'] }}" data-channel="{{ $capability }}" data-tone="{{ $channel['tone'] }}">
            <span class="h-2 w-2 shrink-0 rounded-full {{ $dotTone[$channel['tone']] ?? $dotTone['none'] }}" aria-hidden="true"></span>
            <span @class(['text-gray-400 dark:text-gray-600' => $channel['tone'] === 'none'])>{{ $channel['label'] }}</span>
            <span class="sr-only">{{ $channel['title'] }}</span>
        </span>
    @endforeach
</span>
