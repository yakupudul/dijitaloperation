@php
    // Shared by the inbox's own backup and the extra backups: upload in pieces, extract, read names, show the key.
    $uploadUrls = [
        'begin' => route('operator.whatsapp.backup'),
        'chunk' => route('operator.whatsapp.backup.chunk', ['import' => '00000000-0000-0000-0000-000000000000']),
    ];
@endphp
{
        urls: @js($uploadUrls), progress: 0, uploading: false, uploadingLine: null, message: '', extracting: false, keys: {}, copied: null,
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
        async upload(input, line = null) {
            const file = input.files[0];
            if (!file || this.uploading) return;
            this.uploading = true; this.uploadingLine = line; this.message = ''; this.progress = 0;
            try {
                const started = await this.send(this.urls.begin, JSON.stringify({ name: file.name, size: file.size, line }), 'application/json');
                const url = this.urls.chunk.replace('00000000-0000-0000-0000-000000000000', started.id);
                let offset = 0, answer = {};
                while (offset < file.size) {
                    answer = await this.send(url + '?offset=' + offset, file.slice(offset, offset + started.chunk), 'application/octet-stream');
                    offset = answer.expected_offset ?? answer.received;
                    this.progress = Math.floor(offset * 100 / file.size);
                }
                this.message = answer.extracting ? 'Yüklendi; kayıtlı anahtarla çıkarılıyor.' : 'Yüklendi. Şimdi 64 haneli anahtarı girip Çıkar\'a basın.';
                input.value = '';
                await $wire.$refresh();
            } catch (error) { this.message = error.message; }
            finally { this.uploading = false; this.uploadingLine = null; }
        },
        async contacts(event, line = null) {
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
                await $wire.importContacts(kept.join('\n'), line);
            } catch (error) { this.message = 'Rehber okunamadı: ' + error.message; }
            finally { event.target.value = ''; }
        },
        async extract(input, line = null) {
            if (this.extracting) return;
            this.extracting = true;
            try { if (await $wire.extractBackup(input.value, line)) input.value = ''; }
            finally { this.extracting = false; }
        },
        async reveal(line = null) {
            const slot = line ?? 'inbox';
            if (this.keys[slot]) { delete this.keys[slot]; return; }
            this.keys[slot] = (await $wire.revealBackupKey(line)) || 'Kayıtlı anahtar yok';
        },
        async copyKey(line = null) {
            const key = (await $wire.revealBackupKey(line)).replaceAll(' ', '');
            if (!key) return;
            await navigator.clipboard.writeText(key);
            this.copied = line ?? 'inbox';
            setTimeout(() => { this.copied = null; }, 2000);
        },
    }
