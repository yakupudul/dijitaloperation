<?php

namespace App\Services\Observability;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Models\Collection\CollectionRun;
use Illuminate\Support\Collection;

/**
 * Workload-aware stuck Collection detection.
 * Long Backfill ≠ stuck. Progress + policy thresholds matter.
 */
final class StuckCollectionDetector
{
    /**
     * @return list<array{
     *     collection_run_id: int,
     *     uuid: string,
     *     trigger_type: string,
     *     last_activity_at: string|null,
     *     no_progress_seconds: int,
     *     threshold_seconds: int,
     *     customer_id: int|null,
     *     brand_id: int|null,
     *     digital_asset_id: int|null
     * }>
     */
    public function candidates(): array
    {
        $runs = CollectionRun::query()
            ->where('status', CollectionRunStatus::Running->value)
            ->orderBy('id')
            ->limit(200)
            ->get();

        $out = [];
        foreach ($runs as $run) {
            $threshold = $this->thresholdFor($run);
            $last = $run->last_activity_at ?? $run->started_at;
            if ($last === null) {
                continue;
            }
            $age = max(0, now()->getTimestamp() - $last->getTimestamp());
            if ($age < $threshold) {
                continue;
            }
            $out[] = [
                'collection_run_id' => (int) $run->id,
                'uuid' => (string) $run->uuid,
                'trigger_type' => (string) ($run->trigger_type?->value ?? $run->trigger_type),
                'last_activity_at' => $last->toIso8601String(),
                'no_progress_seconds' => $age,
                'threshold_seconds' => $threshold,
                'customer_id' => $run->customer_id !== null ? (int) $run->customer_id : null,
                'brand_id' => $run->brand_id !== null ? (int) $run->brand_id : null,
                'digital_asset_id' => $run->digital_asset_id !== null ? (int) $run->digital_asset_id : null,
            ];
        }

        return $out;
    }

    private function thresholdFor(CollectionRun $run): int
    {
        $trigger = $run->trigger_type instanceof CollectionTriggerType
            ? $run->trigger_type
            : CollectionTriggerType::tryFrom((string) $run->trigger_type);

        $config = config('moxdop-observability.collection', []);

        return match ($trigger) {
            CollectionTriggerType::InitialBackfill,
            CollectionTriggerType::Replay => max(300, (int) ($config['stuck_backfill_no_progress_seconds'] ?? 7200)),
            CollectionTriggerType::Incremental => max(120, (int) ($config['stuck_incremental_no_progress_seconds'] ?? 900)),
            default => max(120, (int) ($config['stuck_default_no_progress_seconds'] ?? 1800)),
        };
    }

    /**
     * @return Collection<int, CollectionRun>
     */
    public function recentFailures(int $windowSeconds = 3600, int $limit = 50): Collection
    {
        return CollectionRun::query()
            ->where('status', CollectionRunStatus::Failed->value)
            ->where('finished_at', '>=', now()->subSeconds($windowSeconds))
            ->orderByDesc('finished_at')
            ->limit($limit)
            ->get();
    }
}
