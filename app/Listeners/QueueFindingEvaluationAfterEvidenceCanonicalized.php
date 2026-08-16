<?php

namespace App\Listeners;

use App\Events\EvidenceCanonicalized;
use App\Jobs\Async\EvaluateFindingsForAssetJob;
use App\Services\IntelligenceScheduling\ScheduleIntelligenceFromEvidenceService;

/**
 * Prompt 63: Evidence canonicalization → durable intelligence trigger → bounded analyzers.
 * Replaces blind "evaluate all findings" with dependency-aware scheduling.
 * CollectionRun completion alone never reaches this path.
 */
final class QueueFindingEvaluationAfterEvidenceCanonicalized
{
    public function __construct(
        private readonly ScheduleIntelligenceFromEvidenceService $scheduler,
    ) {}

    public function handle(EvidenceCanonicalized $event): void
    {
        if (! config('moxdop-intelligence-scheduling.enabled', true)) {
            // Legacy Prompt39 path when intelligence scheduling is disabled.
            if (config('moxdop-finding-rules.evaluate_after_canonicalization')) {
                EvaluateFindingsForAssetJob::dispatch($event->asset->id);
            }

            return;
        }

        $this->scheduler->handleEvidenceCanonicalized($event->asset, $event->run);
    }
}
