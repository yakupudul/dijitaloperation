<?php

namespace App\Panel\Jobs;

use App\Panel\Models\PanelDocument;
use App\Panel\Services\PanelDrive;
use App\Panel\Support\PanelContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Copies one shared file to the brand's Drive folder a moment after it was uploaded. */
class PushPanelDocumentToDrive implements ShouldQueue
{
    use Queueable;

    public int $timeout = 170;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(public int $documentId)
    {
        $this->onConnection('redis')->onQueue('default');
    }

    public function handle(PanelDrive $drive, PanelContext $context): void
    {
        $document = $context->system(fn () => PanelDocument::query()->find($this->documentId));
        if ($document !== null) {
            $drive->push($document);
        }
    }
}
