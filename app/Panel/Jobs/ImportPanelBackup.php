<?php

namespace App\Panel\Jobs;

use App\Panel\Services\PanelBackups;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Opens a brand's uploaded WhatsApp backup and stores its chats (heavy queue: a large file takes minutes). */
class ImportPanelBackup implements ShouldQueue
{
    use Queueable;

    public int $timeout = 870;

    public int $tries = 1;

    public function __construct(public int $importId)
    {
        $this->onConnection('redis')->onQueue('heavy');
    }

    public function handle(PanelBackups $backups): void
    {
        $backups->run($this->importId);
    }
}
