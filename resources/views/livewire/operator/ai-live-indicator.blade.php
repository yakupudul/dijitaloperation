<div @if ($running->isNotEmpty()) wire:poll.5s @else wire:poll.30s @endif class="relative" data-ai-live-indicator>
    @if ($visible)
        <details class="group" wire:key="ai-live-details">
            <summary class="flex cursor-pointer list-none items-center gap-2 rounded-lg border border-violet-200 bg-violet-50 px-3 py-2 text-xs text-violet-800 dark:border-violet-500/20 dark:bg-violet-500/10 dark:text-violet-300">
                <span @class(['h-2 w-2 shrink-0 rounded-full', 'animate-pulse bg-violet-500' => $running->isNotEmpty(), 'bg-emerald-500' => $running->isEmpty() && $finished->first()?->status === 'done', 'bg-rose-500' => $running->isEmpty() && $finished->first()?->status === 'failed'])></span>
                <span class="font-semibold">AI · {{ $running->count() }}</span>
                @if ($running->count() === 1)<span class="hidden max-w-52 truncate sm:inline">{{ $running->first()->label }}</span>@endif
                <span aria-hidden="true">▾</span>
            </summary>
            <div class="absolute right-0 z-50 mt-2 w-96 max-w-[90vw] rounded-xl border border-gray-200 bg-white p-4 shadow-lg dark:border-gray-700 dark:bg-gray-900">
                <p class="mb-2 text-xs font-semibold text-gray-700 dark:text-gray-200">Çalışan AI işlemleri</p>
                <div class="max-h-80 space-y-2 overflow-y-auto">
                    @forelse ($running as $row)
                        <div wire:key="ai-live-run-{{ $row->id }}" class="border-b border-gray-100 pb-2 text-xs dark:border-gray-800" data-ai-live-running>
                            <p class="font-semibold text-gray-900 dark:text-white">{{ $row->label }}</p>
                            <p class="mt-0.5 text-violet-700 dark:text-violet-300">Çalışıyor · {{ $row->durationLabel() }}@if ($row->user_id && isset($users[$row->user_id])) · {{ $users[$row->user_id] }}@endif</p>
                            @if ($row->subject)<p class="mt-0.5 truncate text-gray-500">{{ $row->subject }}</p>@endif
                        </div>
                    @empty
                        <p class="text-xs text-gray-500">Şu an çalışan AI işlemi yok.</p>
                    @endforelse
                </div>
                @if ($finished->isNotEmpty())
                    <p class="mb-2 mt-3 text-xs font-semibold text-gray-700 dark:text-gray-200">Son biten</p>
                    <div class="max-h-60 space-y-1.5 overflow-y-auto">
                        @foreach ($finished as $row)
                            <div wire:key="ai-live-done-{{ $row->id }}" class="text-xs">
                                <p class="flex items-baseline justify-between gap-2">
                                    <span class="truncate text-gray-800 dark:text-gray-200">{{ $row->label }}</span>
                                    <span @class(['shrink-0 tabular-nums', 'text-emerald-700 dark:text-emerald-400' => $row->status === 'done', 'text-rose-600' => $row->status !== 'done'])>{{ $row->statusLabel() }} · {{ $row->durationLabel() }}</span>
                                </p>
                                @if ($row->status !== 'done' && $row->error)<p class="truncate text-rose-600" title="{{ $row->error }}">{{ $row->error }}</p>@endif
                            </div>
                        @endforeach
                    </div>
                @endif
                @if ($canOpenSettings)
                    <a href="{{ route('operator.settings.ai-operations') }}#canli" wire:navigate class="mt-3 block text-xs font-semibold text-brand-600">Ayarlar › AI işlemleri · Canlı</a>
                @endif
            </div>
        </details>
    @endif
</div>
