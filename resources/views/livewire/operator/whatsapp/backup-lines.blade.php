@php
    // Extra WhatsApp backups (other phones or numbers): each with its own file, key and Excel, apart from the inbox.
    $lineMegabytes = fn (?int $bytes): string => number_format(($bytes ?? 0) / 1048576, 1, ',', '.').' MB';
@endphp
<section data-wa-backup-lines x-data="@include('livewire.operator.whatsapp.backup-alpine')">
    <div x-data="{ adding: false, saving: false }">
        <div class="section-head">
            <h2 class="section-title">Diğer WhatsApp yedekleri</h2>
            <button type="button" class="btn ml-auto" @click="adding = ! adding; $nextTick(() => adding && $refs.lineName.focus())" data-wa-backup-line-add>Başka yedek ekle</button>
        </div>
        <p class="-mt-1 mb-3 text-sm text-gray-500 dark:text-gray-400">Başka bir telefonun ya da numaranın yedeğini kendi anahtarıyla ekleyin. Yukarıdaki yedek ve anahtar olduğu gibi kalır; bu yedeklerin görüşmeleri gelen kutusuna ve beyne karışmaz, her biri ayrı Excel olarak iner.</p>
        <form x-show="adding" x-cloak class="card mb-3" @submit.prevent="if (saving) return; saving = true; try { if (await $wire.createBackupLine($refs.lineName.value)) { $refs.lineName.value = ''; adding = false; } } finally { saving = false; }">
            <div class="card-row flex-wrap items-start sm:flex-nowrap">
                <div class="min-w-0 flex-1">
                    <span wire:ignore class="block"><input x-ref="lineName" type="text" maxlength="120" aria-label="Yedeğin adı" placeholder="Yedeğin adı (örneğin 0532 hattı ya da işletme adı)" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white" /></span>
                    @error('line_name')<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                </div>
                <button type="submit" :disabled="saving" class="btn-primary h-9! w-full sm:w-auto">Ekle</button>
            </div>
        </form>
    </div>

    <div class="space-y-3">
        @foreach($backupLines as ['line' => $line, 'import' => $lineImport, 'current' => $lineCurrent, 'key_hint' => $lineKeyHint, 'conversations' => $lineChats])
            @php
                $lineBusy = $lineImport?->isBusy() ?? false;
                $lineStats = $lineImport?->stats ?? [];
                $lineHas = $lineCurrent['import'] !== null;
            @endphp
            <div class="card" wire:key="wa-backup-line-{{ $line->id }}" data-wa-backup-line="{{ $line->id }}">
                <div class="card-row flex-wrap items-start sm:flex-nowrap">
                    <div class="min-w-0 flex-1 sm:pt-1.5">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $line->name }}</p>
                        <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $lineHas ? $lineChats.' görüşme' : 'Henüz yedek yüklenmedi: msgstore.db.crypt15 dosyasını seçin, sonra o telefonun 64 haneli anahtarını girin.' }}</p>
                    </div>
                    <div class="flex w-full flex-wrap gap-2 sm:w-auto sm:shrink-0">
                        @if(! $lineBusy && $lineImport?->status !== 'uploaded')
                            <label class="btn-primary cursor-pointer" :class="uploading && 'pointer-events-none opacity-60'">
                                <span x-text="uploading && uploadingLine === {{ $line->id }} ? 'Yükleniyor… %' + progress : '{{ $lineHas ? 'Yeni yedek seç' : 'Yedek dosyasını seç' }}'">{{ $lineHas ? 'Yeni yedek seç' : 'Yedek dosyasını seç' }}</span>
                                <input type="file" class="sr-only" accept=".crypt15,.crypt14,.crypt12" @change="upload($event.target, {{ $line->id }})" />
                            </label>
                        @endif
                        @unless($lineBusy)
                            <button type="button" wire:click="deleteBackupLine({{ $line->id }})" wire:confirm="&quot;{{ $line->name }}&quot; yedeği, görüşmeleri, dosyası ve anahtarıyla silinsin mi? Gelen kutusundaki yedek etkilenmez." class="btn-quiet">Sil</button>
                        @endunless
                    </div>
                </div>
                <div x-show="uploading && uploadingLine === {{ $line->id }}" x-cloak class="border-t border-gray-200 px-4 py-3 dark:border-gray-800"><div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800"><div class="h-full bg-gray-900 transition-all dark:bg-white" :style="'width: ' + progress + '%'"></div></div></div>

                @if($lineImport?->status === 'uploading')
                    <p class="card-row text-[13px] text-gray-500">Önceki yükleme yarıda kaldı (%{{ $lineImport->size ? (int) floor($lineImport->received_bytes * 100 / $lineImport->size) : 0 }}); dosyayı yeniden seçin.</p>
                @elseif($lineBusy)
                    <div class="border-t border-gray-200 p-3 dark:border-gray-800">@include('livewire.operator.whatsapp.backup-progress', ['backupImport' => $lineImport, 'backupQueueAhead' => 0])</div>
                @elseif($lineImport?->status === 'failed')
                    <div role="alert" class="card-row items-start" data-wa-backup-status="failed">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-error-500" aria-hidden="true"></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">Yedek çıkarılamadı</p>
                            <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $lineImport->error }}</p>
                        </div>
                    </div>
                @elseif($lineImport?->status === 'completed')
                    <div class="card-row items-start" data-wa-backup-status="completed">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-success-500" aria-hidden="true"></span>
                        <p class="min-w-0 flex-1 text-[13px] text-gray-600 dark:text-gray-300">Son yedek {{ $when($lineImport->finished_at) }} çıkarıldı: {{ $lineStats['chats'] ?? 0 }} görüşme ({{ $lineStats['new_chats'] ?? 0 }} yeni), {{ $lineStats['new_messages'] ?? 0 }} yeni mesaj.@if(($lineStats['skipped_groups'] ?? 0) > 0) {{ $lineStats['skipped_groups'] }} grup sohbeti alınmadı.@endif</p>
                    </div>
                @elseif($lineImport?->status === 'uploaded')
                    <form class="card-row flex-wrap items-start sm:flex-nowrap" data-wa-backup-status="uploaded" @submit.prevent="extract($el.querySelector('input'), {{ $line->id }})">
                        <div class="min-w-0 flex-1 sm:pt-1.5">
                            <p class="text-sm text-gray-800 dark:text-gray-200"><strong class="font-medium">{{ $lineImport->file_name }}</strong> yüklendi ({{ $lineMegabytes($lineImport->size) }}). <button type="button" wire:click="discardBackup({{ $line->id }})" class="ml-1 text-[13px] text-gray-500 hover:text-gray-900 hover:underline dark:hover:text-white">Sil</button></p>
                            @if($lineImport->error)
                                <p role="alert" class="mt-1 text-[13px] text-error-700 dark:text-error-300">{{ $lineImport->error }}</p>
                            @endif
                            @error('backup_key.'.$line->id)<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                        </div>
                        <div class="flex w-full min-w-0 gap-2 sm:w-96 sm:shrink-0">
                            <span wire:ignore class="min-w-0 flex-1"><input type="password" autocomplete="off" spellcheck="false" aria-label="64 haneli şifreleme anahtarı" placeholder="{{ $lineKeyHint ? 'Boş bırakın: kayıtlı anahtar kullanılır' : '64 haneli şifreleme anahtarı' }}" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 font-mono text-sm text-gray-900 placeholder:font-sans placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white" /></span>
                            <button type="submit" :disabled="extracting" class="btn-primary h-9!">Çıkar</button>
                        </div>
                    </form>
                @endif

                @include('livewire.operator.whatsapp.backup-loaded', ['loadedLine' => $line->id, 'loadedCurrent' => $lineCurrent, 'loadedKeyHint' => $lineKeyHint, 'loadedChats' => $lineChats])

                @if($lineChats > 0)
                    <div class="card-row flex-wrap items-start sm:flex-nowrap">
                        <p class="min-w-0 flex-1 text-[13px] text-gray-500 dark:text-gray-400">İsimler için o telefonun rehberini (.vcf) seçin; Excel'deki Kişi sütunu dolar.</p>
                        <label class="btn w-full cursor-pointer sm:w-auto">
                            Rehber dosyası seç (.vcf)
                            <input type="file" class="sr-only" accept=".vcf,text/vcard,text/x-vcard" @change="contacts($event, {{ $line->id }})" />
                        </label>
                    </div>
                @endif
                @error('backup.'.$line->id)<p role="alert" class="card-row text-sm text-error-600">{{ $message }}</p>@enderror
            </div>
        @endforeach
    </div>
    <p x-show="message" x-text="message" role="status" class="mt-3 text-sm text-gray-600 dark:text-gray-300"></p>
    @error('contacts')<p role="alert" class="mt-3 text-sm text-error-600">{{ $message }}</p>@enderror
</section>
