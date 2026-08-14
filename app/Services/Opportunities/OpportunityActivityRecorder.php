<?php

namespace App\Services\Opportunities;

use App\Enums\OpportunityLifecycleAction;
use App\Models\BrandContextActivity;
use App\Models\DigitalAsset;
use App\Models\Opportunity;
use App\Support\Opportunities\OpportunityRule;
use Illuminate\Support\Carbon;

/**
 * Meaningful Opportunity Activity only. Reconfirmation does not spam the timeline.
 */
final class OpportunityActivityRecorder
{
    public const string CREATED = 'OPPORTUNITY_CREATED';

    public const string CLOSED = 'OPPORTUNITY_CLOSED';

    public const string REOPENED = 'OPPORTUNITY_REOPENED';

    public const string ACKNOWLEDGED = 'OPPORTUNITY_ACKNOWLEDGED';

    public const string DEFERRED = 'OPPORTUNITY_DEFERRED';

    public const string DISMISSED = 'OPPORTUNITY_DISMISSED';

    public const string CONVERTED = 'OPPORTUNITY_CONVERTED';

    public const string CONTEXT_CHANGED = 'OPPORTUNITY_CONTEXT_CHANGED';

    public function record(
        DigitalAsset $asset,
        Opportunity $opportunity,
        string $event,
        OpportunityLifecycleAction $action,
        ?OpportunityRule $rule = null,
    ): ?BrandContextActivity {
        if ($asset->brand_id === null) {
            return null;
        }

        if (! in_array($event, [
            self::CREATED,
            self::CLOSED,
            self::REOPENED,
            self::ACKNOWLEDGED,
            self::DEFERRED,
            self::DISMISSED,
            self::CONVERTED,
            self::CONTEXT_CHANGED,
        ], true)) {
            return null;
        }

        return BrandContextActivity::query()->create([
            'brand_id' => $asset->brand_id,
            'actor_user_id' => null,
            'event' => $event,
            'subject_type' => Opportunity::class,
            'subject_id' => $opportunity->id,
            'payload' => [
                'opportunity_id' => $opportunity->id,
                'rule_id' => $rule?->stableId ?? $opportunity->rule_id,
                'rule_version' => $rule?->version ?? $opportunity->rule_version,
                'lifecycle_action' => $action->value,
                'status' => $opportunity->status,
                'category' => $opportunity->category,
                'origin' => $opportunity->origin,
                'generated_by_ai' => false,
            ],
            'created_at' => Carbon::now(),
        ]);
    }
}
