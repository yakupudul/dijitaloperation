<?php

namespace App\Jobs\Async;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Run;
use App\Services\Async\AsyncOperationService;
use App\Support\Async\AsyncFailureClassifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use MoxDop\MetaAds\History\MetaHistoricalImportProgress;
use MoxDop\MetaAds\History\MetaHistoricalImportService;
use MoxDop\MetaAds\Models\MetaAdsAccountImportState;
use Throwable;

/**
 * Imports provider-available history for one Meta Ad Account into the historical store.
 * Updates the parent integration-scoped Run's progress metadata. Never binds a Brand.
 */
class MetaHistoricalAccountImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 2400;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 180];
    }

    /**
     * @param  array{from?: string, to?: string}  $window
     */
    public function __construct(
        public int $parentRunId,
        public int $externalResourceId,
        public array $window = [],
    ) {}

    public function handle(
        AsyncOperationService $async,
        MetaHistoricalImportService $import,
        MetaHistoricalImportProgress $progress,
    ): void {
        $run = Run::query()->find($this->parentRunId);
        $resource = CoreExternalResource::query()->find($this->externalResourceId);
        if ($run === null || $resource === null) {
            return;
        }

        if (in_array($run->status, ['completed', 'partial', 'failed'], true)) {
            return;
        }

        $integration = CoreIntegration::query()->find($run->core_integration_id);
        if ($integration === null) {
            return;
        }

        $async->setPhase($run, 'importing_account', 'Importing '.$resource->display_name);
        // Authoritative per-account state: start of active work for this account.
        $progress->markPhase(
            $resource,
            MetaAdsAccountImportState::STATUS_DOWNLOADING,
            'Downloading '.$resource->display_name,
            run: $run->fresh() ?? $run,
        );
        $this->patch($run, [
            'current_account' => $resource->display_name,
            'current_resource_id' => $resource->id,
        ]);

        try {
            $options = array_filter([
                'from' => $this->window['from'] ?? null,
                'to' => $this->window['to'] ?? null,
                'on_progress' => function (array $importProgress) use ($async, $progress, $resource): void {
                    $phase = is_string($importProgress['current_phase'] ?? null) ? $importProgress['current_phase'] : 'importing';
                    $chunks = '';
                    if (isset($importProgress['chunks_done'], $importProgress['chunks_total'])) {
                        $chunks = ' · chunk '.(int) $importProgress['chunks_done'].'/'.(int) $importProgress['chunks_total'];
                    }

                    $parentRun = Run::query()->findOrFail($this->parentRunId);
                    $async->setPhase(
                        $parentRun,
                        'importing_account',
                        'Importing '.$resource->display_name.' · '.str_replace('_', ' ', $phase).$chunks,
                    );

                    $counters = array_filter([
                        'chunks_done' => isset($importProgress['chunks_done']) ? (int) $importProgress['chunks_done'] : null,
                        'chunks_total' => isset($importProgress['chunks_total']) ? (int) $importProgress['chunks_total'] : null,
                    ], fn (mixed $v): bool => $v !== null);

                    $progress->markPhase(
                        $resource,
                        $this->mapPhaseToStatus($phase),
                        'Importing '.$resource->display_name.' · '.str_replace('_', ' ', $phase).$chunks,
                        $counters,
                        $parentRun,
                    );
                },
            ], fn (mixed $v): bool => $v !== null && $v !== '');

            $result = $import->importAccountHistory($integration, $resource, $run->fresh() ?? $run, $options);

            if (($result['status'] ?? null) === 'failed') {
                $progress->markFailed(
                    $resource,
                    AsyncFailureClassifier::VALIDATION,
                    (string) ($result['errors'][0] ?? 'Import failed'),
                    run: $run->fresh() ?? $run,
                );
            } elseif (($result['status'] ?? null) === 'partial') {
                $progress->markPartial(
                    $resource,
                    (string) ($result['errors'][0] ?? null),
                    $run->fresh() ?? $run,
                );
            } else {
                $progress->markReady($resource, $run->fresh() ?? $run);
            }

            $this->recordAccountResult($run->fresh() ?? $run, $resource, [
                'display_name' => $resource->display_name,
                'status' => $result['status'],
                'date_from' => $result['date_from'] ?? null,
                'date_to' => $result['date_to'] ?? null,
                'counts' => $result['counts'] ?? [],
                'errors' => array_slice($result['errors'] ?? [], 0, 20),
            ]);
        } catch (Throwable $exception) {
            $classified = AsyncFailureClassifier::classify($exception);

            $willRetry = $classified['category'] === AsyncFailureClassifier::TRANSIENT
                && $this->attempts() < $this->tries;

            // A single account's failure never aborts the others. Only mark the
            // authoritative terminal state once no retry remains.
            if (! $willRetry) {
                $progress->markFailed(
                    $resource,
                    $classified['category'],
                    $classified['summary'],
                    needsAttention: $classified['category'] === AsyncFailureClassifier::VALIDATION,
                    run: $run->fresh() ?? $run,
                );

                $this->recordAccountResult($run->fresh() ?? $run, $resource, [
                    'display_name' => $resource->display_name,
                    'status' => 'failed',
                    'errors' => [$classified['summary']],
                ]);

                return;
            }

            throw $exception;
        }
    }

    /**
     * Maps the historical import service's progress phase onto an authoritative
     * account import status.
     */
    private function mapPhaseToStatus(string $phase): string
    {
        return match ($phase) {
            'importing_entities' => MetaAdsAccountImportState::STATUS_FETCHING_METADATA,
            'importing_period_aggregates' => MetaAdsAccountImportState::STATUS_NORMALIZING,
            'complete', 'completed' => MetaAdsAccountImportState::STATUS_READY,
            'partial' => MetaAdsAccountImportState::STATUS_PARTIAL,
            'failed' => MetaAdsAccountImportState::STATUS_FAILED,
            default => MetaAdsAccountImportState::STATUS_DOWNLOADING,
        };
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function recordAccountResult(Run $run, CoreExternalResource $resource, array $result): void
    {
        $progress = app(MetaHistoricalImportProgress::class);
        $integration = CoreIntegration::query()->find($run->core_integration_id);

        $meta = $run->metadata ?? [];
        $accountResults = is_array($meta['account_results'] ?? null) ? $meta['account_results'] : [];
        $accountResults[(string) $resource->external_id] = $result;

        // accounts_done is derived from the authoritative count of terminal account
        // states — never the number of account_results entries (a stale loop index).
        $done = $integration !== null
            ? $progress->terminalCount($integration)
            : collect($accountResults)->count();
        $discovered = $integration !== null
            ? $progress->authoritativeDiscoveredCount($integration)
            : (int) ($meta['accounts_total'] ?? $done);

        $run->update([
            'metadata' => array_merge($meta, [
                'account_results' => $accountResults,
                'accounts_total' => $discovered,
                'accounts_done' => $done,
                'progress_units' => $done,
                'current_account' => $resource->display_name,
            ]),
        ]);

        if ($integration !== null && $progress->allAccountsTerminal($integration)) {
            app(MetaHistoricalImportFinalizer::class)->finalize($run->fresh() ?? $run);
        }
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function patch(Run $run, array $patch): void
    {
        $run->update([
            'metadata' => array_merge($run->metadata ?? [], $patch),
        ]);
    }
}
