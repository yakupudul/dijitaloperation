@php
    $import = $backupImport;
    $importBusy = in_array($import?->status, ['queued', 'running'], true);
    $importStats = $import?->stats ?? [];
    $hasBackup = $backupConversations > 0;
    $megabytes = fn (?int $bytes): string => number_format(($bytes ?? 0) / 1048576, 1, ',', '.').' MB';
@endphp
<section data-wa-backup x-data="@include('livewire.operator.whatsapp.backup-alpine')">
    <div class="section-head"><h2 class="section-title">Telefondaki yedekten yükle</h2></div>
    <div class="card">
        <div class="card-row flex-wrap items-start sm:flex-nowrap">
            <p class="min-w-0 flex-1 text-sm text-gray-700 sm:pt-1.5 dark:text-gray-300">
                @if($hasBackup)
                    {{ $backupConversations }} görüşme yedekten geldi{{ filled($config['backup_snapshot_at'] ?? null) ? ', yedekteki son mesaj '.$when($config['backup_snapshot_at']) : '' }}. Yeni mesajları almak için güncel bir yedek yükleyin; yalnız eksik mesajlar eklenir.
                @else
                    WhatsApp Business'ın Android yedeğini (msgstore.db.crypt15) ve 64 haneli anahtarını yükleyin; tüm birebir sohbetler buraya gelir.
                @endif
            </p>
            @if(! $importBusy && $import?->status !== 'uploaded')
                <label class="btn-primary w-full cursor-pointer sm:w-auto" :class="uploading && 'pointer-events-none opacity-60'">
                    <span x-text="uploading && uploadingLine === null ? 'Yükleniyor… %' + progress : '{{ $hasBackup ? 'Yeni yedek seç' : 'Yedek dosyasını seç' }}'">{{ $hasBackup ? 'Yeni yedek seç' : 'Yedek dosyasını seç' }}</span>
                    <input type="file" class="sr-only" accept=".crypt15,.crypt14,.crypt12" @change="upload($event.target)" />
                </label>
            @endif
        </div>
        @if(! $importBusy && $import?->status !== 'uploaded')
            <div x-show="uploading && uploadingLine === null" x-cloak class="border-t border-gray-200 px-4 py-3 dark:border-gray-800"><div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800"><div class="h-full bg-gray-900 transition-all dark:bg-white" :style="'width: ' + progress + '%'"></div></div></div>
            @if($import?->status === 'uploading')
                <p class="card-row text-[13px] text-gray-500">Önceki yükleme yarıda kaldı (%{{ $import->size ? (int) floor($import->received_bytes * 100 / $import->size) : 0 }}); dosyayı yeniden seçin.</p>
            @endif
        @endif

        @if(! $importBusy)
            @if($import?->status === 'completed')
                <div class="card-row items-start" data-wa-backup-status="completed">
                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-success-500" aria-hidden="true"></span>
                    <p class="min-w-0 flex-1 text-[13px] text-gray-600 dark:text-gray-300">Son yedek {{ $when($import->finished_at) }} çıkarıldı: {{ $importStats['chats'] ?? 0 }} görüşme ({{ $importStats['new_chats'] ?? 0 }} yeni), {{ $importStats['new_messages'] ?? 0 }} yeni mesaj.@if(($importStats['skipped_groups'] ?? 0) > 0) {{ $importStats['skipped_groups'] }} grup sohbeti alınmadı.@endif</p>
                </div>
            @elseif($import?->status === 'failed')
                <div role="alert" class="card-row items-start" data-wa-backup-status="failed">
                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-error-500" aria-hidden="true"></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">Yedek çıkarılamadı</p>
                        <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $import->error }}</p>
                    </div>
                </div>
            @endif

            @if($import?->status === 'uploaded')
                <form class="card-row flex-wrap items-start sm:flex-nowrap" data-wa-backup-status="uploaded" @submit.prevent="extract($refs.key)">
                    <div class="min-w-0 flex-1 sm:pt-1.5">
                        <p class="text-sm text-gray-800 dark:text-gray-200"><strong class="font-medium">{{ $import->file_name }}</strong> yüklendi ({{ $megabytes($import->size) }}). <button type="button" wire:click="discardBackup" class="ml-1 text-[13px] text-gray-500 hover:text-gray-900 hover:underline dark:hover:text-white">Sil</button></p>
                        @if($import->error)
                            <p role="alert" class="mt-1 text-[13px] text-error-700 dark:text-error-300">{{ $import->error }}</p>
                        @endif
                        @error('backup_key')<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="flex w-full min-w-0 gap-2 sm:w-96 sm:shrink-0">
                        <span wire:ignore class="min-w-0 flex-1"><input x-ref="key" type="password" autocomplete="off" spellcheck="false" aria-label="64 haneli şifreleme anahtarı" placeholder="{{ $backupKeySaved ? 'Boş bırakın: kayıtlı anahtar kullanılır' : '64 haneli şifreleme anahtarı' }}" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 font-mono text-sm text-gray-900 placeholder:font-sans placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white" /></span>
                        <button type="submit" :disabled="extracting" class="btn-primary h-9!">Çıkar</button>
                    </div>
                </form>
            @endif
        @endif

        @include('livewire.operator.whatsapp.backup-loaded', ['loadedLine' => null, 'loadedCurrent' => $backupCurrent, 'loadedKeyHint' => $backupKeyHint, 'loadedChats' => $backupConversations])

        @if($hasBackup)
            <div class="card-row flex-wrap items-start sm:flex-nowrap" data-wa-contacts>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">Rehberden isimleri al</p>
                    <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">Yedekte kişi adları yok. Telefonda Kişiler › Ayarlar › Dışa aktar ile .vcf dosyası alıp seçin; numarası eşleşen görüşmelere isim yazılır. Rehberden yalnız ad ve numara okunur.</p>
                    @error('contacts')<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                </div>
                <label class="btn w-full cursor-pointer sm:w-auto">
                    Rehber dosyası seç (.vcf)
                    <input type="file" class="sr-only" accept=".vcf,text/vcard,text/x-vcard" @change="contacts($event)" />
                </label>
            </div>
        @endif

        <details class="group/how border-t border-gray-200 dark:border-gray-800" @if(! $hasBackup) open @endif wire:ignore.self>
            <summary class="flex cursor-pointer list-none items-center gap-2 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/[0.03] [&::-webkit-details-marker]:hidden">
                <svg class="h-4 w-4 shrink-0 text-gray-400 transition group-open/how:rotate-90" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.17 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
                Yedek nasıl alınır?
            </summary>
            <div class="px-4 pb-4 text-sm">
                <ol class="list-decimal space-y-1.5 pl-5 text-gray-600 dark:text-gray-300">
                    <li>Telefonda WhatsApp Business › Ayarlar › Sohbetler › Sohbet yedeği › <strong class="font-medium text-gray-900 dark:text-white">Uçtan uca şifreli yedek</strong>'i açın ve <strong class="font-medium text-gray-900 dark:text-white">64 haneli şifreleme anahtarı</strong> seçeneğini seçin. Anahtarı bir yere yazın (parola seçeneği değil). Bu yalnız ilk sefer gerekir.</li>
                    <li><strong class="font-medium text-gray-900 dark:text-white">Yedekle</strong>'ye basın ve bitmesini bekleyin.</li>
                    <li>Dosyayı bilgisayara alın: Dahili depolama › Android › media › com.whatsapp.w4b › WhatsApp Business › Databases › <code class="text-xs">msgstore.db.crypt15</code>.</li>
                    <li>Dosyayı yukarıdan seçin; ilk seferde yüklenince anahtarı girip <strong class="font-medium text-gray-900 dark:text-white">Çıkar</strong>'a basın. Doğru anahtar kaydedilir; sonraki yedeklerde yalnız dosyayı seçersiniz, çıkarma kendiliğinden başlar.</li>
                </ol>
                <p class="mt-3 text-xs text-gray-500">Son çıkarılan dosya (şifreli hâliyle) ve anahtar sunucuda şifreli saklanır; yeni yedek eskisinin yerini alır. Grup sohbetleri, fotoğraf, ses ve belgelerin içeriği alınmaz. Kişi adları bu dosyada olmadığı için numaralar görünür; müşteri kaydıyla eşleşen numara müşteriye bağlanır.</p>
            </div>
        </details>
    </div>

    @if($importBusy)
        <div class="mt-3">@include('livewire.operator.whatsapp.backup-progress', ['backupImport' => $import])</div>
    @endif
    <p x-show="message" x-text="message" role="status" class="mt-3 text-sm text-gray-600 dark:text-gray-300"></p>
    @error('backup')<p role="alert" class="mt-3 text-sm text-error-600">{{ $message }}</p>@enderror
</section>
