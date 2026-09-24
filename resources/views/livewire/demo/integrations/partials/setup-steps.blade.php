{{-- Faz 13: one setup pattern for provider pages — Uygulama → Yetki → Hesaplar → Veri. Expects $steps. --}}
<div class="grid gap-3 rounded-xl bg-gray-50/70 p-3 ring-1 ring-inset ring-gray-200 dark:bg-white/[0.02] dark:ring-gray-800 md:grid-cols-4">
    @foreach ($steps as $index => $step)
        <button type="button" wire:click="setTab('{{ $step['tab'] }}')" class="group flex min-w-0 items-center gap-3 rounded-xl px-3 py-2.5 text-left transition hover:bg-white dark:hover:bg-white/[0.04]">
            <span @class([
                'inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold ring-1 ring-inset',
                'bg-success-50 text-success-700 ring-success-200 dark:bg-success-500/10 dark:text-success-300 dark:ring-success-500/20' => $step['done'],
                'bg-white text-gray-500 ring-gray-200 dark:bg-gray-800 dark:text-gray-400 dark:ring-gray-700' => ! $step['done'],
            ])>
                @if ($step['done'])
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12.5l4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                @else
                    {{ $index + 1 }}
                @endif
            </span>
            <span class="min-w-0">
                <span class="block truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $step['label'] }}</span>
                <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $step['detail'] }}</span>
            </span>
        </button>
    @endforeach
</div>
