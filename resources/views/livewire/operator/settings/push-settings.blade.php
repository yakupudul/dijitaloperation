@php
    $input = 'mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700 dark:text-white';
@endphp
<div class="space-y-5">
    <div>
        <a href="{{ route('operator.settings', ['section' => 'operations']) }}" wire:navigate class="text-sm text-gray-500 hover:text-brand-600">← Ayarlar</a>
        <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">Telefon bildirimleri</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">Site kesintisi, kritik uyarılar, yaklaşan yenilemeler ve hatırlatıcılar telefonuna gelir. ntfy (uygulamayı kur, bir konu adı seç) veya Telegram (bot oluştur, sohbet kimliğini gir) — ikisi birden de olur.</p>
    </div>

    @if ($message !== '')
        <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>
    @endif

    <section class="grid gap-4 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 sm:grid-cols-2 dark:bg-gray-900 dark:ring-gray-800">
        <label class="text-sm text-gray-600 dark:text-gray-300">ntfy konu adresi
            <input type="url" wire:model="ntfyUrl" placeholder="https://ntfy.sh/ajans-gizli-konu" class="{{ $input }}" />
            @error('ntfyUrl')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror
        </label>
        <label class="text-sm text-gray-600 dark:text-gray-300">ntfy erişim anahtarı (isteğe bağlı) {{ $hasNtfyToken ? '· kayıtlı' : '' }}
            <input type="password" wire:model="ntfyToken" autocomplete="off" placeholder="{{ $hasNtfyToken ? 'Değiştirmek için yeni anahtar' : 'tk_…' }}" class="{{ $input }}" />
        </label>
        <label class="text-sm text-gray-600 dark:text-gray-300">Telegram bot anahtarı {{ $hasTelegramToken ? '· kayıtlı' : '' }}
            <input type="password" wire:model="telegramToken" autocomplete="off" placeholder="{{ $hasTelegramToken ? 'Değiştirmek için yeni anahtar' : '123456:ABC…' }}" class="{{ $input }}" />
        </label>
        <label class="text-sm text-gray-600 dark:text-gray-300">Telegram sohbet kimliği
            <input type="text" wire:model="telegramChatId" placeholder="123456789" class="{{ $input }}" />
            @error('telegramChatId')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror
        </label>
        <label class="text-sm text-gray-600 dark:text-gray-300">En az önem
            <select wire:model="minSeverity" class="{{ $input }}">
                <option value="critical">Yalnız kritik (site kesintisi, dönüşüm durdu…)</option>
                <option value="high">Yüksek ve kritik</option>
                <option value="medium">Orta ve üstü</option>
                <option value="info">Hepsi (hatırlatıcılar dahil)</option>
            </select>
        </label>
        <div class="flex flex-wrap items-end gap-2">
            <button type="button" wire:click="save" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-600">Kaydet</button>
            <button type="button" wire:click="test" class="rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700">Deneme gönder</button>
            @if ($hasNtfyToken || $hasTelegramToken)
                <button type="button" wire:click="clearSecrets" wire:confirm="Kayıtlı anahtarlar silinsin mi?" class="rounded-lg px-3 py-2 text-sm text-rose-600">Anahtarları sil</button>
            @endif
        </div>
        <p class="text-xs text-gray-500 sm:col-span-2">Aktif kanallar: {{ $channels === [] ? 'yok' : implode(', ', $channels) }}. Hatırlatıcılar kendi zamanında her zaman gönderilir (önem seçimi uyarılar içindir).</p>
    </section>

    <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Son gönderimler</h2>
        @forelse ($log as $row)
            <p class="mt-1 text-xs"><span class="{{ $row->status === 'sent' ? 'text-emerald-600' : 'text-rose-600' }}">{{ $row->status === 'sent' ? 'Gönderildi' : 'Hata' }}</span> · {{ $row->channel }} · {{ \Illuminate\Support\Carbon::parse($row->created_at)->timezone('Europe/Istanbul')->format('d.m.Y H:i') }} · {{ $row->title }} @if ($row->error) <span class="text-rose-600">({{ $row->error }})</span> @endif</p>
        @empty
            <p class="mt-2 text-sm text-gray-500">Henüz gönderim yok.</p>
        @endforelse
    </section>
</div>
