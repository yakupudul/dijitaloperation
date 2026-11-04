@php
    // Telefon bağlantısı (2026-11-04): the phone (FolderSync) puts the backup on a WebDAV address of MoxDOP every morning;
    // with the saved key it is extracted at once. One address, one password per row; the password is shown once.
    $sync = app(\App\Services\Sales\PhoneBackupSync::class);
    $syncOn = $sync->enabled($syncLine);
    $syncLast = $sync->lastReceived($syncLine);
    $syncShownPassword = $phoneSyncShown[$syncLine->id] ?? null;
@endphp
<div class="border-t border-gray-200 px-4 py-3.5 dark:border-gray-800" data-wa-phone-sync="{{ $syncLine->id }}" data-wa-phone-sync-state="{{ $syncOn ? 'on' : 'off' }}">
    <div class="flex flex-wrap items-start gap-3">
        <div class="min-w-0 flex-1">
            <p class="text-sm font-medium text-gray-900 dark:text-white">Telefon bağlantısı
                @if($syncOn)<x-ta.pill tone="ok" class="ml-1.5">açık</x-ta.pill>@else<x-ta.pill tone="info" class="ml-1.5">kapalı</x-ta.pill>@endif
            </p>
            <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">
                @if($syncOn && $syncLast !== null)
                    Son dosya {{ \Carbon\CarbonImmutable::parse((string) ($syncLast['at'] ?? now()))->timezone('Europe/Istanbul')->format('d.m.Y H:i') }} geldi ({{ number_format(((int) ($syncLast['size'] ?? 0)) / 1048576, 1, ',', '.') }} MB).
                    {{ $syncKeyHint ? 'Anahtar kayıtlı: gelen yedek kendiliğinden çıkarılır.' : 'Anahtar kayıtlı değil: gelen yedek için bir kez Çıkar gerekir.' }}
                @elseif($syncOn)
                    Adres hazır; telefon ilk dosyayı gönderince burada görünür.{{ $syncKeyHint ? '' : ' Anahtar bu satıra bir kez kaydedilince gelen her yedek kendiliğinden çıkarılır.' }}
                @else
                    Telefon her sabah WhatsApp yedeğini bu adrese kendisi koyar; elle dosya seçmek biter.
                @endif
            </p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            @if($syncOn)
                <button type="button" wire:click="issuePhoneSync({{ $syncLine->id }})" wire:loading.attr="disabled" class="btn" data-wa-phone-sync-issue>Yeni şifre</button>
                <button type="button" wire:click="revokePhoneSync({{ $syncLine->id }})" wire:confirm="Telefon bağlantısı kapansın mı? Telefondaki uygulama artık dosya bırakamaz." class="btn-quiet">Kapat</button>
            @else
                <button type="button" wire:click="issuePhoneSync({{ $syncLine->id }})" wire:loading.attr="disabled" class="btn-dark" data-wa-phone-sync-issue>Bağlantıyı aç</button>
            @endif
        </div>
    </div>
    @if($syncShownPassword !== null)
        <div class="mt-3 rounded-xl border border-gray-200 bg-gray-50 p-3 text-[13px] dark:border-gray-800 dark:bg-white/[0.03]" data-wa-phone-sync-password>
            <p class="font-medium text-gray-900 dark:text-white">Telefondaki FolderSync'e bunları yaz. Şifre yalnız şimdi görünür.</p>
            <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 font-mono text-xs text-gray-800 dark:text-gray-200">
                <dt class="font-sans text-gray-500">Tür</dt><dd class="font-sans">WebDAV</dd>
                <dt class="font-sans text-gray-500">Adres</dt><dd class="break-all select-all">{{ \App\Services\Sales\PhoneBackupSync::url() }}</dd>
                <dt class="font-sans text-gray-500">Kullanıcı</dt><dd class="select-all">{{ \App\Services\Sales\PhoneBackupSync::USERNAME }}</dd>
                <dt class="font-sans text-gray-500">Şifre</dt><dd class="select-all">{{ $syncShownPassword }}</dd>
            </dl>
            <p class="mt-2 font-sans text-xs text-gray-500">Telefon klasörü: Android/media/com.whatsapp.w4b/WhatsApp Business/Databases (WhatsApp Business) ya da com.whatsapp/WhatsApp/Databases. Yön: telefondan buluta; yalnız msgstore.db.crypt15; her gün WhatsApp yedeğinden yarım saat sonra. Dosya 32 MB'tan büyükse sunucu sınırı yükseltilmeli (deploy/staging/nginx.conf.example).</p>
        </div>
    @endif
</div>
