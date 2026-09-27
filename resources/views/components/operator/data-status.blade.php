@props([
    /** list<\App\Services\DataStatus\DataStatus> from DataStatusReader */
    'statuses' => [],
    /** Show the per-source action (Verileri yenile / Yeniden bağla / Kaynağı bağla). */
    'actions' => true,
])

@php
    $tones = [
        'ok' => ['dot' => 'bg-emerald-500', 'text' => 'text-emerald-700 dark:text-emerald-300'],
        'warn' => ['dot' => 'bg-amber-500', 'text' => 'text-amber-700 dark:text-amber-300'],
        'bad' => ['dot' => 'bg-rose-500', 'text' => 'text-rose-700 dark:text-rose-300'],
        'muted' => ['dot' => 'bg-gray-300 dark:bg-gray-600', 'text' => 'text-gray-600 dark:text-gray-300'],
    ];
@endphp

@if (count($statuses) > 0)
    <section {{ $attributes->class('flex flex-col gap-2 rounded-xl bg-white px-4 py-2.5 text-sm ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800 lg:flex-row lg:items-center lg:gap-4') }} data-data-status aria-label="{{ __('data_status.title', [], 'tr') }}">
        <p class="shrink-0 text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ __('data_status.title', [], 'tr') }}</p>
        <div class="flex min-w-0 flex-1 flex-col gap-2 lg:flex-row lg:flex-wrap lg:items-center lg:gap-x-5">
            @foreach ($statuses as $status)
                @php $tone = $tones[$status->tone()] ?? $tones['muted']; @endphp
                <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5" data-data-status-source="{{ $status->capability }}" data-state="{{ $status->state }}">
                    <span class="h-2 w-2 shrink-0 rounded-full {{ $tone['dot'] }}"></span>
                    <span class="font-medium text-gray-800 dark:text-gray-100">{{ $status->sourceLabel() }}</span>
                    @if ($status->resourceName)
                        <span class="max-w-[14rem] truncate text-xs text-gray-400" title="{{ $status->resourceName }}">{{ $status->resourceName }}</span>
                    @endif
                    <span class="font-semibold {{ $tone['text'] }}">{{ $status->label() }}</span>
                    @if ($status->detail() !== '')
                        <span class="text-xs text-gray-500 dark:text-gray-400">· {{ $status->detail() }}</span>
                    @endif
                    @if ($status->collecting)
                        <span class="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-700 dark:bg-blue-500/10 dark:text-blue-300">
                            <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-blue-500"></span>
                            {{ __('data_status.collecting', [], 'tr') }}@if ($status->progressPct !== null) · {{ __('data_status.progress', ['pct' => $status->progressPct], 'tr') }}@endif
                        </span>
                    @endif
                    @if ($actions)
                        @if ($status->action === \App\Services\DataStatus\DataStatus::ACTION_REFRESH)
                            <button type="button" wire:click="refreshSource('{{ $status->capability }}')" wire:loading.attr="disabled" class="rounded-md px-2 py-0.5 text-xs font-semibold text-brand-600 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 disabled:opacity-60 dark:text-brand-300 dark:ring-brand-500/30">{{ $status->actionLabel() }}</button>
                        @elseif ($status->actionUrl)
                            <a href="{{ $status->actionUrl }}" @if ($status->action === \App\Services\DataStatus\DataStatus::ACTION_BIND) wire:navigate @endif @class([
                                'rounded-md px-2 py-0.5 text-xs font-semibold',
                                'bg-rose-600 text-white hover:bg-rose-700' => $status->action === \App\Services\DataStatus\DataStatus::ACTION_RECONNECT,
                                'text-brand-600 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:text-brand-300 dark:ring-brand-500/30' => $status->action !== \App\Services\DataStatus\DataStatus::ACTION_RECONNECT,
                            ])>{{ $status->actionLabel() }}</a>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>
        {{ $slot }}
    </section>
@endif
