<?php

namespace MoxDop\MetaAds\Workspace;

use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use App\Support\Async\AsyncOperationTypes;
use App\Support\Integrations\ComparisonPeriod;
use MoxDop\MetaAds\History\MetaHistoricalImportService;
use MoxDop\MetaAds\History\MetaHistoricalQueryService;
use MoxDop\MetaAds\Models\MetaAdsHistoryCoverage;
use Throwable;

/**
 * Persists the operator's Meta workspace period selection and, when the newly
 * selected range is not fully covered by the local historical store, queues a
 * background gap-enrich. Selecting a period NEVER calls Meta synchronously — the
 * workspace always reads from the local store; enrichment fills gaps in the
 * background. Mirrors the Filament InteractsWithMetaExpertWorkspace behaviour for
 * the TailAdmin operator app.
 */
final class MetaWorkspacePeriodCoordinator
{
    /**
     * @param  array<string, mixed>  $filterUpdate
     */
    public static function apply(DigitalAsset $asset, array $filterUpdate, ?User $user = null): void
    {
        if ($asset->type !== 'meta_ads') {
            return;
        }

        $allowed = ['period_preset', 'period_start', 'period_end', 'compare', 'delivery', 'objective', 'search', 'trend_metric'];
        $mapped = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $filterUpdate)) {
                $mapped[$key] = $key === 'compare'
                    ? (bool) $filterUpdate[$key]
                    : $filterUpdate[$key];
            }
        }

        if ($mapped !== []) {
            MetaWorkspaceFilters::put((int) $asset->id, $mapped);
        }

        self::maybeQueueGapEnrich($asset, $user);
    }

    private static function maybeQueueGapEnrich(DigitalAsset $asset, ?User $user): void
    {
        $binding = CoreAssetBinding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('capability', MetaHistoricalImportService::RESOURCE_TYPE)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->with('externalResource.integration')
            ->latest('id')
            ->first();

        $resource = $binding?->externalResource;
        if ($resource === null) {
            return;
        }

        $filters = MetaWorkspaceFilters::get((int) $asset->id);

        try {
            $period = ComparisonPeriod::forPreset(
                $filters['period_preset'],
                $filters['period_start'],
                $filters['period_end'],
                false,
            )['current'];
        } catch (Throwable) {
            return;
        }

        $from = (string) ($period['start'] ?? '');
        $to = (string) ($period['end'] ?? '');
        if ($from === '' || $to === '') {
            return;
        }

        $coverage = app(MetaHistoricalQueryService::class)->isRangeCovered(
            $resource,
            $from,
            $to,
            MetaAdsHistoryCoverage::LAYER_DAILY_FACTS,
        );

        if (in_array($coverage, ['complete', 'importing', 'outside_provider'], true)) {
            return;
        }

        $async = app(AsyncOperationService::class);
        if ($async->activeRun((int) $asset->id, AsyncOperationTypes::META_HISTORY_GAP_ENRICH) !== null) {
            return;
        }

        $integrationId = (int) ($resource->integration_id ?? 0);
        if ($integrationId > 0 && $async->activeRunForIntegration($integrationId, AsyncOperationTypes::META_HISTORY_IMPORT) !== null) {
            return;
        }

        $async->queueMetaHistoryGapEnrich($asset, $from, $to, $user);
    }
}
