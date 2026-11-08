<div class="flex flex-wrap items-center justify-between gap-3 p-4" data-account="{{ $account->id }}">
    <div class="flex min-w-0 items-center gap-3">
        @if ($account->picture_url)<img src="{{ $account->picture_url }}" alt="" class="h-9 w-9 rounded-full">@endif
        <div class="min-w-0">
            <p class="text-sm font-semibold">{{ $account->platformLabel() }} · {{ $account->displayName() }}</p>
            <p class="text-xs text-gray-500">{{ $account->isManual() ? 'Kopyala ve aç ile paylaşılır' : ($account->enabled ? 'Otomatik yayın' : 'Kapalı') }}{{ $account->connected_at ? ' · bağlandı '.$account->connected_at->timezone($tz)->format('d.m.Y') : '' }}</p>
        </div>
    </div>
    @if ($canWrite)@if ($account->enabled)<button type="button" wire:click="toggleAccount({{ $account->id }})" class="btn">Kapat</button>@else<button type="button" x-on:click="if (confirm('Açılırsa Moximu gönderileri bu hesapta da yayınlanır. Emin misiniz?')) $wire.toggleAccount({{ $account->id }})" class="btn">Aç</button>@endif@endif
</div>
