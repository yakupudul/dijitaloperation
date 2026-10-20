<div class="inline-flex flex-wrap items-center gap-2 text-xs" data-activity-pause>
    @if ($available)
        @if ($activity?->isPaused())
            <x-ta.pill tone="look">Duraklatıldı (müşteri kararı)</x-ta.pill>
            <x-ta.button size="sm" variant="outline" wire:click="resume" wire:loading.attr="disabled">Duraklatmayı kaldır</x-ta.button>
        @else
            @if ($activity !== null && $activity->tier->value !== 'active')
                <x-ta.pill tone="info">{{ $activity->tier->label() }}@if ($activity->last_active_on), son harcama {{ $activity->last_active_on->format('d.m.Y') }}@endif</x-ta.pill>
            @endif
            <x-ta.button size="sm" variant="outline" wire:click="pause" wire:confirm="Hesap müşteri kararıyla duraklatılsın mı? Veriler haftada bir kontrol edilir; harcama yeniden başlarsa duraklatma kendiliğinden kalkar." wire:loading.attr="disabled">Duraklat (müşteri kararı)</x-ta.button>
        @endif
        @if ($message !== '')<span class="text-gray-600 dark:text-gray-300">{{ $message }}</span>@endif
    @endif
</div>
