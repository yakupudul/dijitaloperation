@props([
    /** list<\App\Services\DataStatus\DataStatus> from DataStatusReader */
    'statuses' => [],
    /** Show the per-source action (Verileri yenile / Yeniden bağla / Kaynağı bağla). */
    'actions' => true,
    /** One quiet line (asset page head) instead of the white card. */
    'compact' => false,
])

@php
    $tones = [
        'ok' => ['dot' => 'bg-success-500', 'text' => 'text-success-700 dark:text-success-300'],
        'warn' => ['dot' => 'bg-warning-500', 'text' => 'text-warning-700 dark:text-warning-300'],
        'bad' => ['dot' => 'bg-error-500', 'text' => 'text-error-700 dark:text-error-300'],
        'muted' => ['dot' => 'bg-gray-300 dark:bg-gray-600', 'text' => 'text-gray-600 dark:text-gray-300'],
    ];
@endphp

@if (count($statuses) > 0)
    <section {{ $attributes->class([
        'flex min-w-0 flex-wrap items-center gap-x-4 gap-y-1 text-xs' => $compact,
        'flex flex-col gap-2 border-b border-gray-200 py-2.5 text-sm dark:border-gray-800 lg:flex-row lg:items-center lg:gap-4' => ! $compact,
    ]) }} data-data-status aria-label="{{ __('data_status.title', [], 'tr') }}">
        <p @class(['shrink-0 text-gray-500', 'text-xs' => ! $compact])>{{ __('data_status.title', [], 'tr') }}</p>
        <div @class([
            'contents' => $compact,
            'flex min-w-0 flex-1 flex-col gap-2 lg:flex-row lg:flex-wrap lg:items-center lg:gap-x-5' => ! $compact,
        ])>
            @foreach ($statuses as $status)
                @php $tone = $tones[$status->tone()] ?? $tones['muted']; @endphp
                <div @class(['flex min-w-0 flex-wrap items-center gap-y-0.5', 'gap-x-1.5' => $compact, 'gap-x-2' => ! $compact]) data-data-status-source="{{ $status->capability }}" data-state="{{ $status->state }}">
                    <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $tone['dot'] }}"></span>
                    <span @class(['font-medium', 'text-gray-700 dark:text-gray-200' => $compact, 'text-gray-800 dark:text-gray-100' => ! $compact]) @if ($compact) title="{{ collect([$status->resourceName, $status->detail()])->filter()->implode(', ') }}" @endif>{{ $status->sourceLabel() }}</span>
                    @if ($status->resourceName && ! $compact)
                        <span class="max-w-[14rem] truncate text-xs text-gray-500" title="{{ $status->resourceName }}">{{ $status->resourceName }}</span>
                    @endif
                    <span class="font-medium {{ $tone['text'] }}">{{ $status->label() }}</span>
                    @if ($status->detail() !== '')
                        <span @class(['text-xs tabular-nums text-gray-500 dark:text-gray-400', 'hidden sm:inline' => $compact])>{{ $status->detail() }}</span>
                    @endif
                    @if ($status->collecting)
                        <span class="inline-flex items-center gap-1 text-xs text-gray-600 dark:text-gray-300">
                            <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-gray-500"></span>
                            {{ __('data_status.collecting', [], 'tr') }}@if ($status->progressPct !== null), {{ __('data_status.progress', ['pct' => $status->progressPct], 'tr') }}@endif
                        </span>
                    @endif
                    @if ($actions)
                        @if ($status->action === \App\Services\DataStatus\DataStatus::ACTION_REFRESH)
                            <button type="button" wire:click="refreshSource('{{ $status->capability }}')" wire:loading.attr="disabled" @class([
                                'text-xs disabled:opacity-60' => true,
                                'link-neutral' => $compact,
                                'rounded-md px-1.5 py-0.5 font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.04]' => ! $compact,
                            ])>{{ $status->actionLabel() }}</button>
                        @elseif ($status->actionUrl)
                            <a href="{{ $status->actionUrl }}" @if ($status->action === \App\Services\DataStatus\DataStatus::ACTION_BIND) wire:navigate @endif @class([
                                'text-xs font-medium',
                                'underline decoration-current/30 underline-offset-2 hover:decoration-current' => $compact,
                                'rounded-md px-1.5 py-0.5 ring-1 ring-inset' => ! $compact,
                                'text-error-700 dark:text-error-300' => $status->action === \App\Services\DataStatus\DataStatus::ACTION_RECONNECT,
                                'ring-error-300 hover:bg-error-50 dark:ring-error-500/40' => ! $compact && $status->action === \App\Services\DataStatus\DataStatus::ACTION_RECONNECT,
                                'text-gray-900 dark:text-white' => $compact && $status->action !== \App\Services\DataStatus\DataStatus::ACTION_RECONNECT,
                                'text-gray-700 ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700' => ! $compact && $status->action !== \App\Services\DataStatus\DataStatus::ACTION_RECONNECT,
                            ])>{{ $status->actionLabel() }}</a>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>
        {{ $slot }}
    </section>
@endif
