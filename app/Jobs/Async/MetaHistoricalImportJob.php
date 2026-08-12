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
use MoxDop\MetaAds\Models\MetaAdsDailyAction;
use MoxDop\MetaAds\Models\MetaAdsDailyFact;
use MoxDop\MetaAds\Models\MetaAdsEntity;
use Throwable;

/**
 * Integration-scoped Meta history import for every discovered, available Ad Account.
 * Never binds a Digital Asset. One account's failure yields a `partial` parent Run —
 * the others' imported data is preserved.
 */
class MetaHistoricalImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

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

            // Refresh the discovered Ad Account catalog first so new accounts are covered.
            try {
                $async->setPhase($run->fresh() ?? $run, 'discovering', 'Discovering Ad Accounts');
                $discovery->discover($integration);
            } catch (Throwable) {
                // Discovery is best-effort; proceed with whatever is already available.
            }

            $accounts = $import->discoverAccountsForImport($integration);
            $accountsTotal = $accounts->count();

            $this->patchMetadata($run, [
                'accounts_total' => $accountsTotal,
                'accounts_done' => 0,
                'progress_units' => 0,
                'account_results' => [],
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

            $accountResults = [];
            $anyFailed = false;
            $anyPartial = false;
            $anySucceeded = false;

            foreach ($accounts->values() as $index => $resource) {
                $fresh = $run->fresh() ?? $run;
                $async->setPhase($fresh, 'importing_account', "Importing {$resource->display_name}");
                $this->patchMetadata($fresh, [
                    'accounts_done' => $index,
                    'current_account' => $resource->display_name,
                ]);

                try {
                    $result = $import->importAccountHistory($integration, $resource, $run->fresh() ?? $run, [
                        'on_progress' => function (array $progress) use ($async, $resource): void {
                            $phase = is_string($progress['current_phase'] ?? null) ? $progress['current_phase'] : null;
                            if ($phase !== null) {
                                $async->setPhase(
                                    Run::query()->findOrFail($this->runId),
                                    'importing_account',
                                    "Importing {$resource->display_name} · ".str_replace('_', ' ', $phase),
                                );
                            }
                        },
                    ]);

                    $accountResults[(string) $resource->external_id] = [
                        'display_name' => $resource->display_name,
                        'status' => $result['status'],
                        'date_from' => $result['date_from'] ?? null,
                        'date_to' => $result['date_to'] ?? null,
                        'counts' => $result['counts'] ?? [],
                        'errors' => array_slice($result['errors'] ?? [], 0, 20),
                    ];

                    match ($result['status']) {
                        'failed' => $anyFailed = true,
                        'partial' => $anyPartial = true,
                        default => $anySucceeded = true,
                    };
                } catch (Throwable $exception) {
                    $anyFailed = true;
                    $accountResults[(string) $resource->external_id] = [
                        'display_name' => $resource->display_name,
                        'status' => 'failed',
                        'errors' => [AsyncFailureClassifier::classify($exception)['summary']],
                    ];
                }

                $this->patchMetadata($run->fresh() ?? $run, [
                    'accounts_done' => $index + 1,
                    'progress_units' => $index + 1,
                    'account_results' => $accountResults,
                ]);
            }

            [$final, $label] = match (true) {
                $anyFailed && ! $anySucceeded && ! $anyPartial => ['failed', 'Meta history import failed'],
                $anyFailed || $anyPartial => ['partial', 'Meta history import finished with gaps'],
                default => ['completed', 'Meta history import complete'],
            };

            $summary = $this->buildSummary($integration, $accounts->pluck('id')->all());

            $async->markFinished($run->fresh() ?? $run, $final, $label, [
                'result_summary' => sprintf(
                    '%d of %d Ad Account%s imported (%s daily facts, %s entities).',
                    collect($accountResults)->reject(fn (array $r): bool => ($r['status'] ?? '') === 'failed')->count(),
                    $accountsTotal,
                    $accountsTotal === 1 ? '' : 's',
                    number_format((float) $summary['daily_facts']),
                    number_format((float) $summary['entities']),
                ),
                'account_results' => $accountResults,
                'history_summary' => $summary,
                'failure_category' => $final === 'failed' ? AsyncFailureClassifier::VALIDATION : null,
                'failure_summary' => $final === 'failed' ? 'No Ad Account could be imported. See per-account results.' : null,
                'retryable' => $final !== 'completed',
            ]);
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

    /**
     * @param  list<int>  $resourceIds
     * @return array{earliest: ?string, latest: ?string, entities: int, daily_facts: int, daily_actions: int}
     */
    private function buildSummary(CoreIntegration $integration, array $resourceIds): array
    {
        if ($resourceIds === []) {
            return ['earliest' => null, 'latest' => null, 'entities' => 0, 'daily_facts' => 0, 'daily_actions' => 0];
        }

        return [
            'earliest' => MetaAdsDailyFact::query()->whereIn('core_external_resource_id', $resourceIds)->min('date'),
            'latest' => MetaAdsDailyFact::query()->whereIn('core_external_resource_id', $resourceIds)->max('date'),
            'entities' => MetaAdsEntity::query()->whereIn('core_external_resource_id', $resourceIds)->count(),
            'daily_facts' => MetaAdsDailyFact::query()->whereIn('core_external_resource_id', $resourceIds)->count(),
            'daily_actions' => MetaAdsDailyAction::query()->whereIn('core_external_resource_id', $resourceIds)->count(),
        ];
    }
}
