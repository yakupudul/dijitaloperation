<?php

namespace App\Services\Collection\Activity;

use App\Enums\Collection\ActivityTier;
use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionResourceRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\ResourceActivity;
use App\Models\ResourceAutomation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Activity tier of every collected provider account/property, computed from stored facts:
 * Ads spend, GA4 sessions, Search Console clicks (config moxdop-collection-activity.signals).
 *
 * - active: activity in the last 7 days (or a new binding whose initial load has not completed)
 * - idle: no activity for 7–30 days
 * - dormant: no activity for 30+ days, or the operator paused the account ("Duraklatıldı (müşteri kararı)")
 *
 * The pause flag clears itself as soon as activity reappears after it was set. When the effective tier goes back
 * to active the account is made due immediately and the planners backfill the gap since the last full collection.
 * Stored state only — no provider calls. The signal tables are plain tables on both SQLite and PostgreSQL
 * (compact storage only covers breakdown tables), read through the query builder.
 */
final class ActivityTierService
{
    private ?bool $ready = null;

    /**
     * @param  list<int>|null  $externalResourceIds
     * @return array{active: int, idle: int, dormant: int, paused: int}
     */
    public function refresh(?array $externalResourceIds = null): array
    {
        $counts = ['active' => 0, 'idle' => 0, 'dormant' => 0, 'paused' => 0];
        if (! $this->ready()) {
            return $counts;
        }

        $types = array_keys((array) config('moxdop-collection-activity.resource_types', []));
        CoreExternalResource::query()
            ->whereIn('resource_type', $types)
            ->when($externalResourceIds !== null, fn ($q) => $q->whereIn('id', array_map('intval', $externalResourceIds)))
            ->where(function ($q): void {
                $q->whereExists(fn ($s) => $s->selectRaw('1')->from('resource_automations')
                    ->whereColumn('resource_automations.external_resource_id', 'core_external_resources.id')
                    ->where('resource_automations.collection_enabled', true))
                    ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('core_asset_bindings')
                        ->whereColumn('core_asset_bindings.external_resource_id', 'core_external_resources.id')
                        ->where('core_asset_bindings.status', CoreAssetBinding::STATUS_ACTIVE));
            })
            ->orderBy('id')
            ->chunkById(200, function ($resources) use (&$counts): void {
                foreach ($resources as $resource) {
                    $row = $this->refreshResource($resource);
                    if ($row === null) {
                        continue;
                    }
                    $counts[$row->effectiveTier()->value]++;
                    $counts['paused'] += $row->isPaused() ? 1 : 0;
                }
            });

