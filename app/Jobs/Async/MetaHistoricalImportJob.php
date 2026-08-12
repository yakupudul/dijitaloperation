<?php

namespace App\Jobs\Async;

use App\Models\CoreIntegration;
use App\Models\Run;
use App\Services\Async\AsyncOperationService;
use App\Services\Integrations\Meta\MetaResourceDiscoveryService;
use App\Support\Async\AsyncFailureClassifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use MoxDop\MetaAds\History\MetaHistoricalImportService;
use Throwable;

/**
 * Integration-scoped Meta history import orchestrator.
 *
 * Discovers Ad Accounts, then dispatches one resumable job per account.
 * Never binds a Digital Asset. Parent Run remains the operator-visible progress record.
 */
class MetaHistoricalImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $runId) {}

    public function handle(
        AsyncOperationService $async,
        MetaHistoricalImportService $import,
        MetaResourceDiscoveryService $discovery,
    ): void {
        $run = Run::query()->find($this->runId);
        if ($run === null) {
            return;
        }

        try {
            $async->markRunning($run, 'starting', 'Starting Meta history import');

            $integration = CoreIntegration::query()->findOrFail($run->core_integration_id);

            try {
                $async->setPhase($run->fresh() ?? $run, 'discovering', 'Discovering Ad Accounts');
                $discovery->discover($integration);
            } catch (Throwable) {
                // Discovery is best-effort; proceed with whatever is already available.
            }

            $accounts = $import->discoverAccountsForImport($integration)
                ->sortBy(function ($resource): string {
                    return str_contains((string) $resource->external_id, '744654160596455')
                        ? '0'
                        : '1'.mb_strtolower((string) $resource->display_name);
                })
                ->values();

            $accountsTotal = $accounts->count();

            $window = array_filter([
                'from' => data_get($run->metadata, 'import_from'),
                'to' => data_get($run->metadata, 'import_to'),
            ], fn (mixed $v): bool => is_string($v) && $v !== '');

            $this->patchMetadata($run, [
                'accounts_total' => $accountsTotal,
                'accounts_done' => 0,
                'progress_units' => 0,
                'account_results' => [],
                'orchestration' => 'per_account_jobs',
            ]);

            if ($accountsTotal === 0) {
                $async->markFinished($run->fresh() ?? $run, 'failed', 'No Ad Accounts to import', [
                    'result_summary' => 'No available Meta Ad Accounts were discovered for this Integration.',
                    'failure_category' => AsyncFailureClassifier::VALIDATION,
                    'failure_summary' => 'Discover Meta Ad Accounts before importing history.',
                    'retryable' => true,
                ]);

                return;
            }

            $async->setPhase($run->fresh() ?? $run, 'queued_accounts', 'Queued '.$accountsTotal.' Ad Account imports');

            $parentRunId = $this->runId;
            foreach ($accounts as $index => $resource) {
                MetaHistoricalAccountImportJob::dispatch(
                    parentRunId: $parentRunId,
                    externalResourceId: (int) $resource->id,
                    window: $window,
                )->delay(now()->addSeconds($index * 2));
            }
        } catch (Throwable $exception) {
            $async->markFailed($run->fresh() ?? $run, $exception);
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

    /**
     * @param  array<string, mixed>  $patch
     */
    private function patchMetadata(Run $run, array $patch): void
    {
        $run->update([
            'metadata' => array_merge($run->metadata ?? [], $patch),
        ]);
    }
}
