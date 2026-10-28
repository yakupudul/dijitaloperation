@php
    // Every WhatsApp backup is a row (yakup, 2026-10-28): its key, file, Excel, new uploads; the marked row's chats are in the inbox.
    $rowMegabytes = fn (?int $bytes): string => number_format(($bytes ?? 0) / 1048576, 1, ',', '.').' MB';
@endphp
<section data-wa-backup x-data="@include('livewire.operator.whatsapp.backup-alpine')">
    <div x-data="{ adding: false, saving: false }">
        <div class="section-head">
            <h2 class="section-title">WhatsApp yedekleri</h2>
            <button type="button" class="btn-primary ml-auto" @click="adding = ! adding; $nextTick(() => adding && $refs.lineName.focus())" data-wa-backup-line-add>Yedek ekle</button>
        </div>
        <p class="-mt-1 mb-3 text-sm text-gray-500 dark:text-gray-400">Her yedek bir satır: kendi anahtarı, dosyası ve Excel'i olur. Gelen kutusunda yalnız <strong class="font-medium text-gray-700 dark:text-gray-200">Görüşmelerde göster</strong> işaretli yedeğin görüşmeleri görünür; diğerleri silinmez, bekler.</p>
        <form x-show="adding" x-cloak class="card mb-3" @submit.prevent="if (saving) return; saving = true; try { if (await $wire.createBackupLine($refs.lineName.value)) { $refs.lineName.value = ''; adding = false; } } finally { saving = false; }">
            <div class="card-row flex-wrap items-start sm:flex-nowrap">
                <div class="min-w-0 flex-1">
                    <span wire:ignore class="block"><input x-ref="lineName" type="text" maxlength="120" aria-label="Yedeğin adı" placeholder="Yedeğin adı (firma adı ya da numara)" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white" /></span>
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
            <div @class(['card', 'ring-2 ring-success-500/60' => $line->active]) wire:key="wa-backup-line-{{ $line->id }}" data-wa-backup-line="{{ $line->id }}" @if($line->active) data-wa-backup-shown @endif>
                <div class="card-row flex-wrap items-start sm:flex-nowrap">
                    <div class="min-w-0 flex-1 sm:pt-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $line->name }}
                            @if($line->active)<span class="ml-1.5 inline-flex items-center gap-1 rounded-full bg-success-50 px-2 py-0.5 text-xs font-medium text-success-700 dark:bg-success-500/15 dark:text-success-300"><span class="h-1.5 w-1.5 rounded-full bg-success-500" aria-hidden="true"></span>Görüşmelerde gösteriliyor</span>@endif
                        </p>
                        <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $lineHas || $lineChats > 0 ? $lineChats.' görüşme' : 'Henüz yedek yüklenmedi: dosyayı seçin, sonra bu yedeğin 64 haneli anahtarını girin.' }}</p>
                    </div>
                    <div class="flex w-full flex-wrap gap-2 sm:w-auto sm:shrink-0">
                        @if($line->active)
                            <button type="button" wire:click="showBackupInInbox(null)" wire:confirm="Bu yedeğin görüşmeleri gelen kutusundan kaldırılsın mı? Silinmez, burada bekler." class="btn-quiet">Görüşmelerden kaldır</button>
                        @elseif(! $lineBusy)
                            <button type="button" wire:click="showBackupInInbox({{ $line->id }})" wire:confirm="Gelen kutusunda artık &quot;{{ $line->name }}&quot; yedeğinin görüşmeleri görünsün mü? Şu an gösterilen yedek silinmez, burada bekler." class="btn" data-wa-backup-show>Görüşmelerde göster</button>
                        @endif
                        @if(! $lineBusy && $lineImport?->status !== 'uploaded')
                            <label class="btn cursor-pointer" :class="uploading && 'pointer-events-none opacity-60'">
                                <span x-text="uploading && uploadingLine === {{ $line->id }} ? 'Yükleniyor… %' + progress : '{{ $lineHas ? 'Yeni yedek dosyası ekle' : 'Yedek dosyasını seç' }}'">{{ $lineHas ? 'Yeni yedek dosyası ekle' : 'Yedek dosyasını seç' }}</span>
                                <input type="file" class="sr-only" accept=".crypt15,.crypt14,.crypt12" @change="upload($event.target, {{ $line->id }})" />
                            </label>
                        @endif
                        @if($lineChats > 0)
                            <a href="{{ route('operator.whatsapp.export', ['line' => $line->id]) }}" class="btn" data-wa-backup-excel>Excel indir</a>
                        @endif
                        @unless($lineBusy)
                            <button type="button" wire:click="deleteBackupLine({{ $line->id }})" wire:confirm="&quot;{{ $line->name }}&quot; yedeği, görüşmeleri, dosyası ve anahtarıyla silinsin mi? Bu geri alınamaz." class="btn-quiet">Sil</button>
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
                            <p class="text-sm text-gray-800 dark:text-gray-200"><strong class="font-medium">{{ $lineImport->file_name }}</strong> yüklendi ({{ $rowMegabytes($lineImport->size) }}). <button type="button" wire:click="discardBackup({{ $line->id }})" class="ml-1 text-[13px] text-gray-500 hover:text-gray-900 hover:underline dark:hover:text-white">Sil</button></p>
                            @if($lineImport->error)
                                <p role="alert" class="mt-1 text-[13px] text-error-700 dark:text-error-300">{{ $lineImport->error }}</p>
                            @endif
                            @error('backup_key.'.$line->id)<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                        </div>
                        <div class="flex w-full min-w-0 gap-2 sm:w-96 sm:shrink-0">
                            <span wire:ignore class="min-w-0 flex-1"><input type="password" autocomplete="off" spellcheck="false" aria-label="64 haneli şifreleme anahtarı" placeholder="{{ $lineKeyHint ? 'Boş bırakın: kayıtlı anahtar kullanılır' : 'Bu yedeğin 64 haneli anahtarı' }}" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 font-mono text-sm text-gray-900 placeholder:font-sans placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white" /></span>
                            <button type="submit" :disabled="extracting" class="btn-primary h-9!">Çıkar</button>
                        </div>
                    </form>
                @endif

                @include('livewire.operator.whatsapp.backup-loaded', ['loadedLine' => $line->id, 'loadedCurrent' => $lineCurrent, 'loadedKeyHint' => $lineKeyHint, 'loadedChats' => $lineChats])

                @if($lineChats > 0)
                    <div class="card-row flex-wrap items-start sm:flex-nowrap">
                        <p class="min-w-0 flex-1 text-[13px] text-gray-500 dark:text-gray-400">Yedekte kişi adları yok. O telefonun rehberini (.vcf) seçerseniz bu yedeğin görüşmelerine ve Excel'ine isimler yazılır; rehberden yalnız ad ve numara okunur.</p>
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

    <div class="card mt-3">
        <details class="group/how border-t border-gray-200 dark:border-gray-800" @if($backupLines === []) open @endif wire:ignore.self>
            <summary class="flex cursor-pointer list-none items-center gap-2 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/[0.03] [&::-webkit-details-marker]:hidden">
                <svg class="h-4 w-4 shrink-0 text-gray-400 transition group-open/how:rotate-90" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.17 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
                Yedek nasıl alınır?
            </summary>
            <div class="px-4 pb-4 text-sm">
                <ol class="list-decimal space-y-1.5 pl-5 text-gray-600 dark:text-gray-300">
                    <li>Telefonda WhatsApp Business › Ayarlar › Sohbetler › Sohbet yedeği › <strong class="font-medium text-gray-900 dark:text-white">Uçtan uca şifreli yedek</strong>'i açın ve <strong class="font-medium text-gray-900 dark:text-white">64 haneli şifreleme anahtarı</strong> seçeneğini seçin. Anahtarı bir yere yazın (parola seçeneği değil). Bu yalnız ilk sefer gerekir.</li>
                    <li><strong class="font-medium text-gray-900 dark:text-white">Yedekle</strong>'ye basın ve bitmesini bekleyin.</li>
                    <li>Dosyayı bilgisayara alın: Dahili depolama › Android › media › com.whatsapp.w4b › WhatsApp Business › Databases › <code class="text-xs">msgstore.db.crypt15</code>.</li>
                    <li>Yukarıda <strong class="font-medium text-gray-900 dark:text-white">Yedek ekle</strong> ile bir satır açın (her telefon ya da firma ayrı satır), dosyayı seçin, anahtarı girip <strong class="font-medium text-gray-900 dark:text-white">Çıkar</strong>'a basın. Doğru anahtar o satıra kaydedilir; o satırın sonraki yedeklerinde yalnız dosyayı seçersiniz.</li>
                </ol>
                <p class="mt-3 text-xs text-gray-500">Her satırın son çıkarılan dosyası (şifreli hâliyle) ve anahtarı sunucuda şifreli saklanır; yeni yedek eskisinin yerini alır. Grup sohbetleri, fotoğraf, ses ve belgelerin içeriği alınmaz. Kişi adları bu dosyada olmadığı için numaralar görünür; müşteri kaydıyla eşleşen numara müşteriye bağlanır.</p>
            </div>
        </details>
    </div>
    <p x-show="message" x-text="message" role="status" class="mt-3 text-sm text-gray-600 dark:text-gray-300"></p>
    @error('backup')<p role="alert" class="mt-3 text-sm text-error-600">{{ $message }}</p>@enderror
    @error('contacts')<p role="alert" class="mt-3 text-sm text-error-600">{{ $message }}</p>@enderror
</section>
