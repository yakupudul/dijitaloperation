@php
    $import = $backupImport;
    $importBusy = in_array($import?->status, ['queued', 'running'], true);
    $importStats = $import?->stats ?? [];
    $hasBackup = $backupConversations > 0;
    $megabytes = fn (?int $bytes): string => number_format(($bytes ?? 0) / 1048576, 1, ',', '.').' MB';
    $uploadUrls = [
        'begin' => route('operator.whatsapp.backup'),
        'chunk' => route('operator.whatsapp.backup.chunk', ['import' => '00000000-0000-0000-0000-000000000000']),
    ];
@endphp
<section class="{{ $card }} space-y-4 p-5" data-wa-backup
    x-data="{
        urls: @js($uploadUrls), progress: 0, uploading: false, message: '', extracting: false,
        async send(url, body, type) {
            for (let attempt = 1; ; attempt++) {
                let response;
                try {
                    response = await fetch(url, { method: 'POST', credentials: 'same-origin', body,
                        headers: { 'Content-Type': type, 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
                } catch (_) {
                    if (attempt < 4) { await new Promise(done => setTimeout(done, attempt * 2000)); continue; }
                    throw new Error('Sunucuya ulaşılamadı. İnternet bağlantınızı kontrol edip dosyayı yeniden seçin.');
                }
                const isJson = (response.headers.get('Content-Type') || '').includes('application/json');
                const data = isJson ? await response.json().catch(() => ({})) : {};
                if (response.status === 409 && data.expected_offset !== undefined) return data;
                if (!response.ok || response.redirected || !isJson) {
                    if ([401, 419].includes(response.status) || response.redirected) throw new Error('MoxDOP oturumunuz kapanmış. Sayfayı yenileyip giriş yapın.');
                    if (response.status >= 500 && attempt < 4) { await new Promise(done => setTimeout(done, attempt * 2000)); continue; }
                    const fieldError = data.errors ? Object.values(data.errors).flat()[0] : null;
                    throw new Error(fieldError || data.message || ('Yükleme durdu (HTTP ' + response.status + ').'));
                }
                return data;
            }
        },
        async upload() {
            const file = this.$refs.file.files[0];
            if (!file || this.uploading) return;
            this.uploading = true; this.message = ''; this.progress = 0;
            try {
                const started = await this.send(this.urls.begin, JSON.stringify({ name: file.name, size: file.size }), 'application/json');
                const url = this.urls.chunk.replace('00000000-0000-0000-0000-000000000000', started.id);
                let offset = 0, answer = {};
                while (offset < file.size) {
                    answer = await this.send(url + '?offset=' + offset, file.slice(offset, offset + started.chunk), 'application/octet-stream');
                    offset = answer.expected_offset ?? answer.received;
                    this.progress = Math.floor(offset * 100 / file.size);
                }
                this.message = answer.extracting ? 'Yüklendi; kayıtlı anahtarla çıkarılıyor.' : 'Yüklendi. Şimdi 64 haneli anahtarı girip Çıkar\'a basın.';
                this.$refs.file.value = '';
                await $wire.$refresh();
            } catch (error) { this.message = error.message; }
            finally { this.uploading = false; }
        },
        async contacts(event) {
            const file = event.target.files[0];
            if (!file) return;
            this.message = 'Rehber okunuyor…';
            try {
                // Only names and numbers leave the browser: photos, e-mails and addresses are dropped here.
                const lines = (await file.text()).split(/\r\n|\r|\n/);
                const kept = [];
                let keep = false;
                for (const line of lines) {
                    if (/^[ \t]/.test(line) || (keep && kept.length && kept[kept.length - 1].endsWith('='))) { if (keep) kept.push(line); continue; }
                    keep = /^(BEGIN|END|FN|N|TEL|item\d+\.TEL)[;:]/i.test(line);
                    if (keep) kept.push(line);
                }
                this.message = '';
                await $wire.importContacts(kept.join('\n'));
            } catch (error) { this.message = 'Rehber okunamadı: ' + error.message; }
            finally { event.target.value = ''; }
        },
        async extract() {
            if (this.extracting) return;
            this.extracting = true;
            try { if (await $wire.extractBackup(this.$refs.key.value)) this.$refs.key.value = ''; }
            finally { this.extracting = false; }
        },
    }">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <h2 class="font-semibold text-gray-900 dark:text-white">Telefondaki yedekten yükle</h2>
            <p class="mt-1 text-sm text-gray-500">
                @if($hasBackup)
                    {{ $backupConversations }} görüşme yedekten geldi{{ filled($config['backup_snapshot_at'] ?? null) ? ' · yedekteki son mesaj '.$when($config['backup_snapshot_at']) : '' }}. Yeni mesajları almak için güncel bir yedek yükleyin; yalnız eksik mesajlar eklenir.
                @else
                    WhatsApp Business'ın Android yedeğini (msgstore.db.crypt15) ve 64 haneli anahtarını yükleyin; tüm birebir sohbetler buraya gelir.
                @endif
            </p>
            @if($backupKeySaved)
                <p class="mt-1 text-xs text-gray-500" data-wa-backup-key-saved>Anahtar kayıtlı: yeni yedekte yalnız dosyayı seçin. <button type="button" wire:click="forgetBackupKey" wire:confirm="Kayıtlı anahtar silinsin mi? Sonraki yedekte yeniden sorulur." class="underline hover:text-rose-600">Kayıtlı anahtarı sil</button></p>
            @endif
        </div>
    </div>

    <details class="text-sm" @if(! $hasBackup) open @endif wire:ignore.self>
        <summary class="cursor-pointer font-medium text-gray-700 dark:text-gray-200">Yedek nasıl alınır?</summary>
        <ol class="mt-2 list-decimal space-y-1 pl-5 text-gray-600 dark:text-gray-300">
            <li>Telefonda WhatsApp Business › Ayarlar › Sohbetler › Sohbet yedeği › <strong>Uçtan uca şifreli yedek</strong>'i açın ve <strong>64 haneli şifreleme anahtarı</strong> seçeneğini seçin. Anahtarı bir yere yazın (parola seçeneği değil). Bu yalnız ilk sefer gerekir.</li>
            <li><strong>Yedekle</strong>'ye basın ve bitmesini bekleyin.</li>
            <li>Dosyayı bilgisayara alın: Dahili depolama › Android › media › com.whatsapp.w4b › WhatsApp Business › Databases › <code class="text-xs">msgstore.db.crypt15</code>.</li>
            <li>Dosyayı aşağıdan seçin; ilk seferde yüklenince anahtarı girip <strong>Çıkar</strong>'a basın. Doğru anahtar kaydedilir; sonraki yedeklerde yalnız dosyayı seçersiniz, çıkarma kendiliğinden başlar.</li>
        </ol>
        <p class="mt-2 text-xs text-gray-500">Dosya, çıkarma bitince sunucudan silinir; anahtar şifreli saklanır. Grup sohbetleri, fotoğraf, ses ve belgelerin içeriği alınmaz. Kişi adları bu dosyada olmadığı için numaralar görünür; müşteri kaydıyla eşleşen numara müşteriye bağlanır.</p>
    </details>

    @if($importBusy)
        <div class="rounded-lg p-3 text-sm ring-1 ring-inset {{ $tone['warn'] }}" data-wa-backup-status="{{ $import->status }}">
            <p class="font-medium">{{ ($importStats['phase'] ?? '') === 'import' ? 'Görüşmeler aktarılıyor: '.($importStats['chats'] ?? 0).' görüşme, '.($importStats['new_messages'] ?? 0).' yeni mesaj' : ($import->status === 'queued' ? 'Çıkarma sırada…' : 'Yedek açılıyor…') }}</p>
            <p class="mt-1 text-xs">{{ $import->file_name }} · {{ $megabytes($import->size) }}. Sayfayı açık tutmanız gerekmez.</p>
        </div>
    @else
        @if($import?->status === 'completed')
            <p class="rounded-lg p-3 text-sm ring-1 ring-inset {{ $tone['ok'] }}" data-wa-backup-status="completed">
                Son yedek {{ $when($import->finished_at) }} çıkarıldı: {{ $importStats['chats'] ?? 0 }} görüşme ({{ $importStats['new_chats'] ?? 0 }} yeni), {{ $importStats['new_messages'] ?? 0 }} yeni mesaj.@if(($importStats['skipped_groups'] ?? 0) > 0) {{ $importStats['skipped_groups'] }} grup sohbeti alınmadı.@endif
            </p>
        @elseif($import?->status === 'failed')
            <div role="alert" class="rounded-lg p-3 text-sm ring-1 ring-inset {{ $tone['bad'] }}" data-wa-backup-status="failed">
                <p class="font-medium">Yedek çıkarılamadı</p>
                <p class="mt-1">{{ $import->error }}</p>
            </div>
        @endif

        @if($import?->status === 'uploaded')
            <form class="space-y-2" data-wa-backup-status="uploaded" @submit.prevent="extract()">
                <p class="text-sm"><strong>{{ $import->file_name }}</strong> yüklendi ({{ $megabytes($import->size) }}). <button type="button" wire:click="discardBackup" class="text-xs text-gray-500 hover:text-rose-600">Sil</button></p>
                @if($import->error)
                    <p role="alert" class="rounded-lg p-3 text-sm ring-1 ring-inset {{ $tone['bad'] }}">{{ $import->error }}</p>
                @endif
                <div class="flex flex-wrap gap-2">
                    <span wire:ignore class="min-w-0 flex-1"><input x-ref="key" type="password" autocomplete="off" spellcheck="false" aria-label="64 haneli şifreleme anahtarı" placeholder="{{ $backupKeySaved ? 'Boş bırakın: kayıtlı anahtar kullanılır' : '64 haneli şifreleme anahtarı' }}" class="w-full rounded-lg border border-gray-300 bg-transparent p-2 font-mono text-sm dark:border-gray-700" /></span>
                    <button type="submit" :disabled="extracting" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-60">Çıkar</button>
                </div>
                @error('backup_key')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
            </form>
        @else
            <div class="flex flex-wrap items-center gap-3">
                <label class="rounded-lg px-4 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:ring-gray-700 dark:hover:bg-white/[0.04]" :class="uploading && 'pointer-events-none opacity-60'">
                    <span x-text="uploading ? 'Yükleniyor… %' + progress : '{{ $hasBackup ? 'Yeni yedek seç' : 'Yedek dosyasını seç' }}'">{{ $hasBackup ? 'Yeni yedek seç' : 'Yedek dosyasını seç' }}</span>
                    <input x-ref="file" type="file" class="sr-only" accept=".crypt15,.crypt14,.crypt12" @change="upload()" />
                </label>
                @if($import?->status === 'uploading')
                    <span class="text-xs text-gray-500">Önceki yükleme yarıda kaldı (%{{ $import->size ? (int) floor($import->received_bytes * 100 / $import->size) : 0 }}); dosyayı yeniden seçin.</span>
                @endif
            </div>
            <div x-show="uploading" x-cloak class="h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800"><div class="h-full bg-brand-500 transition-all" :style="'width: ' + progress + '%'"></div></div>
        @endif
    @endif
    @if($hasBackup)
        <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 pt-3 text-sm dark:border-gray-800" data-wa-contacts>
            <label class="rounded-lg px-3 py-1.5 text-sm font-medium ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:ring-gray-700 dark:hover:bg-white/[0.04]">
                Rehberden isimleri al (.vcf)
                <input type="file" class="sr-only" accept=".vcf,text/vcard,text/x-vcard" @change="contacts($event)" />
            </label>
            <span class="text-xs text-gray-500">Yedekte kişi adları yok. Telefonda Kişiler › Ayarlar › Dışa aktar ile .vcf dosyası alıp seçin; numarası eşleşen görüşmelere isim yazılır. Rehberden yalnız ad ve numara okunur.</span>
            @error('contacts')<p class="w-full text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>
    @endif
    <p x-show="message" x-text="message" role="status" class="text-sm text-gray-600 dark:text-gray-300"></p>
    @error('backup')<p role="alert" class="text-sm text-rose-600">{{ $message }}</p>@enderror
</section>
