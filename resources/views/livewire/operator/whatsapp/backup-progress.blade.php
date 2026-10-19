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
<div class="space-y-1 rounded-lg p-3 text-sm ring-1 ring-inset {{ $tone['warn'] }}" data-wa-backup-status="{{ $backupImport->status }}">
    <p class="font-medium">{{ $progressTitle }}</p>
    <p class="text-xs">{{ $backupImport->file_name }} · {{ number_format(($backupImport->size ?? 0) / 1048576, 1, ',', '.') }} MB · {{ $backupImport->status === 'running' ? 'başladı' : 'sıraya alındı' }} {{ $progressSince?->timezone('Europe/Istanbul')->format('H:i') ?? '—' }}@if($backupImport->attempt > 1) · {{ $backupImport->attempt }}. deneme @endif. Bitince yeni mesajlar listeye eklenir; sayfayı açık tutmanız gerekmez.</p>
    @if($backupImport->error)
        <p class="text-xs" data-wa-backup-note>{{ $backupImport->error }}</p>
    @endif
    @if(\App\Services\WhatsApp\Backup\WhatsAppBackupImporter::canRestart($backupImport))
        <p class="text-xs">{{ $progressMinutes }} dakikadır ilerleme yok. Takılan çıkarma kendiliğinden yeniden başlatılır; beklemek istemezseniz <button type="button" wire:click="restartBackup" class="font-semibold underline">Yeniden başlat</button>.</p>
    @endif
    @error('backup')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
</div>
