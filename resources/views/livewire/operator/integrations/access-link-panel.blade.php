<div class="mt-2.5 rounded-lg bg-gray-50 px-3 py-3 dark:bg-white/[0.04]" data-access-link>
    <p class="text-[13px] text-gray-700 dark:text-gray-300"><span class="font-medium text-gray-900 dark:text-white">Erişim bağlantısı.</span> Müşteri tek sayfada her erişim için üç adımı ve kimi ekleyeceğini görür, "Yetkiyi verdim" der; sana bildirim gelir.</p>
    <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2">
        @foreach ($guide as $key => $channel)
            <label class="flex cursor-pointer items-center gap-2 text-[13px] text-gray-800 dark:text-gray-200">
                <input type="checkbox" wire:model="channels" value="{{ $key }}" class="h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-gray-900 dark:border-gray-600 dark:bg-gray-900">
                {{ \App\Services\DataStatus\ConnectionMap::CHANNELS[$key] }}
                @if (($items[$key]['state'] ?? '') === 'marked')<span class="chip chip-accent">verdim dedi</span>@elseif (($items[$key]['state'] ?? '') === 'connected')<span class="chip chip-flat">bağlı</span>@endif
            </label>
        @endforeach
    </div>
    <div class="mt-3 grid gap-3 sm:grid-cols-2">
        <label class="block text-[12px] text-gray-500">Müşterinin ekleyeceği Google hesabı
            <input type="email" wire:model="google" placeholder="ornek@gmail.com" class="mt-1 block h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-[13px] text-gray-900 focus:border-gray-900 focus:ring-0 dark:border-gray-700 dark:bg-white/[0.04] dark:text-white">
        </label>
        <label class="block text-[12px] text-gray-500">Meta için e-posta ya da işletme kimliği (yalnız Meta istenirse)
            <input type="text" wire:model="meta" placeholder="ornek@gmail.com ya da 15 haneli numara" class="mt-1 block h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-[13px] text-gray-900 focus:border-gray-900 focus:ring-0 dark:border-gray-700 dark:bg-white/[0.04] dark:text-white">
        </label>
    </div>
    <div class="mt-3 flex flex-wrap items-center gap-2">
        <button type="button" wire:click="save" wire:loading.attr="disabled" class="btn-dark" data-access-link-save>{{ $link ? 'Bağlantıyı güncelle' : 'Bağlantıyı hazırla' }}</button>
        @if ($message !== '')<span class="text-[13px] text-gray-600 dark:text-gray-300" role="status">{{ $message }}</span>@endif
    </div>
    @if ($link)
        <div class="mt-3 border-t border-gray-200 pt-3 dark:border-gray-700" x-data="{ copied: false }">
            <p class="whitespace-pre-line text-[13px] leading-relaxed text-gray-800 dark:text-gray-200" data-access-link-text>{{ $text }}</p>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <button type="button" class="btn" x-on:click="navigator.clipboard.writeText(@js($text)).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"><span x-show="!copied">Mesajı kopyala</span><span x-show="copied" x-cloak>Kopyalandı</span></button>
                <a href="{{ route('access-link', ['token' => $link->token]) }}" target="_blank" rel="noopener" class="btn-quiet">Sayfaya bak</a>
                <span class="text-[12px] text-gray-500">{{ $link->opened_at ? 'Müşteri sayfayı açtı · '.$link->opened_at->timezone('Europe/Istanbul')->locale('tr')->isoFormat('D MMMM HH:mm') : 'Henüz açılmadı.' }}</span>
            </div>
        </div>
    @endif
</div>
