<?php

namespace App\Services\Opportunities;

use App\Enums\OpportunityLifecycleAction;
use App\Models\Opportunity;
use InvalidArgumentException;

/**
 * Operator disposition for Opportunities: review/defer/dismiss/convert.
 * Never creates Recommendations, Tasks, or Service Scopes — Recommendation creation is owned
 * by a later Prompt. Detection truth (detection_state) is left untouched; only the operator's
 * status changes here.
 */
final class OpportunityDispositionService
{
    /** @var list<string> */
    private const array ALLOWED_STATUSES = [
        Opportunity::STATUS_OPEN,
        Opportunity::STATUS_REVIEWING,
        Opportunity::STATUS_DEFERRED,
        Opportunity::STATUS_CONVERTED,
        Opportunity::STATUS_DISMISSED,
    ];

    public function __construct(
        private readonly OpportunityActivityRecorder $activity,
    ) {}

    public function setStatus(Opportunity $opportunity, string $status): Opportunity
    {
        if (! in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException("Unsupported Opportunity status [{$status}].");
        }

        $opportunity->forceFill(['status' => $status])->save();

        $event = match ($status) {
            Opportunity::STATUS_REVIEWING => OpportunityActivityRecorder::ACKNOWLEDGED,
            Opportunity::STATUS_DEFERRED => OpportunityActivityRecorder::DEFERRED,
            Opportunity::STATUS_DISMISSED => OpportunityActivityRecorder::DISMISSED,
            Opportunity::STATUS_CONVERTED => OpportunityActivityRecorder::CONVERTED,
            default => null,
        };

        $asset = $opportunity->digitalAsset;
        if ($event !== null && $asset !== null) {
            $this->activity->record($asset, $opportunity, $event, OpportunityLifecycleAction::ContextChanged);
        }

        return $opportunity->fresh() ?? $opportunity;
    }

    public function review(Opportunity $opportunity): Opportunity
    {
        return $this->setStatus($opportunity, Opportunity::STATUS_REVIEWING);
    }

    public function defer(Opportunity $opportunity): Opportunity
    {
        return $this->setStatus($opportunity, Opportunity::STATUS_DEFERRED);
    }

    public function dismiss(Opportunity $opportunity): Opportunity
    {
        return $this->setStatus($opportunity, Opportunity::STATUS_DISMISSED);
    }

    /**
     * Marks the Opportunity converted without creating a Recommendation.
     * Recommendation creation from a converted Opportunity is owned by a later Prompt.
     */
    public function markConvertedWithoutRecommendation(Opportunity $opportunity): Opportunity
    {
        return $this->setStatus($opportunity, Opportunity::STATUS_CONVERTED);
    }
}
