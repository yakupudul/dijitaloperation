<?php

namespace App\Jobs\Async;

use App\Models\DigitalAsset;
use App\Models\Run;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use App\Services\Integrations\CollectLiveBoundDataService;
use App\Support\Async\AsyncFailureClassifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CollectLiveBoundDataJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 90];
    }

    public function __construct(public int $runId) {}

    public function handle(AsyncOperationService $async, CollectLiveBoundDataService $collector): void
    {
        $run = Run::query()->find($this->runId);
        if ($run === null) {
            return;
        }

        try {
            $async->markRunning($run, 'collecting', 'Collecting provider data');
            $asset = DigitalAsset::query()->findOrFail($run->digital_asset_id);

            $async->setPhase($run->fresh() ?? $run, 'collecting_bindings', 'Collecting connected sources');
            $actorId = data_get($run->metadata, 'triggered_by_user_id');
            $actor = is_numeric($actorId) ? User::query()->find((int) $actorId) : null;
            $result = $collector->collect($asset, $actor);

            $childIds = collect($result['runs'] ?? [])
                ->map(fn (Run $child): int => (int) $child->id)
                ->values()
                ->all();

            $statuses = collect($result['runs'] ?? [])->pluck('status');
            $hasPartial = $statuses->contains('partial');
            $hasFailedChild = $statuses->contains('failed');
            $ok = (bool) ($result['ok'] ?? false);

            $final = 'completed';
            $label = 'Completed';
            if (! $ok || $hasFailedChild || $hasPartial || ($result['skipped'] ?? []) !== []) {
                $final = ($childIds === [] && ($result['collection_run_id'] ?? null) === null && ! $ok) ? 'failed' : 'partial';
                $label = $final === 'failed' ? 'Failed' : 'Completed with gaps';
            }

            $async->markFinished($run->fresh() ?? $run, $final, $label, [
                'result_summary' => (string) ($result['message'] ?? ''),
                'child_run_ids' => $childIds,
                'collection_run_id' => $result['collection_run_id'] ?? null,
                'skipped' => $result['skipped'] ?? [],
                'findings' => $result['findings'] ?? [],
                'failure_category' => $final === 'failed' ? AsyncFailureClassifier::VALIDATION : null,
                'failure_summary' => $final === 'failed' ? (string) ($result['message'] ?? 'Collection failed') : null,
                'retryable' => $final !== 'completed',
            ]);
        } catch (Throwable $exception) {
            $classified = AsyncFailureClassifier::classify($exception);
            if ($classified['category'] === AsyncFailureClassifier::VALIDATION || $this->attempts() >= $this->tries) {
                $async->markFailed($run->fresh() ?? $run, $exception);

                return;
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = Run::query()->find($this->runId);
        if ($run === null || $exception === null) {
            return;
        }

        if (in_array($run->status, ['completed', 'partial', 'failed'], true)) {
            return;
        }

        app(AsyncOperationService::class)->markFailed($run, $exception);
    }
}
