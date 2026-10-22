<?php

namespace App\Services\Work;

use App\Jobs\SendReadyContentJob;
use App\Models\ExternalWriteAction;
use App\Models\GbpQueuedPost;
use App\Models\User;
use App\Models\WorkBatch;
use App\Services\Gbp\GbpPostQueue;
use App\Services\Repair\RepairDesk;
use App\Support\Operator\OperatorClock;
use Illuminate\Support\Collection;
use Throwable;

/**
 * "Yeniden dene" on Akış (Aşama 4): a failed piece of work goes again the way it first went, never by replaying the
 * raw write — a desk row is approved again (its checks run again), an article is sent again from İçerik, a Business
 * Profile post is approved again for today. Writes are never retried blindly; what has no own way back is left with
 * its reason. A write sent again is marked (`retried_at`), so it is no longer an open error.
 */
final class FlowRetry
{
    private const array ARTICLE_ACTIONS = [ExternalWriteAction::ACTION_ARTICLE_DRAFTS, ExternalWriteAction::ACTION_CONTENT_DRAFT];

    public function __construct(private readonly RepairDesk $desk, private readonly GbpPostQueue $posts) {}

    /**
     * Every failure of one Akış row (`batch:ID` / `writes:ACTION|BRAND`), or of today's rows when $key is null.
     *
     * @return array{sent: int, left: int}
     */
    public function retry(User $user, ?string $key = null): array
    {
        $writes = $this->failedWrites($key);
        $deskIds = $this->batchFailureIds($key);

        return $this->send($user, $writes, $deskIds);
    }

    /**
     * One sub row: `w123` (a write) or `f4-567` (a desk row of a batch that did not go).
     *
     * @return array{sent: int, left: int}
     */
    public function retryItem(User $user, string $item): array
    {
        if (str_starts_with($item, 'w')) {
            $write = ExternalWriteAction::query()->whereKey((int) substr($item, 1))->where('status', 'failed')->whereNull('retried_at')->get();

            return $this->send($user, $write, []);
        }
        if (str_starts_with($item, 'f') && str_contains($item, '-')) {
            $id = (int) substr($item, strpos($item, '-') + 1);

            return $this->send($user, collect(), $id > 0 ? [$id] : []);
        }

        return ['sent' => 0, 'left' => 0];
    }

    /**
     * @param  Collection<int, ExternalWriteAction>  $writes
     * @param  list<int>  $deskIds
     * @return array{sent: int, left: int}
     */
    private function send(User $user, Collection $writes, array $deskIds): array
    {
        $sent = 0;
        $left = 0;
        $handled = [];
        $sites = [];
        foreach ($writes as $write) {
            if (in_array($write->action, self::ARTICLE_ACTIONS, true)) {
                // The article went back to "send" with its reason (ContentPlanner::draftFailed): it goes with the site's next send.
                $sites[(int) $write->digital_asset_id] = true;
                $handled[] = $write->id;
            } elseif ($write->action === ExternalWriteAction::ACTION_LOCAL_POST && ($post = GbpQueuedPost::query()->where('external_write_action_id', $write->id)->first()) !== null) {
                try {
                    $this->posts->retry($user, $post);
                    $handled[] = $write->id;
                    $sent++;
                } catch (Throwable) {
                    $left++;
                }
            } elseif ($write->suggestion_id !== null) {
                $deskIds[] = (int) $write->suggestion_id;
                $handled[] = $write->id;
            } else {
                $left++;
            }
        }
        $deskIds = array_values(array_unique($deskIds));
        if ($deskIds !== []) {
            // Only rows that are open on the desk again go (queueApproval re-reads them); the others are already done or gone.
            $queued = $this->desk->queueApproval($deskIds, $user);
            $sent += (int) $queued['applied'];
            $left += max(0, count($deskIds) - (int) $queued['applied'] - (int) $queued['skipped_high']);
        }
        foreach (array_keys($sites) as $siteId) {
            SendReadyContentJob::dispatch((int) $user->id, null, $siteId);
            $sent++;
        }
        if ($handled !== []) {
            ExternalWriteAction::query()->whereIn('id', $handled)->update(['retried_at' => now()]);
        }
        $this->forgetBatchFailures($deskIds);

        return ['sent' => $sent, 'left' => $left];
    }

    /** @return Collection<int, ExternalWriteAction> */
    private function failedWrites(?string $key): Collection
    {
        $query = ExternalWriteAction::query()->where('status', 'failed')->whereNull('retried_at')
            // Google Ads writes are off: nothing there goes again from here.
            ->where('channel', '!=', ExternalWriteAction::CHANNEL_GOOGLE_ADS);
        [$type, $rest] = array_pad(explode(':', (string) $key, 2), 2, '');
        if ($type === 'batch') {
            $query->where('work_batch_id', (int) $rest);
        } elseif ($type === 'writes') {
            [$action, $brand] = array_pad(explode('|', $rest, 2), 2, '0');
            $query->where('action', $action)->whereNull('work_batch_id')
                ->when((int) $brand > 0, fn ($q) => $q->where('brand_id', (int) $brand), fn ($q) => $q->whereNull('brand_id'))
                ->where('finished_at', '>=', OperatorClock::startOfToday());
        } elseif ($key === null) {
            $query->where('finished_at', '>=', OperatorClock::startOfToday());
        } else {
            return collect();
        }

        return $query->orderBy('id')->limit(2000)->get();
    }

    /** @return list<int> desk rows a batch could not send */
    private function batchFailureIds(?string $key): array
    {
        [$type, $rest] = array_pad(explode(':', (string) $key, 2), 2, '');
        $batches = match (true) {
            $type === 'batch' => WorkBatch::query()->whereKey((int) $rest)->get(),
            $key === null => WorkBatch::query()->where('created_at', '>=', OperatorClock::startOfToday())->where('failed', '>', 0)->get(),
            default => collect(),
        };

        return $batches->flatMap(fn (WorkBatch $b): array => array_filter(array_map(fn (array $f): int => (int) ($f['id'] ?? 0), (array) $b->failures)))->values()->all();
    }

    /**
     * Rows that went again leave the failure list of their batch (the new approval is its own row on Akış).
     *
     * @param  list<int>  $ids
     */
    private function forgetBatchFailures(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $flip = array_flip($ids);
        WorkBatch::query()->where('created_at', '>=', now()->subDays(2))->where('failed', '>', 0)->whereNotIn('status', [WorkBatch::QUEUED, WorkBatch::RUNNING])->get()->each(function (WorkBatch $batch) use ($flip): void {
            $keep = array_values(array_filter((array) $batch->failures, fn (array $f): bool => ! isset($flip[(int) ($f['id'] ?? 0)])));
            $gone = count((array) $batch->failures) - count($keep);
            if ($gone > 0) {
                $batch->forceFill(['failures' => $keep === [] ? null : $keep, 'failed' => max(0, $batch->failed - $gone)])->save();
                if ($batch->failed === 0 && $batch->status === WorkBatch::FAILED) {
                    $batch->forceFill(['status' => WorkBatch::DONE])->save();
                }
            }
        });
    }
}
