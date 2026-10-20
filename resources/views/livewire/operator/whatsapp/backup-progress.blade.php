@php
    /** @var \App\Models\WhatsAppBackupImport $backupImport */
    $progressStats = $backupImport->stats ?? [];
    $progressPhase = $progressStats['phase'] ?? null;
    $progressSince = $backupImport->status === 'running' ? $backupImport->started_at : ($backupImport->queued_at ?? $backupImport->updated_at);
    $progressMinutes = $progressSince ? (int) $progressSince->diffInMinutes(now()) : 0;
    $progressTitle = match (true) {
        $backupImport->status === 'queued' => 'Çıkarma sırada bekliyor ('.$progressMinutes.' dk)'.(($backupQueueAhead ?? 0) > 0 ? '; önünde '.$backupQueueAhead.' arka plan işi var' : ''),
        $progressPhase === 'import' => 'Görüşmeler aktarılıyor: '.($progressStats['chats'] ?? 0).' görüşme, '.($progressStats['new_messages'] ?? 0).' yeni mesaj',
        default => 'Yedek açılıyor (şifre çözülüyor), '.$progressMinutes.' dk',
    };
@endphp
<div class="card" data-wa-backup-status="{{ $backupImport->status }}">
    <div class="card-row items-start">
        <span class="mt-1.5 h-2 w-2 shrink-0 animate-pulse rounded-full bg-warning-500" aria-hidden="true"></span>
        <div class="min-w-0 flex-1">
            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $progressTitle }}</p>
            <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $backupImport->file_name }}, {{ number_format(($backupImport->size ?? 0) / 1048576, 1, ',', '.') }} MB, {{ $backupImport->status === 'running' ? 'başladı' : 'sıraya alındı' }} {{ $progressSince?->timezone('Europe/Istanbul')->format('H:i') ?? '—' }}@if($backupImport->attempt > 1), {{ $backupImport->attempt }}. deneme @endif. Bitince yeni mesajlar listeye eklenir; sayfayı açık tutmanız gerekmez.</p>
            @if($backupImport->error)
                <p class="mt-1 text-[13px] text-warning-800 dark:text-warning-300" data-wa-backup-note>{{ $backupImport->error }}</p>
            @endif
            @if(\App\Services\WhatsApp\Backup\WhatsAppBackupImporter::canRestart($backupImport))
                <p class="mt-1 text-[13px] text-gray-600 dark:text-gray-300">{{ $progressMinutes }} dakikadır ilerleme yok. Takılan çıkarma kendiliğinden yeniden başlatılır; beklemek istemezseniz yeniden başlatın.</p>
            @endif
            @error('backup')<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
        </div>
        @if(\App\Services\WhatsApp\Backup\WhatsAppBackupImporter::canRestart($backupImport))
            <button type="button" wire:click="restartBackup" class="btn">Yeniden başlat</button>
        @endif
    </div>
</div>
