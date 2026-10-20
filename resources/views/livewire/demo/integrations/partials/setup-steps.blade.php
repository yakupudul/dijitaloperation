{{-- One setup pattern for provider pages (Uygulama, Yetki, Hesaplar, Veri): four cells in one card, the first open step
     marked. Expects $steps (label, detail, done, tab). --}}
@php $firstOpen = collect($steps)->search(fn (array $step): bool => ! $step['done']); @endphp
<div class="metric-strip grid-cols-2 lg:grid-cols-4" data-setup-steps>
    @foreach ($steps as $index => $step)
        <button type="button" wire:click="setTab('{{ $step['tab'] }}')" class="flex min-w-0 items-start gap-3 bg-white px-4 py-3.5 text-left transition hover:bg-gray-50 dark:bg-gray-900 dark:hover:bg-white/[0.03]">
            <span @class([
                'mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold tabular-nums',
                'bg-success-500 text-white' => $step['done'],
                'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => ! $step['done'] && $index === $firstOpen,
                'text-gray-500 ring-1 ring-inset ring-gray-300 dark:text-gray-400 dark:ring-gray-700' => ! $step['done'] && $index !== $firstOpen,
            ])>
                @if ($step['done'])
                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 12.5l4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                @else
                    {{ $index + 1 }}
                @endif
            </span>
            <span class="min-w-0">
                <span class="block truncate text-sm font-medium text-gray-900 dark:text-white">{{ $step['label'] }}</span>
                <span class="mt-0.5 block text-[13px] text-gray-500 dark:text-gray-400">{{ $step['detail'] }}</span>
            </span>
        </button>
    @endforeach
</div>
