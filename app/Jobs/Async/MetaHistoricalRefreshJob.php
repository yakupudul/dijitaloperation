<?php

namespace App\Jobs\Async;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\Run;
use App\Services\Async\AsyncOperationService;
use App\Support\Async\AsyncFailureClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use MoxDop\MetaAds\History\MetaHistoricalConfig;
use MoxDop\MetaAds\History\MetaHistoricalImportService;
use MoxDop\MetaAds\Models\MetaAdsHistoryCoverage;
use RuntimeException;
use Throwable;

/**
 * Asset-scoped incremental Meta history refresh. Re-fetches only the trailing
 * correction window (through today in the account timezone) plus any coverage hole
 * near the end. Idempotent upserts never delete older facts.
 */
class MetaHistoricalRefreshJob implements ShouldQueue
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

    public function handle(AsyncOperationService $async, MetaHistoricalImportService $import): void
    {
        $run = Run::query()->find($this->runId);
        if ($run === null) {
            return;
        }

        try {
            $async->markRunning($run, 'starting', 'Refreshing Meta data');

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
                throw new RuntimeException('This asset has no active Meta Ad Account binding to refresh.');
            }

            [$from, $to] = $this->resolveIncrementalWindow($resource);

            $result = $import->importAccountHistory($integration, $resource, $run->fresh() ?? $run, [
                'from' => $from,
                'to' => $to,
                'include_period_aggregates' => true,
                'on_progress' => function (array $progress) use ($async, $resource): void {
                    $phase = is_string($progress['current_phase'] ?? null) ? $progress['current_phase'] : null;
                    if ($phase !== null) {
                        $async->setPhase(
                            Run::query()->findOrFail($this->runId),
                            'refreshing',
                            "Refreshing {$resource->display_name} · ".str_replace('_', ' ', $phase),
                        );
                    }
                },
            ]);

            [$final, $label] = match ($result['status']) {
                'failed' => ['failed', 'Refresh failed'],
                'partial' => ['partial', 'Refresh finished with gaps'],
                default => ['completed', 'Meta data refreshed'],
            };

            $async->markFinished($run->fresh() ?? $run, $final, $label, [
                'result_summary' => sprintf(
                    'Refreshed %s from %s to %s (%s daily facts).',
                    $resource->display_name,
                    $from,
                    $to,
                    number_format((float) ($result['counts']['facts'] ?? 0)),
                ),
                'refresh_window' => ['from' => $from, 'to' => $to],
                'counts' => $result['counts'] ?? [],
                'failure_category' => $final === 'failed' ? AsyncFailureClassifier::VALIDATION : null,
                'failure_summary' => $final === 'failed' ? 'Meta refresh failed for this account.' : null,
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

    /**
     * Correction window through today in the account timezone, extended back to fill a
     * coverage hole if the daily-facts coverage ends before the correction window start.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveIncrementalWindow(CoreExternalResource $resource): array
    {
        $meta = is_array($resource->metadata) ? $resource->metadata : [];
        $timezone = is_string($meta['timezone_name'] ?? null) && $meta['timezone_name'] !== '' ? $meta['timezone_name'] : 'UTC';

        try {
            $today = CarbonImmutable::now($timezone)->startOfDay();
        } catch (Throwable) {
            $today = CarbonImmutable::now('UTC')->startOfDay();
        }

        $windowStart = $today->subDays(MetaHistoricalConfig::correctionWindowDays());
        $from = $windowStart;

        $coverage = MetaAdsHistoryCoverage::query()
            ->where('core_external_resource_id', $resource->id)
            ->where('data_layer', MetaAdsHistoryCoverage::LAYER_DAILY_FACTS)
            ->first();

        if ($coverage instanceof MetaAdsHistoryCoverage && $coverage->end_date !== null) {
            $coverageEnd = CarbonImmutable::parse($coverage->end_date->toDateString());
            if ($coverageEnd->lt($windowStart)) {
                // Fill the gap between the last covered day and now.
                $from = $coverageEnd;
            }
        }

        return [$from->toDateString(), $today->toDateString()];
    }
}
