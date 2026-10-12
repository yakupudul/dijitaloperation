<?php

namespace App\Jobs\WhatsApp;

use App\Models\WhatsAppBackupImport;
use App\Services\Ai\AiLiveOperations;
use App\Services\WhatsApp\Backup\WhatsAppBackupImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * Opens an uploaded WhatsApp Business backup and stores its chats (heavy queue: large files take minutes). Not unique:
 * the importer claims the import for one attempt, so a job queued again after a stall never runs twice.
 */
class ExtractWhatsAppBackup implements ShouldQueue
{
    use Queueable;

    public int $timeout = 870;

    public int $tries = 1;

    /** The import's attempt this job belongs to (null on jobs queued before attempts were counted). */
    public ?int $attempt = null;

    public function __construct(public string $importId, public ?int $userId = null, ?int $attempt = null)
    {
        $this->attempt = $attempt;
        $this->onConnection('redis')->onQueue('heavy');
    }

    public function handle(WhatsAppBackupImporter $importer): void
    {
        if ($this->userId !== null) {
            // Started by the operator's "Çıkar": the brain learning that follows counts as their click.
            Context::addHidden(AiLiveOperations::USER_CONTEXT, $this->userId);
        }
        $importer->run($this->importId, $this->attempt);
    }

    /** Cut off (time limit, worker lost): the importer starts a new attempt, or fails the import with the reason. */
    public function failed(?Throwable $exception): void
    {
        $import = WhatsAppBackupImport::query()->find($this->importId);
        if ($import !== null && $import->isBusy() && ($this->attempt === null || $this->attempt === $import->attempt)) {
            app(WhatsAppBackupImporter::class)->recoverInterrupted($import);
        }
    }
}