        return $counts;
    }

    public function refreshResource(CoreExternalResource|int $resource): ?ResourceActivity
    {
        if (! $this->ready()) {
            return null;
        }
        $resource = $resource instanceof CoreExternalResource ? $resource : CoreExternalResource::query()->find($resource);
        $provider = $resource instanceof CoreExternalResource ? $this->providerFor((string) $resource->resource_type) : null;
        if ($provider === null) {
            return null;
        }

        $lastActive = $this->lastActiveOn((int) $resource->id, $provider);

        return DB::transaction(function () use ($resource, $provider, $lastActive): ResourceActivity {
            $row = ResourceActivity::query()->lockForUpdate()->firstOrNew(['external_resource_id' => $resource->id]);
            $previousEffective = $row->exists ? $row->effectiveTier() : null;
            $previousTierSince = $row->tier_since;

            $row->provider = $provider;
            $row->last_active_on = $lastActive;

            // Auto-clear: activity on a day after the pause was set means the customer is advertising again.
            if ($row->isPaused() && $lastActive !== null
                && $lastActive->toDateString() > $row->operator_paused_at->setTimezone(config('app.timezone'))->toDateString()) {
                Log::info('collection.activity.pause_auto_cleared', ['external_resource_id' => $resource->id, 'last_active_on' => $lastActive->toDateString()]);
                $row->operator_paused_at = null;
                $row->operator_paused_by = null;
            }

            $tier = $this->computeTier($resource, $provider, $lastActive);
            if (! $row->exists || $row->tier !== $tier) {
                $row->tier_since = now();
            }
            $row->tier = $tier;
            $row->refreshed_at = now();

            $effective = $row->effectiveTier();
            if ($previousEffective !== null && $previousEffective !== ActivityTier::Active && $effective === ActivityTier::Active) {
                // Activity resumed: backfill everything since the last full collection, starting right away.
                $row->backfill_from = $row->last_full_collection_at?->toDateString()
                    ?? $row->backfill_from
                    ?? $previousTierSince?->subDays((int) config('moxdop-collection-activity.active_days', 7))->toDateString()
                    ?? now()->toDateString();
                ResourceAutomation::query()->where('external_resource_id', $resource->id)->where('collection_enabled', true)
                    ->whereNotIn('collection_status', ['planning', 'collecting'])
                    ->update(['next_collection_at' => now()]);
                Log::info('collection.activity.resumed', ['external_resource_id' => $resource->id, 'from' => $previousEffective->value]);
            }
            $row->save();

            return $row;
        });
    }

    /** Effective tier (pause = dormant); an account without a row yet is active. */
    public function tierFor(int $externalResourceId): ActivityTier
    {
        return $this->row($externalResourceId)?->effectiveTier() ?? ActivityTier::Active;
    }

    public function row(int $externalResourceId): ?ResourceActivity
    {
        if (! $this->ready()) {
            return null;
        }

        return ResourceActivity::query()->where('external_resource_id', $externalResourceId)->first();
    }

    /** Ensures a row exists (computed from facts) and returns it. */
    public function ensure(CoreExternalResource $resource): ?ResourceActivity
    {
        return $this->row((int) $resource->id) ?? $this->refreshResource($resource);
    }

    public function pause(int $externalResourceId, ?User $by = null): ResourceActivity
    {
        $row = $this->ensure($this->resource($externalResourceId))
            ?? throw new InvalidArgumentException('Bu hesap için etkinlik durumu tutulmuyor.');
        $row->forceFill(['operator_paused_at' => now(), 'operator_paused_by' => $by?->id])->save();
        Log::info('collection.activity.paused', ['external_resource_id' => $externalResourceId, 'user_id' => $by?->id]);

        return $row->fresh() ?? $row;
    }

    public function resume(int $externalResourceId, ?User $by = null): ResourceActivity
    {
        $resource = $this->resource($externalResourceId);
        $row = $this->ensure($resource) ?? throw new InvalidArgumentException('Bu hesap için etkinlik durumu tutulmuyor.');
        $row->forceFill(['operator_paused_at' => null, 'operator_paused_by' => null])->save();
        Log::info('collection.activity.unpaused', ['external_resource_id' => $externalResourceId, 'user_id' => $by?->id]);
        if ($row->tier === ActivityTier::Active) {
            // The pause kept it dormant; collect normally again from the next tick.
            $row->forceFill(['backfill_from' => $row->last_full_collection_at?->toDateString()])->save();
            ResourceAutomation::query()->where('external_resource_id', $externalResourceId)->where('collection_enabled', true)
                ->whereNotIn('collection_status', ['planning', 'collecting'])->update(['next_collection_at' => now()]);
        }

        return $row->fresh() ?? $row;
    }

    /** A full (all datasets) collection of this account finished successfully. */
    public function markFullCollection(int $externalResourceId): void
    {
        if ($this->ready()) {
            ResourceActivity::query()->where('external_resource_id', $externalResourceId)
                ->update(['last_full_collection_at' => now(), 'backfill_from' => null, 'updated_at' => now()]);
        }
    }

    /**
     * Read API for status strips / Command Center: the most active collected account bound to the asset
     * (ads accounts first). Null when the asset has no tracked account.
     *
     * @return array{tier: string, last_active_on: ?string, operator_paused: bool}|null
     */
    public function forAsset(int $digitalAssetId): ?array
    {
        if (! $this->ready()) {
            return null;
        }
        $capabilities = array_keys((array) config('moxdop-collection-activity.resource_types', []));
        $bindings = CoreAssetBinding::query()->where('digital_asset_id', $digitalAssetId)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)->whereIn('capability', $capabilities)
            ->whereNotNull('external_resource_id')->get(['capability', 'external_resource_id']);
        if ($bindings->isEmpty()) {
            return null;
        }
        $rows = ResourceActivity::query()->whereIn('external_resource_id', $bindings->pluck('external_resource_id')->all())->get();
        if ($rows->isEmpty()) {
            return null;
        }
        $ads = $rows->filter(fn (ResourceActivity $row): bool => in_array($row->provider, ['GOOGLE_ADS', 'META_ADS'], true));
        $candidates = ($ads->isNotEmpty() ? $ads : $rows)->values()->all();
        usort($candidates, fn (ResourceActivity $a, ResourceActivity $b): int => [$a->effectiveTier()->rank(), (string) $b->last_active_on?->toDateString()]
            <=> [$b->effectiveTier()->rank(), (string) $a->last_active_on?->toDateString()]);
        $best = $candidates[0];

        return [
            'tier' => $best->effectiveTier()->value,
            'last_active_on' => $best->last_active_on?->toDateString(),
            'operator_paused' => $best->isPaused(),
        ];
    }

    /**
     * SystemHealthReader "collection_activity": accounts per tier and what activity-aware planning avoided in the last 24h.
     *
     * @return array{tiers: array{active: int, idle: int, dormant: int}, paused: int, passes_24h: int, planned_datasets_24h: int, skipped_datasets_24h: int, by_mode_24h: array<string, int>}
     */
    public function healthSummary(): array
    {
        $summary = ['tiers' => ['active' => 0, 'idle' => 0, 'dormant' => 0], 'paused' => 0, 'passes_24h' => 0,
            'planned_datasets_24h' => 0, 'skipped_datasets_24h' => 0, 'by_mode_24h' => []];
        if (! $this->ready()) {
            return $summary;
        }
        foreach (ResourceActivity::query()->get(['tier', 'operator_paused_at']) as $row) {
            $summary['tiers'][$row->effectiveTier()->value]++;
            $summary['paused'] += $row->isPaused() ? 1 : 0;
        }
        $since = now()->subDay();
        $passes = DB::table('collection_activity_passes')->where('created_at', '>=', $since)
            ->selectRaw('mode, count(*) as passes, sum(planned_datasets) as planned, sum(skipped_datasets) as skipped')
            ->groupBy('mode')->get();
        foreach ($passes as $pass) {
            $summary['passes_24h'] += (int) $pass->passes;
            $summary['planned_datasets_24h'] += (int) $pass->planned;
            $summary['skipped_datasets_24h'] += (int) $pass->skipped;
            $summary['by_mode_24h'][(string) $pass->mode] = (int) $pass->skipped;
        }

        return $summary;
    }

    public function pruneLog(): int
    {
        if (! $this->ready()) {
            return 0;
        }

        return DB::table('collection_activity_passes')
            ->where('created_at', '<', now()->subDays((int) config('moxdop-collection-activity.pass_log_retention_days', 30)))
            ->delete();
    }

    public function providerFor(string $resourceType): ?string
    {
        $provider = config('moxdop-collection-activity.resource_types.'.$resourceType);

        return is_string($provider) ? $provider : null;
    }

    public function ready(): bool
    {
        return $this->ready ??= Schema::hasTable('resource_activity') && Schema::hasTable('collection_activity_passes');
    }

    private function computeTier(CoreExternalResource $resource, string $provider, ?CarbonImmutable $lastActive): ActivityTier
    {
        if ($lastActive === null) {
            // A new binding stays active until its initial load has completed.
            return $this->initialLoadCompleted((int) $resource->id) ? ActivityTier::Dormant : ActivityTier::Active;
        }
        $lag = (int) config('moxdop-collection-activity.signals.'.$provider.'.lag_days', 1);
        $reference = CarbonImmutable::today()->subDays($lag);
        $daysSince = (int) $lastActive->startOfDay()->diffInDays($reference, false);

        return match (true) {
            $daysSince < (int) config('moxdop-collection-activity.active_days', 7) => ActivityTier::Active,
            $daysSince < (int) config('moxdop-collection-activity.dormant_days', 30) => ActivityTier::Idle,
            default => ActivityTier::Dormant,
        };
    }

    private function lastActiveOn(int $externalResourceId, string $provider): ?CarbonImmutable
    {
        $signal = (array) config('moxdop-collection-activity.signals.'.$provider, []);
        $table = (string) ($signal['table'] ?? '');
        $metric = (string) ($signal['metric'] ?? '');
        if ($table === '' || $metric === '' || ! Schema::hasTable($table)) {
            return null;
        }
        $max = DB::table($table)->where('external_resource_id', $externalResourceId)->where($metric, '>', 0)->max('reporting_date');

        return $max !== null ? CarbonImmutable::parse(substr((string) $max, 0, 10))->startOfDay() : null;
    }

    private function initialLoadCompleted(int $externalResourceId): bool
    {
        return ResourceAutomation::query()->where('external_resource_id', $externalResourceId)->whereNotNull('last_collection_success_at')->exists()
            || CollectionResourceRun::query()->where('external_resource_id', $externalResourceId)
                ->where('status', CollectionRunStatus::Completed->value)->exists();
    }

    private function resource(int $externalResourceId): CoreExternalResource
    {
        return CoreExternalResource::query()->findOrFail($externalResourceId);
    }
}
