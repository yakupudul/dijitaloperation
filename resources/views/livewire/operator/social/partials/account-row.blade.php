<div class="flex flex-wrap items-center justify-between gap-3 p-4" data-account="{{ $account->id }}">
    <div class="flex min-w-0 items-center gap-3">
        @if ($account->picture_url)<img src="{{ $account->picture_url }}" alt="" class="h-9 w-9 rounded-full">@endif
        <div class="min-w-0">
            <p class="text-sm font-semibold">{{ $account->platformLabel() }} · {{ $account->displayName() }}</p>
            <p class="text-xs text-gray-500">{{ $account->isManual() ? 'Kopyala ve aç ile paylaşılır' : ($account->enabled ? 'Otomatik yayın' : 'Kapalı') }}{{ $account->connected_at ? ' · bağlandı '.$account->connected_at->timezone($tz)->format('d.m.Y') : '' }}{{ $account->enabled && data_get($account->meta, 'inbox_synced_at') ? ' · mesajlar okundu '.\Illuminate\Support\Carbon::parse(data_get($account->meta, 'inbox_synced_at'))->timezone($tz)->format('d.m H:i') : '' }}</p>
            @if ($account->enabled && ! empty(data_get($account->meta, 'inbox_errors')))
                <p class="mt-1 text-xs text-error-600" data-inbox-errors>Okunamadı: {{ implode(' · ', (array) data_get($account->meta, 'inbox_errors')) }}</p>
            @elseif ($account->enabled && $account->platform === \App\Models\SocialAccount::INSTAGRAM && data_get($account->meta, 'inbox_dm_seen') === 0)
                <p class="mt-1 text-xs text-warning-700" data-inbox-hint>Instagram hiç mesaj göstermiyor. Instagram uygulamasında Ayarlar › Mesajlar ve hikâye yanıtları › Bağlı araçlar › “Mesajlara erişime izin ver” açık olmalı.</p>
            @endif
        </div>
    </div>
    @if ($canWrite)@if ($account->enabled)<button type="button" wire:click="toggleAccount({{ $account->id }})" class="btn">Kapat</button>@else<button type="button" x-on:click="if (confirm('Açılırsa Moximu gönderileri bu hesapta da yayınlanır. Emin misiniz?')) $wire.toggleAccount({{ $account->id }})" class="btn">Aç</button>@endif@endif
</div>
