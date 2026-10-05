<?php

namespace App\Jobs\WhatsApp;

use App\Models\WhatsAppBackupImport;
use App\Services\Ai\AiLiveOperations;
use App\Services\WhatsApp\Backup\WhatsAppBackupImporter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Throwable;

/** Opens an uploaded WhatsApp Business backup and stores its chats (heavy queue: large files take minutes). */
class ExtractWhatsAppBackup implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 1800;

    public int $timeout = 870;

    public int $tries = 1;

    public function __construct(public string $importId, public ?int $userId = null)
    {
        $this->onConnection('redis')->onQueue('heavy');
    }

    public function uniqueId(): string
    {
        return 'wa-backup:'.$this->importId;
    }

    public function handle(WhatsAppBackupImporter $importer): void
    {
        if ($this->userId !== null) {
            // Started by the operator's "Çıkar": the brain learning that follows counts as their click.
            Context::addHidden(AiLiveOperations::USER_CONTEXT, $this->userId);
        }
        $importer->run($this->importId);
    }

    public function failed(?Throwable $exception): void
    {
        $import = WhatsAppBackupImport::query()->find($this->importId);
        if ($import !== null && in_array($import->status, ['queued', 'running'], true)) {
            $import->update(['status' => 'failed', 'backup_key' => null, 'finished_at' => now(), 'error' => 'Çıkarma işi yarıda kesildi (zaman aşımı ya da bellek). Dosyayı yeniden yükleyip deneyin; sürerse bu mesajı iletin.']);
            app(WhatsAppBackupImporter::class)->discardFiles($import);
        }
    }
}
