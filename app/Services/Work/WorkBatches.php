<?php

namespace App\Services\Work;

use App\Models\Suggestion;
use App\Models\User;
use App\Models\WorkBatch;
use App\Services\Repair\RepairDesk;
use App\Support\SchemaState;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Akış bookkeeping (Aşama 4, 2026-10-22): an approval or send the operator started opens one WorkBatch; while it runs
 * (within()) every external write it makes points at it, and each step adds how many rows went and which did not.
 * Never in the way of the work itself: a failure here is reported and the approval goes on.
 */
final class WorkBatches
{
    /** A running batch that has not moved for this long is shown as stopped (its job died without a word). */
    public const int STALE_MINUTES = 30;

    private static ?int $current = null;

    /** The batch whose work runs now in this process (writes created meanwhile point at it). */
    public static function current(): ?int
    {
        return self::$current;
    }

    /**
     * Runs $work as part of the batch; the batch before it is restored afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function within(?int $batchId, callable $work): mixed
    {
        $before = self::$current;
        self::$current = $batchId ?? $before;
        try {
            return $work();
        } finally {
            self::$current = $before;
        }
    }

    /**
     * @param  array{channel?: ?string, brand_id?: ?int, brand_count?: int, decision_wait_seconds?: ?int}  $extra
     */
    public function open(string $kind, string $title, ?User $user, int $total, array $extra = []): ?int
    {
        if (! SchemaState::hasTable('work_batches')) {
            return null;
        }
        try {
            return (int) WorkBatch::query()->create([
                'kind' => $kind,
                'title' => mb_substr($title, 0, 160),
                'user_id' => $user?->id,
                'total' => max(0, $total),
                'channel' => $extra['channel'] ?? null,
                'brand_id' => $extra['brand_id'] ?? null,
                'brand_count' => (int) ($extra['brand_count'] ?? 0),
                'decision_wait_seconds' => $extra['decision_wait_seconds'] ?? null,
                'status' => WorkBatch::QUEUED,
            ])->id;
        } catch (Throwable $error) {
            report($error);

            return null;
        }
    }

    /**
     * A desk approval opens its batch: the rows' brands and channel, and how long the decisions waited (median of the
     * rows' age when approved).
     *
     * @param  list<int>  $ids
     */
    public function openForDesk(array $ids, User $user, string $title): ?int
    {
        $rows = Suggestion::query()->whereIn('id', $ids)->get(['id', 'brand_id', 'channel', 'action_type', 'first_seen_at', 'created_at']);
        $brands = $rows->pluck('brand_id')->filter()->unique()->values();
        $channels = $rows->map(fn (Suggestion $s): string => self::channelOf((string) $s->channel, (string) $s->action_type))->unique()->values();
        $ages = $rows->map(fn (Suggestion $s): int => (int) abs(now()->diffInSeconds($s->first_seen_at ?? $s->created_at ?? now())))->sort()->values();

        return $this->open(WorkBatch::KIND_DESK, $title, $user, count($ids), [
            'channel' => $channels->count() === 1 ? $channels->first() : 'mixed',
            'brand_id' => $brands->count() === 1 ? (int) $brands->first() : null,
            'brand_count' => $brands->count(),
            'decision_wait_seconds' => $ages->isEmpty() ? null : (int) $ages[intdiv($ages->count(), 2)],
        ]);
    }

    public function start(?int $batchId): void
    {
        $this->safely($batchId, fn () => WorkBatch::query()->whereKey($batchId)->where('status', WorkBatch::QUEUED)
            ->update(['status' => WorkBatch::RUNNING, 'started_at' => now(), 'updated_at' => now()]));
    }

    /**
     * One step went: $done rows without trouble, $failures rows that did not go (with their reason). The batch ends
     * when every row it was given is counted.
     *
     * @param  list<array{id?: int|null, title: string, brand?: string|null, reason: string}>  $failures
     */
    public function step(?int $batchId, int $done, array $failures = []): void
    {
        $this->safely($batchId, function () use ($batchId, $done, $failures): void {
            DB::transaction(function () use ($batchId, $done, $failures): void {
                $batch = WorkBatch::query()->whereKey($batchId)->lockForUpdate()->first();
                if ($batch === null) {
                    return;
                }
                $kept = array_slice([...(array) $batch->failures, ...$failures], 0, WorkBatch::FAILURES_KEPT);
                $batch->forceFill([
                    'done' => $batch->done + max(0, $done),
                    'failed' => $batch->failed + count($failures),
                    'failures' => $kept === [] ? null : $kept,
                    'status' => $batch->status === WorkBatch::QUEUED ? WorkBatch::RUNNING : $batch->status,
                    'started_at' => $batch->started_at ?? now(),
                ]);
                if ($batch->done + $batch->failed >= $batch->total && $batch->total > 0) {
                    $batch->forceFill(['status' => $batch->failed > 0 ? WorkBatch::FAILED : WorkBatch::DONE, 'finished_at' => now()]);
                }
                $batch->save();
            });
        });
    }

    /** Desk rows of a step that did not go, with the reason the desk keeps on them. */
    public function deskFailures(array $failedIds): array
    {
        if ($failedIds === []) {
            return [];
        }

        return Suggestion::query()->with('brand:id,name')->whereIn('id', $failedIds)->get(['id', 'brand_id', 'title', 'action'])
            ->map(fn (Suggestion $s): array => ['id' => (int) $s->id, 'title' => (string) $s->title, 'brand' => $s->brand?->name,
                'reason' => (string) (data_get($s->action, RepairDesk::DESK_ERROR) ?: 'Gönderilemedi.')])->values()->all();
    }

    /** The batch ends now (the send finished in another shape than rows, or its job gave up with $error). */
    public function finish(?int $batchId, ?string $error = null, ?int $done = null): void
    {
        $this->safely($batchId, function () use ($batchId, $error, $done): void {
            $batch = WorkBatch::query()->find($batchId);
            if ($batch === null || ! $batch->isOpen()) {
                return;
            }
            $fields = ['finished_at' => now()];
            if ($done !== null) {
                $fields['done'] = $done;
                $fields['total'] = max($batch->total, $done + $batch->failed);
            }
            if ($error !== null) {
                $left = max(0, $batch->total - $batch->done - $batch->failed);
                $fields['failed'] = $batch->failed + max(1, $left);
                $fields['failures'] = array_slice([...(array) $batch->failures, ['title' => $batch->title, 'reason' => mb_substr($error, 0, 300)]], 0, WorkBatch::FAILURES_KEPT);
            }
            $batch->forceFill($fields + ['status' => $error !== null || $batch->failed > 0 ? WorkBatch::FAILED : WorkBatch::DONE])->save();
        });
    }

    /** The batch's job died and its rows are tried once more: the batch is kept open meanwhile. */
    public function touch(?int $batchId): void
    {
        $this->safely($batchId, fn () => WorkBatch::query()->whereKey($batchId)->update(['updated_at' => now()]));
    }

    /** web | gbp | google_ads | meta for a suggestion's channel and action type. */
    public static function channelOf(string $channel, string $actionType = ''): string
    {
        return match (true) {
            $channel === 'maps' || str_starts_with($actionType, 'gbp') => 'gbp',
            $channel === 'google_ads' || str_starts_with($actionType, 'ads') => 'google_ads',
            $channel === 'meta' => 'meta',
            default => 'web',
        };
    }

    private function safely(?int $batchId, callable $work): void
    {
        if ($batchId === null) {
            return;
        }
        try {
            $work();
        } catch (Throwable $error) {
            report($error);
        }
    }
}
