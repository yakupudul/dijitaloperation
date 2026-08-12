<?php

namespace App\Jobs\Async;

use App\Models\CoreAssetBinding;
use App\Models\Run;
use App\Services\Async\AsyncOperationService;
use App\Support\Async\AsyncFailureClassifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use MoxDop\MetaAds\History\MetaHistoricalImportService;
use MoxDop\MetaAds\History\MetaHistoricalPeriodEnricher;
use RuntimeException;
use Throwable;

/**
 * Asset-scoped gap enrichment for an exact [gap_from, gap_to] range: backfills daily
 * facts/actions for the range subset and fetches exact-period reach/frequency for the
 * account entity so range-level metrics resolve. Idempotent; never wipes history.
 */
class MetaHistoricalGapEnrichJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 1800;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(public int $runId) {}

    public function handle(
        AsyncOperationService $async,
        MetaHistoricalImportService $import,
        MetaHistoricalPeriodEnricher $enricher,
    ): void {
        $run = Run::query()->find($this->runId);
        if ($run === null) {
            return;
        }

        try {
            $async->markRunning($run, 'starting', 'Preparing Meta history');

            $from = (string) data_get($run->metadata, 'gap_from');
            $to = (string) data_get($run->metadata, 'gap_to');
            if ($from === '' || $to === '') {
                throw new RuntimeException('Gap enrichment requires a date range.');
            }

            $binding = CoreAssetBinding::query()
                ->where('digital_asset_id', $run->digital_asset_id)
                ->where('capability', MetaHistoricalImportService::RESOURCE_TYPE)
                ->where('status', CoreAssetBinding::STATUS_ACTIVE)
                ->with('externalResource.integration')
                ->latest('id')
                ->first();

            $resource = $binding?->externalResource;
            $integration = $resource?->integration;

            if ($binding === null || $resource === null || $integration === null) {
                throw new RuntimeException('This asset has no active Meta Ad Account binding to enrich.');
            }

            $async->setPhase($run->fresh() ?? $run, 'backfilling', 'Backfilling missing days');
            $result = $import->importAccountHistory($integration, $resource, $run->fresh() ?? $run, [
                'from' => $from,
                'to' => $to,
                'include_period_aggregates' => false,
            ]);

            // Exact account-level reach/frequency for the precise selected range.
            $async->setPhase($run->fresh() ?? $run, 'enriching_period', 'Fetching exact reach/frequency');
            $actId = str_starts_with((string) $resource->external_id, 'act_')
                ? (string) $resource->external_id
                : 'act_'.$resource->external_id;
            $periodResult = $enricher->fetchAndStoreExactPeriod($integration, $resource, 'account', $actId, $from, $to, $run->fresh() ?? $run);

            $final = 'completed';
            $label = 'Meta history ready';
            if ($result['status'] === 'failed' || $periodResult['status'] === 'failed') {
                $final = ($result['status'] === 'failed' && ($result['counts']['facts'] ?? 0) === 0) ? 'failed' : 'partial';
                $label = $final === 'failed' ? 'Gap enrichment failed' : 'Meta history ready with gaps';
            } elseif ($result['status'] === 'partial') {
                $final = 'partial';
                $label = 'Meta history ready with gaps';
            }

            $async->markFinished($run->fresh() ?? $run, $final, $label, [
                'result_summary' => sprintf(
                    'Prepared %s for %s → %s (%s daily facts, reach/frequency %s).',
                    $resource->display_name,
                    $from,
                    $to,
                    number_format((float) ($result['counts']['facts'] ?? 0)),
                    $periodResult['status'],
                ),
                'gap_from' => $from,
                'gap_to' => $to,
                'counts' => $result['counts'] ?? [],
                'period_status' => $periodResult['status'],
                'failure_category' => $final === 'failed' ? AsyncFailureClassifier::VALIDATION : null,
                'failure_summary' => $final === 'failed' ? 'Could not prepare Meta history for this range.' : null,
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
