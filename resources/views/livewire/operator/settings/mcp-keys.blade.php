@php
    $field = 'h-9 rounded-lg border border-gray-200 bg-white px-3 text-sm dark:border-gray-700 dark:bg-transparent';
    $activeCount = $keys->whereNull('revoked_at')->count();
@endphp
<div class="space-y-10">
    <header class="min-w-0">
        <h1 class="page-title">MCP anahtarları</h1>
        <p class="page-sub">Claude MoxDOP'a bu anahtarlarla bağlanır; okuma anahtarı yalnız okur, yazma anahtarı kuyruk işlerini de yapar.</p>
        <p class="mt-1 text-xs text-gray-500" data-oauth-hint>claude.ai bağlayıcısı anahtar istemez: {{ $serverUrl }} adresini ekleyip Bağlan'a bas, açılan MoxDOP ekranında onayla; anahtar burada kendiliğinden görünür.</p>
    </header>

    @if ($newKey)
        <section class="card -mt-4 space-y-3 p-4" data-new-key>
            <p class="text-sm font-medium text-gray-900 dark:text-white">Yeni anahtar yalnız şimdi görünür. Anahtarı başlıkla gönderebilen araçlar (Claude Code gibi) içindir; sohbete yazma.</p>
            <div x-data="{ copied: false }" class="flex flex-wrap items-center gap-2">
                <code class="min-w-0 flex-1 break-all rounded-lg bg-gray-100 px-3 py-2 text-[13px] dark:bg-white/10" x-ref="key">{{ $newKey }}</code>
                <button type="button" class="btn w-full sm:w-auto" x-on:click="navigator.clipboard.writeText($refs.key.innerText); copied = true" x-text="copied ? 'Kopyalandı' : 'Kopyala'">Kopyala</button>
                <button type="button" class="btn w-full sm:w-auto" wire:click="forgetNewKey">Kapat</button>
            </div>
            <p class="text-xs text-gray-500">Sunucu adresi: {{ $serverUrl }}</p>
        </section>
    @endif

    <section class="card -mt-4" data-create-key>
        <div class="flex flex-wrap items-center gap-2 p-4">
            <input type="text" wire:model="name" placeholder="Nerede kullanılacak? (ör. claude.ai bağlayıcısı)" aria-label="Ad" class="{{ $field }} min-w-0 flex-1 sm:min-w-60">
            <select wire:model="scope" aria-label="Yetki" class="{{ $field }} w-full sm:w-auto">
                <option value="read">Yalnız okuma</option>
                <option value="write">Okuma ve yazma</option>
            </select>
            <button type="button" wire:click="create" class="btn-primary w-full sm:w-auto">Anahtar üret</button>
        </div>
        @error('name')<p class="px-4 pb-3 text-xs text-error-600">{{ $message }}</p>@enderror
    </section>

    <section class="card overflow-x-auto" data-keys>
        <table class="data-table">
            <thead><tr><th>Ad</th><th>Yetki</th><th class="hidden sm:table-cell">Son kullanım</th><th class="text-right!">Durum</th></tr></thead>
            <tbody>
                @if ($envConfigured)
                    <tr wire:key="key-env">
                        <td><span class="block font-medium text-gray-900 dark:text-white">Eski sunucu anahtarı</span><span class="block text-xs text-gray-500">Sunucu ayarındaki anahtar; panel anahtarına geçince kapat.</span></td>
                        <td><x-ta.pill tone="look">Okuma ve yazma</x-ta.pill></td>
                        <td class="hidden text-xs text-gray-500 sm:table-cell">—</td>
                        <td class="text-right">
                            @if ($envOn)
                                <button type="button" class="btn" wire:click="setEnvKey(false)" wire:confirm="Eski anahtar kapanınca onu kullanan bağlantılar MoxDOP'a ulaşamaz. Kapatılsın mı?">Kapat</button>
                            @else
                                <span class="inline-flex items-center gap-2"><x-ta.pill tone="info">Kapalı</x-ta.pill><button type="button" class="text-xs text-gray-500 underline" wire:click="setEnvKey(true)">Aç</button></span>
                            @endif
                        </td>
                    </tr>
                @endif
                @forelse ($keys as $key)
                    <tr wire:key="key-{{ $key->id }}">
                        <td><span class="block font-medium text-gray-900 dark:text-white">{{ $key->name }}</span><span class="block text-xs text-gray-500">…{{ $key->last_four }} · {{ $key->created_at?->timezone('Europe/Istanbul')->format('d.m.Y') }}</span></td>
                        <td><x-ta.pill :tone="$key->scope === 'write' ? 'look' : 'ok'">{{ $key->scope === 'write' ? 'Okuma ve yazma' : 'Yalnız okuma' }}</x-ta.pill></td>
                        <td class="hidden whitespace-nowrap text-xs tabular-nums text-gray-500 sm:table-cell">{{ $key->last_used_at?->timezone('Europe/Istanbul')->format('d.m H:i') ?? 'Hiç' }}</td>
                        <td class="text-right">
                            @if ($key->revoked_at)
                                <x-ta.pill tone="info">İptal</x-ta.pill>
                            @else
                                <button type="button" class="btn" wire:click="revoke({{ $key->id }})" wire:confirm="Bu anahtar hemen çalışmaz olur. İptal edilsin mi?">İptal et</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    @unless ($envConfigured)
                        <tr><td colspan="4"><x-ta.empty-state size="sm" icon="none" title="Henüz anahtar yok." class="px-0! py-0!" /></td></tr>
                    @endunless
                @endforelse
            </tbody>
        </table>
        <p class="border-t border-gray-200 px-4 py-3 text-xs text-gray-500 dark:border-gray-800">{{ $activeCount }} panel anahtarı çalışıyor. Anahtarın kendisi saklanmaz; kaybolursa yenisini üret, eskisini iptal et.</p>
    </section>
</div>
