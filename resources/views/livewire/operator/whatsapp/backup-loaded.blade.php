@php
    // The backup file and key now loaded for one backup ($loadedLine: an extra backup's id, null for the inbox's own).
    $loadedImport = $loadedCurrent['import'];
    $loadedParams = $loadedLine === null ? [] : ['line' => $loadedLine];
    $loadedSlot = (string) ($loadedLine ?? 'inbox');
    $loadedArg = $loadedLine === null ? 'null' : (string) $loadedLine;
    $loadedSnapshot = $loadedImport?->stats['snapshot_at'] ?? null;
@endphp
@if($loadedImport)
    <div class="card-row flex-wrap items-start sm:flex-nowrap" data-wa-backup-file>
        <div class="min-w-0 flex-1">
            <p class="text-sm text-gray-800 dark:text-gray-200">Yüklü yedek: <strong class="font-medium">{{ $loadedImport->file_name }}</strong></p>
            <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ number_format(($loadedImport->size ?? 0) / 1048576, 1, ',', '.') }} MB, {{ $when($loadedImport->finished_at) }} çıkarıldı{{ $loadedSnapshot ? ', yedekteki son mesaj '.$when($loadedSnapshot) : '' }}, {{ $loadedChats }} görüşme.@unless($loadedCurrent['file_kept']) Dosyanın kendisi sunucuda tutulmamış (bu yedek, dosya saklanmaya başlamadan önce çıkarıldı); bir sonraki yedekte saklanır.@endunless</p>
        </div>
        <div class="flex w-full flex-wrap gap-2 sm:w-auto sm:shrink-0">
            @if($loadedCurrent['file_kept'])
                <a href="{{ route('operator.whatsapp.backup.file', $loadedParams) }}" class="btn" data-wa-backup-file-download>Dosyayı indir</a>
            @endif
            @if($loadedChats > 0)
                <a href="{{ route('operator.whatsapp.export', $loadedParams) }}" class="btn" data-wa-backup-excel>{{ $loadedLine === null ? 'Tüm görüşmeler (Excel)' : 'Excel indir' }}</a>
            @endif
        </div>
    </div>
@endif
@if($loadedKeyHint)
    <div class="card-row flex-wrap items-start sm:flex-nowrap" data-wa-backup-key-saved>
        <div class="min-w-0 flex-1">
            <p class="text-[13px] text-gray-500 dark:text-gray-400">Anahtar kayıtlı: yeni yedekte yalnız dosyayı seçin.</p>
            <p class="mt-1 break-all font-mono text-sm text-gray-900 dark:text-white" x-text="keys['{{ $loadedSlot }}'] ?? '{{ $loadedKeyHint }}'">{{ $loadedKeyHint }}</p>
        </div>
        <div class="flex w-full flex-wrap gap-1 sm:w-auto sm:shrink-0">
            <button type="button" class="btn-quiet" @click="reveal({{ $loadedArg }})" x-text="keys['{{ $loadedSlot }}'] ? 'Gizle' : 'Göster'">Göster</button>
            <button type="button" class="btn-quiet" @click="copyKey({{ $loadedArg }})" x-text="String(copied) === '{{ $loadedSlot }}' ? 'Kopyalandı' : 'Kopyala'">Kopyala</button>
            <a href="{{ route('operator.whatsapp.backup.key', $loadedParams) }}" class="btn-quiet">İndir</a>
            <button type="button" wire:click="forgetBackupKey({{ $loadedArg }})" wire:confirm="Kayıtlı anahtar silinsin mi? Sonraki yedekte yeniden sorulur." class="btn-quiet">Kayıtlı anahtarı sil</button>
        </div>
    </div>
@endif
