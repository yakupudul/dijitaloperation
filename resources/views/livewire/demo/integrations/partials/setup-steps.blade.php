{{-- Faz 13: one setup pattern for provider pages (Uygulama, Yetki, Hesaplar, Veri). Expects $steps. --}}
<div class="grid gap-2 md:grid-cols-4">
    @foreach ($steps as $index => $step)
        <button type="button" wire:click="setTab('{{ $step['tab'] }}')" class="group flex min-w-0 items-center gap-3 rounded-lg px-2 py-2 text-left transition hover:bg-gray-50 dark:hover:bg-white/[0.04]">
            <span @class([
                'inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $step['done'],
                'text-gray-500 ring-1 ring-inset ring-gray-300 dark:text-gray-400 dark:ring-gray-700' => ! $step['done'],
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
