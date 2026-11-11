<?php

namespace App\Jobs\WhatsApp;

use App\Models\CoreIntegration;
use App\Models\WhatsAppBackupLine;
use App\Services\WhatsApp\Backup\WhatsAppBackupImporter;
use App\Services\WhatsApp\WhatsAppConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** "Canlı sohbetlere ekle": copies a backup's messages into the live chats of the same numbers (heavy queue). */
class FillLiveFromBackup implements ShouldQueue
{
    use Queueable;

    public int $timeout = 870;

    public int $tries = 1;

    public function __construct(public int $lineId)
    {
        $this->onConnection('redis')->onQueue('heavy');
    }

    public function handle(WhatsAppBackupImporter $importer): void
    {
        $line = WhatsAppBackupLine::query()->find($this->lineId);
        if ($line === null) {
            return;
        }
        $stats = $importer->copyToLive($line);
        $row = CoreIntegration::query()->where('provider', WhatsAppConnection::PROVIDER)->first();
        if ($row !== null) {
            $config = $row->config ?? [];
            $config['live_fill_result'][(string) $line->id] = [...$stats, 'at' => now()->toIso8601String()];
            $row->update(['config' => $config]);
        }
    }
}
