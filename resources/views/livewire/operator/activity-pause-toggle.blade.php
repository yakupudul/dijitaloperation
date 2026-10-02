<div class="inline-flex flex-wrap items-center gap-2 text-xs" data-activity-pause>
    @if ($available)
        @if ($activity?->isPaused())
            <span class="rounded-full bg-amber-50 px-2 py-0.5 font-medium text-amber-800 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30">Duraklatıldı (müşteri kararı)</span>
            <x-ta.button size="sm" variant="outline" wire:click="resume" wire:loading.attr="disabled">Duraklatmayı kaldır</x-ta.button>
        @else
            @if ($activity !== null && $activity->tier->value !== 'active')
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-700 dark:bg-white/5 dark:text-gray-300">{{ $activity->tier->label() }}@if ($activity->last_active_on) · son harcama {{ $activity->last_active_on->format('d.m.Y') }}@endif</span>
            @endif
            <x-ta.button size="sm" variant="outline" wire:click="pause" wire:confirm="Hesap müşteri kararıyla duraklatılsın mı? Veriler haftada bir kontrol edilir; harcama yeniden başlarsa duraklatma kendiliğinden kalkar." wire:loading.attr="disabled">Duraklat (müşteri kararı)</x-ta.button>
        @endif
        @if ($message !== '')<span class="text-emerald-700 dark:text-emerald-300">{{ $message }}</span>@endif
    @endif
</div>
