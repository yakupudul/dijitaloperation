{{-- Komuta "Bugün satış" box (2026-11-04): the prospects whose next step is today; the full list and texts are on Satış. --}}
<div class="card" data-sales-today>
    <div class="flex items-center justify-between gap-3 px-4 pt-4 pb-2">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Bugün satış <span class="font-normal text-gray-500 tabular-nums">{{ $count }}</span></h2>
        <a href="{{ route('operator.sales') }}" wire:navigate class="link-neutral text-[13px]">{{ $open }} açık aday →</a>
    </div>
    @forelse ($due as $lead)
        <a href="{{ route('operator.sales.lead', ['lead' => $lead->id]) }}" wire:navigate wire:key="today-{{ $lead->id }}" class="card-row py-2.5! hover:bg-gray-50 dark:hover:bg-white/[0.02]">
            <x-ta.brand-avatar :name="$lead->title()" size="sm" />
            <span class="min-w-0 flex-1 truncate text-sm text-gray-900 dark:text-white">{{ $lead->title() }}<span class="text-gray-500"> · {{ $lead->next_step ?: $lead->stageLabel() }}</span></span>
            <span class="text-xs text-gray-500">{{ $lead->next_step_at?->timezone('Europe/Istanbul')->toDateString() < now('Europe/Istanbul')->toDateString() ? 'gecikti' : 'bugün' }}</span>
        </a>
    @empty
        <p class="px-4 pb-4 text-sm text-gray-500">Bugün yazılacak kimse yok. Bir site linkini ya da sohbeti telefondan MoxDOP'a paylaş; kart kendiliğinden açılır.</p>
    @endforelse
    @if ($count > 5)
        <a href="{{ route('operator.sales') }}" wire:navigate class="block border-t border-gray-200 px-4 py-2.5 text-[13px] text-gray-500 hover:text-gray-900 dark:border-gray-800">{{ $count - 5 }} kişi daha →</a>
    @endif
</div>
