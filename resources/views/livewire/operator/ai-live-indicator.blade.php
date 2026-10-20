<div @if ($running->isNotEmpty()) wire:poll.5s="refreshNow" @else wire:poll.30s="refreshNow" @endif class="relative" data-ai-live-indicator @if ($card) data-ai-live-card @endif>
    @if ($visible || $card)
        <details class="group" wire:key="ai-live-details">
            @if ($card)
                @php
                    $cardTitle = $running->isNotEmpty() ? 'Claude çalışıyor' : ($queued->isNotEmpty() ? 'Claude sıradaki işe geçiyor' : 'Claude hazır');
                    $cardLine = implode(' · ', array_filter([
                        $running->count() > 1 ? $running->count().' iş çalışıyor' : null,
                        $queued->isNotEmpty() ? $queued->count().' iş sırada' : null,
                        'son 24 saatte '.number_format($doneToday, 0, ',', '.').' iş bitti',
                    ]));
                @endphp
                {{-- Sidebar card (menu prototype 2026-10-20): dark, a live dot, what Claude does now and its day's count. --}}
                <summary class="relative flex cursor-pointer list-none items-center gap-2.5 overflow-hidden rounded-[14px] border border-white/10 bg-hero px-3 py-2.5 text-white"
                    :class="($store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen) ? '' : 'justify-center px-0'" title="{{ $cardTitle }}">
                    <span class="pointer-events-none absolute -bottom-10 -right-8 h-24 w-32 bg-[radial-gradient(closest-side,color-mix(in_srgb,var(--color-second)_70%,transparent),transparent)]" aria-hidden="true"></span>
                    <span @class(['relative h-2.5 w-2.5 shrink-0 rounded-full', 'bg-accent animate-pulse' => $running->isNotEmpty(), 'bg-warning-400' => $running->isEmpty() && $queued->isNotEmpty(), 'bg-success-400' => $running->isEmpty() && $queued->isEmpty()])></span>
                    <span class="relative min-w-0" x-show="$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen">
                        <span class="block truncate text-[13px] font-semibold">{{ $cardTitle }}</span>
                        <span class="block truncate text-xs text-white/65">{{ $cardLine }}</span>
                    </span>
                    <span class="sr-only">AI · {{ $running->count() }}</span>
                </summary>
            @else
            <summary class="flex h-8 cursor-pointer list-none items-center gap-1.5 rounded-md px-2 text-xs text-gray-500 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white">
                <span @class(['h-2 w-2 shrink-0 rounded-full', 'live-dot' => $running->isNotEmpty(), 'bg-warning-500' => $running->isEmpty() && $queued->isNotEmpty(), 'bg-success-500' => $running->isEmpty() && $queued->isEmpty() && $finished->first()?->status === 'done', 'bg-error-500' => $running->isEmpty() && $queued->isEmpty() && $finished->first()?->status === 'failed', 'bg-gray-400' => $running->isEmpty() && $queued->isEmpty() && $finished->first()?->status === 'cancelled'])></span>
                <span class="sr-only sm:not-sr-only">AI · {{ $running->count() }}</span>
                @if ($queued->isNotEmpty())<span class="sr-only sm:not-sr-only">+{{ $queued->count() }} sırada</span>@endif
            </summary>
            @endif
            <div @class(['absolute z-50 rounded-xl bg-white p-4 shadow-theme-lg ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700', 'right-0 mt-1.5 w-96 max-w-[90vw]' => ! $card, 'bottom-full left-0 mb-2 w-80 max-w-[85vw]' => $card]) data-ai-live-panel>
                <p class="mb-2 text-xs font-semibold text-gray-700 dark:text-gray-200">Çalışan AI işlemleri</p>
                <div class="max-h-80 space-y-2 overflow-y-auto">
                    @forelse ($running as $row)
                        <a href="{{ route('operator.ai-jobs', ['is' => $row->id]) }}" wire:navigate wire:key="ai-live-run-{{ $row->id }}" class="block border-b border-gray-100 pb-2 text-xs hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800" data-ai-live-running data-ai-live-item="{{ $row->id }}">
                            <p class="font-semibold text-gray-900 dark:text-white">{{ $row->label }}</p>
                            <p class="mt-0.5 text-live-700 dark:text-live-300">{{ $row->statusLabel() }} · {{ $row->durationLabel() }}@if ($row->user_id && isset($users[$row->user_id])) · {{ $users[$row->user_id] }}@endif</p>
                            @if ($row->subject)<p class="mt-0.5 truncate text-gray-500">{{ $row->subject }}</p>@endif
                        </a>
                    @empty
                        <p class="text-xs text-gray-500">Şu an çalışan AI işlemi yok.</p>
                    @endforelse
                </div>
                @if ($queued->isNotEmpty())
                    <p class="mb-2 mt-3 text-xs font-semibold text-gray-700 dark:text-gray-200">Sırada</p>
                    <div class="max-h-40 space-y-1.5 overflow-y-auto">
                        @foreach ($queued as $row)
                            <a href="{{ route('operator.ai-jobs', ['is' => $row->id]) }}" wire:navigate wire:key="ai-live-queued-{{ $row->id }}" class="flex items-baseline justify-between gap-2 text-xs hover:bg-gray-50 dark:hover:bg-gray-800" data-ai-live-item="{{ $row->id }}">
                                <span class="truncate text-gray-800 dark:text-gray-200">{{ $row->label }}</span>
                                <span class="shrink-0 text-warning-700 dark:text-warning-300">Sırada</span>
                            </a>
                        @endforeach
                    </div>
                @endif
                @if ($finished->isNotEmpty())
                    <p class="mb-2 mt-3 text-xs font-semibold text-gray-700 dark:text-gray-200">Son biten</p>
                    <div class="max-h-60 space-y-1.5 overflow-y-auto">
                        @foreach ($finished as $row)
                            <a href="{{ route('operator.ai-jobs', ['is' => $row->id]) }}" wire:navigate wire:key="ai-live-done-{{ $row->id }}" class="block text-xs hover:bg-gray-50 dark:hover:bg-gray-800" data-ai-live-item="{{ $row->id }}">
                                <p class="flex items-baseline justify-between gap-2">
                                    <span class="truncate text-gray-800 dark:text-gray-200">{{ $row->label }}</span>
                                    <span @class(['shrink-0 tabular-nums', 'text-success-700 dark:text-success-400' => $row->status === 'done', 'text-gray-500' => $row->status === 'cancelled', 'text-error-600' => ! in_array($row->status, ['done', 'cancelled'], true)])>{{ $row->statusLabel() }} · {{ $row->durationLabel() }}</span>
                                </p>
                                @if ($row->status === 'failed' && $row->error)<p class="truncate text-error-600" title="{{ $row->error }}">{{ $row->error }}</p>@endif
                            </a>
                        @endforeach
                    </div>
                @endif
                <div class="mt-3 flex items-center justify-between gap-2 border-t border-gray-100 pt-2 text-xs dark:border-gray-800">
                    <a href="{{ route('operator.ai-jobs') }}" wire:navigate class="link-neutral" data-ai-live-all>Tümünü gör</a>
                    @if ($canOpenSettings)
                        <a href="{{ route('operator.settings.ai-operations') }}" wire:navigate class="text-gray-500">Ayarlar › AI işlemleri</a>
                    @endif
                </div>
            </div>
        </details>
    @endif
</div>
