@php
    // "Yedekten Excel al": file + key → that backup's own Excel; nothing goes into the inbox.
    $excelBusy = $excelImport?->isBusy() ?? false;
    $sourceLabel = fn (\App\Models\WhatsAppBackupImport $row): string => $row->excelOnly() ? 'Yalnız Excel' : ($row->line?->name ?? 'Gelen kutusu yedeği');
@endphp
<section data-wa-backup-excel x-data="@include('livewire.operator.whatsapp.backup-alpine')">
    <div class="section-head"><h2 class="section-title">Yedekten Excel al</h2></div>
    <div class="card">
        <div class="card-row flex-wrap items-start sm:flex-nowrap">
            <p class="min-w-0 flex-1 text-sm text-gray-700 sm:pt-1.5 dark:text-gray-300">Yedek dosyasını (msgstore.db.crypt15) seç, o yedeğin 64 haneli anahtarını gir, Excel'i indir. Excel'de yalnız o dosyadaki sohbetler olur; başka yedekle karışmaz, gelen kutusuna da eklenmez.</p>
            @if(! $excelBusy && $excelImport?->status !== 'uploaded')
                <label class="btn-primary w-full cursor-pointer sm:w-auto" :class="uploading && 'pointer-events-none opacity-60'">
                    <span x-text="uploading && uploadingLine === 'excel' ? 'Yükleniyor… %' + progress : 'Yedek dosyasını seç'">Yedek dosyasını seç</span>
                    <input type="file" class="sr-only" accept=".crypt15,.crypt14,.crypt12" @change="upload($event.target, null, true)" />
                </label>
            @endif
        </div>
        <div x-show="uploading && uploadingLine === 'excel'" x-cloak class="border-t border-gray-200 px-4 py-3 dark:border-gray-800"><div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800"><div class="h-full bg-gray-900 transition-all dark:bg-white" :style="'width: ' + progress + '%'"></div></div></div>

        @if($excelBusy)
            <div class="border-t border-gray-200 p-3 dark:border-gray-800">@include('livewire.operator.whatsapp.backup-progress', ['backupImport' => $excelImport, 'backupQueueAhead' => 0])</div>
        @elseif($excelImport?->status === 'uploading')
            <p class="card-row text-[13px] text-gray-500">Önceki yükleme yarıda kaldı; dosyayı yeniden seçin.</p>
        @elseif($excelImport?->status === 'failed' && $excelImport->updated_at?->greaterThan(now()->subDay()))
            <div role="alert" class="card-row items-start" data-wa-backup-status="failed">
                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-error-500" aria-hidden="true"></span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">Excel hazırlanamadı</p>
                    <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $excelImport->error }}</p>
                </div>
            </div>
        @elseif($excelImport?->status === 'uploaded')
            <form class="card-row flex-wrap items-start sm:flex-nowrap" data-wa-backup-status="uploaded" @submit.prevent="extractExcel($el.querySelector('input'))">
                <div class="min-w-0 flex-1 sm:pt-1.5">
                    <p class="text-sm text-gray-800 dark:text-gray-200"><strong class="font-medium">{{ $excelImport->file_name }}</strong> yüklendi ({{ number_format(($excelImport->size ?? 0) / 1048576, 1, ',', '.') }} MB). <button type="button" wire:click="discardExcelUpload" class="ml-1 text-[13px] text-gray-500 hover:text-gray-900 hover:underline dark:hover:text-white">Sil</button></p>
                    @if($excelImport->error)
                        <p role="alert" class="mt-1 text-[13px] text-error-700 dark:text-error-300">{{ $excelImport->error }}</p>
                    @endif
                    @error('backup_key.excel')<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                </div>
                <div class="flex w-full min-w-0 gap-2 sm:w-[28rem] sm:shrink-0">
                    <span wire:ignore class="min-w-0 flex-1"><input type="password" autocomplete="off" spellcheck="false" aria-label="64 haneli şifreleme anahtarı" placeholder="Bu yedeğin 64 haneli anahtarı" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 font-mono text-sm text-gray-900 placeholder:font-sans placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white" /></span>
                    <button type="submit" :disabled="extracting" class="btn-primary h-9!">Excel hazırla</button>
                </div>
            </form>
        @endif

        @foreach($backupExcels as $excelRow)
            <div class="card-row flex-wrap items-start sm:flex-nowrap" wire:key="wa-backup-excel-{{ $excelRow->id }}" data-wa-backup-excel-row>
                <div class="min-w-0 flex-1">
                    <p class="text-sm text-gray-800 dark:text-gray-200"><strong class="font-medium">{{ $excelRow->file_name }}</strong> <span class="text-[13px] text-gray-500">· {{ $sourceLabel($excelRow) }}</span></p>
                    <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $when($excelRow->finished_at) }} hazırlandı, {{ $excelRow->stats['chats'] ?? 0 }} görüşme, {{ number_format((int) ($excelRow->stats['messages'] ?? 0), 0, ',', '.') }} mesaj{{ filled($excelRow->stats['snapshot_at'] ?? null) ? ', son mesaj '.$when($excelRow->stats['snapshot_at']) : '' }}.</p>
                </div>
                <div class="flex w-full flex-wrap gap-2 sm:w-auto sm:shrink-0">
                    <a href="{{ route('operator.whatsapp.backup.excel', ['import' => $excelRow->id]) }}" class="btn-primary">Excel indir</a>
                    <button type="button" wire:click="deleteBackupExcel('{{ $excelRow->id }}')" wire:confirm="Bu Excel silinsin mi?" class="btn-quiet">Sil</button>
                </div>
            </div>
        @endforeach
    </div>
    <p x-show="message" x-text="message" role="status" class="mt-3 text-sm text-gray-600 dark:text-gray-300"></p>
</section>
