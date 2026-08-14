<?php

namespace App\Services\Findings;

use App\Enums\FindingLifecycleAction;
use App\Models\BrandContextActivity;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Support\Findings\FindingRule;
use Illuminate\Support\Carbon;

/**
 * Meaningful Finding Activity only. Reconfirmation does not spam the timeline.
 */
final class FindingActivityRecorder
{
    public const string CREATED = 'FINDING_CREATED';

    public const string SEVERITY_CHANGED = 'FINDING_SEVERITY_CHANGED';

    public const string RESOLVED = 'FINDING_RESOLVED';

    public const string REOPENED = 'FINDING_REOPENED';

    public const string ACKNOWLEDGED = 'FINDING_ACKNOWLEDGED';

    public function record(
        DigitalAsset $asset,
        Finding $finding,
        string $event,
        FindingLifecycleAction $action,
        FindingRule $rule,
    ): ?BrandContextActivity {
        if ($asset->brand_id === null) {
            return null;
        }

        if (! in_array($event, [self::CREATED, self::SEVERITY_CHANGED, self::RESOLVED, self::REOPENED, self::ACKNOWLEDGED], true)) {
            return null;
        }

        return BrandContextActivity::query()->create([
            'brand_id' => $asset->brand_id,
            'actor_user_id' => null,
            'event' => $event,
            'subject_type' => Finding::class,
            'subject_id' => $finding->id,
            'payload' => [
                'finding_id' => $finding->id,
                'rule_id' => $rule->stableId,
                'rule_version' => $rule->version,
                'lifecycle_action' => $action->value,
                'status' => $finding->status,
                'severity' => $finding->severity,
                'origin' => $finding->origin,
                'generated_by_ai' => false,
            ],
            'created_at' => Carbon::now(),
        ]);
    }
}
