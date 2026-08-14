<?php

namespace App\Listeners;

use App\Events\EvidenceCanonicalized;
use App\Jobs\Async\EvaluateFindingsForAssetJob;

/**
 * Queue Finding evaluation after Evidence canonicalization. Isolated from Evidence writes.
 */
final class QueueFindingEvaluationAfterEvidenceCanonicalized
{
    public function handle(EvidenceCanonicalized $event): void
    {
        if (! config('moxdop-finding-rules.evaluate_after_canonicalization')) {
            return;
        }

        EvaluateFindingsForAssetJob::dispatch($event->asset->id);
    }
}
