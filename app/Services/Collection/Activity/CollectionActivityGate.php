<?php

namespace App\Services\Collection\Activity;

use App\Enums\Collection\ActivityTier;
use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\CoreExternalResource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The activity gate every collection planner consults (Google Ads / GA4 / Search Console central planners,
 * the bound Meta due query, and resource automation admission):
 *
 * - which datasets an account may collect in this pass (full / light / check by activity tier),
 * - whether an idle / dormant account is due for its weekly pass,
 * - whether structure snapshots may be re-collected (provider change signal, weekly safety net),
 * - and records each planning pass (planned vs skipped datasets) for the savings metric.
 */
class CollectionActivityGate
{
    /** @var array<int, array{due: bool, reason: string}> */
    private array $structureMemo = [];

    public function __construct(
        private readonly ActivityTierService $tiers,
        private readonly StructureChangeDetector $changes,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('moxdop-collection-activity.enabled', true) && $this->tiers->ready();
    }

    public function plan(CoreExternalResource $resource): ActivityCollectionPlan
    {
        $provider = $this->tiers->providerFor((string) $resource->resource_type) ?? strtoupper((string) $resource->resource_type);
        $light = (array) config('moxdop-collection-activity.light_families.'.$provider, []);
        $checkDays = max(1, (int) config('moxdop-collection-activity.check_days', 7));
        $row = $this->enabled() ? $this->tiers->ensure($resource) : null;
        if ($row === null) {
            return new ActivityCollectionPlan((int) $resource->id, $provider, ActivityTier::Active, ActivityCollectionPlan::MODE_FULL,
                true, false, null, $checkDays, $light);
        }

        $tier = $row->effectiveTier();
        $mode = match ($tier) {
            ActivityTier::Active => ActivityCollectionPlan::MODE_FULL,
            ActivityTier::Idle => ActivityCollectionPlan::MODE_LIGHT,
            ActivityTier::Dormant => ActivityCollectionPlan::MODE_CHECK,
        };
        $interval = max(1, (int) config('moxdop-collection-activity.light_interval_days', 7));
        $nextDue = $mode === ActivityCollectionPlan::MODE_FULL || $row->last_light_check_at === null
            ? null
            : $row->last_light_check_at->addDays($interval);
        $due = $nextDue === null || ! $nextDue->isFuture();

        return new ActivityCollectionPlan(
            externalResourceId: (int) $resource->id,
            provider: $provider,
            tier: $tier,
            mode: $mode,
            due: $due,
            paused: $row->isPaused(),
            backfillFrom: $tier === ActivityTier::Active ? $row->backfill_from?->toDateString() : null,
            checkDays: $checkDays,
            lightFamilies: $light,
            nextDueAt: $nextDue?->toIso8601String(),
        );
    }

    public function isStructureFamily(string $provider, string $familyId): bool
    {
        return in_array($familyId, (array) config('moxdop-collection-activity.structure_families.'.$provider, []), true);
    }

    /**
     * Structure snapshots are re-collected when never collected, when the weekly safety net is reached, or when the
     * provider reports a change since the last successful structure collection (unknown → collect).
     *
     * @return array{due: bool, reason: string}
     */
    public function structureDecision(CoreExternalResource $resource, string $provider): array
    {
        if (! $this->enabled()) {
            return ['due' => true, 'reason' => 'activity_gate_disabled'];
        }
        if (isset($this->structureMemo[(int) $resource->id])) {
            return $this->structureMemo[(int) $resource->id];
        }
        $families = (array) config('moxdop-collection-activity.structure_families.'.$provider, []);
        $last = CollectionDatasetRun::query()
            ->whereIn('request_family_id', $families)
            ->where('status', CollectionRunStatus::Completed->value)
            ->whereHas('resourceRun', fn ($q) => $q->where('external_resource_id', $resource->id))
            ->max('finished_at');
        $safetyDays = max(1, (int) config('moxdop-collection-activity.structure_safety_net_days', 7));

        if ($last === null) {
            $decision = ['due' => true, 'reason' => 'never_collected'];
        } else {
            $lastAt = CarbonImmutable::parse((string) $last);
            if ($lastAt->lessThanOrEqualTo(now()->subDays($safetyDays))) {
                $decision = ['due' => true, 'reason' => 'weekly_safety_net'];
            } else {
                $changed = $this->changes->changedSince($resource, $provider, $lastAt);
                $decision = match ($changed) {
                    true => ['due' => true, 'reason' => 'provider_change'],
                    false => ['due' => false, 'reason' => 'unchanged'],
                    null => ['due' => true, 'reason' => 'change_check_unavailable'],
                };
            }
        }

        return $this->structureMemo[(int) $resource->id] = $decision;
    }

    /**
     * Logs one planning pass for this account: how many datasets were planned and how many the activity gate
     * avoided. Planning does not move the weekly clock of idle / dormant accounts: a pass that fails must be retried
     * soon, so the clock starts only when the light / check collection succeeded (markLightCheck).
     *
     * @param  array<string, mixed>  $detail
     */
    public function recordPass(ActivityCollectionPlan $plan, int $planned, int $skipped, array $detail = []): void
    {
        if (! $this->enabled()) {
            return;
        }
        DB::table('collection_activity_passes')->insert([
            'external_resource_id' => $plan->externalResourceId,
            'provider' => $plan->provider,
            'tier' => $plan->tier->value,
            'mode' => $plan->mode,
            'planned_datasets' => max(0, $planned),
            'skipped_datasets' => max(0, $skipped),
            'detail' => json_encode($detail, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
        Log::info(sprintf(
            'collection.activity.pass resource=%d provider=%s tier=%s mode=%s planned=%d skipped=%d',
            $plan->externalResourceId, $plan->provider, $plan->tier->value, $plan->mode, $planned, $skipped,
        ), $detail);
    }

    /** A light / check collection of an idle / dormant account succeeded: its next weekly pass is due in a week. */
    public function markLightCheck(int $externalResourceId): void
    {
        if ($this->enabled()) {
            DB::table('resource_activity')->where('external_resource_id', $externalResourceId)
                ->update(['last_light_check_at' => now(), 'updated_at' => now()]);
        }
    }

    /** "Şimdi güncelle": the next admission collects an idle / dormant account without waiting for its weekly pass. */
    public function resetLightCheck(int $externalResourceId): void
    {
        if ($this->enabled()) {
            DB::table('resource_activity')->where('external_resource_id', $externalResourceId)
                ->whereNotNull('last_light_check_at')
                ->update(['last_light_check_at' => null, 'updated_at' => now()]);
        }
    }

    /** A Google Ads account gets the deep (late conversion) restatement window at most weekly. */
    public function deepRestatementDue(int $externalResourceId): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        $last = DB::table('resource_activity')->where('external_resource_id', $externalResourceId)->value('last_deep_restatement_at');
        $interval = max(1, (int) config('moxdop-collection-activity.deep_restatement_interval_days', 7));

        return $last === null || CarbonImmutable::parse((string) $last)->lessThanOrEqualTo(now()->subDays($interval));
    }

    public function markDeepRestatement(int $externalResourceId): void
    {
        if ($this->enabled()) {
            DB::table('resource_activity')->where('external_resource_id', $externalResourceId)
                ->update(['last_deep_restatement_at' => now(), 'updated_at' => now()]);
        }
    }
}
