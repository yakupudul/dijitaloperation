<?php

namespace App\Jobs;

use App\Models\ExternalWriteAction;
use App\Services\ExternalWrites\ExternalWriteService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs one approved external write (or its undo). One attempt: writes are never retried blindly.
 */
final class ExecuteExternalWriteJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $actionId, public bool $undo = false) {}

    public function handle(ExternalWriteService $service): void
    {
        $action = ExternalWriteAction::query()->find($this->actionId);
        if ($action === null) {
            return;
        }
        if ($this->undo) {
            if ($action->status === 'undoing') {
                $service->executeUndo($action);
            }

            return;
        }
        if ($action->status === 'queued') {
            $service->execute($action);
        }
    }
}
