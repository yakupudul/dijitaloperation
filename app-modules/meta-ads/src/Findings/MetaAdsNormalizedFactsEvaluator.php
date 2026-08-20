<?php

namespace MoxDop\MetaAds\Findings;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Services\Evidence\EvidenceEligibilityService;
use App\Services\Findings\FindingLifecycleService;
use App\Services\MetaAds\MetaAdsSpecialistBindingResolver;
use App\Services\MetaAds\MetaAdsUiDatasetGate;
use App\Support\Evidence\EvidenceDefinition;
use App\Support\Findings\RuleEvaluationResult;
use App\Support\Integrations\Meta\MetaResourceType;
use Illuminate\Support\Facades\DB;
use MoxDop\MetaAds\Collection\MetaAdsBoundCollector;
use MoxDop\MetaAds\Normalization\MetaResultResolver;

/**
 * Deterministic Meta Ads Findings from Data Pool facts (campaign daily + snapshot + typed actions).
 * Reuses MetaAdsFindingsCatalog thresholds and MetaResultResolver. Does not call the Marketing API.
 */
final class MetaAdsNormalizedFactsEvaluator
{
    public function __construct(
        private readonly MetaAdsUiDatasetGate $gate,
        private readonly EvidenceEligibilityService $eligibility,
        private readonly MetaAdsPerformanceBoundEvidenceEvaluator $boundEvaluator,
        private readonly FindingLifecycleService $lifecycle,
    ) {}

    /**
     * @return array{opened: int, updated: int, reopened: int, resolved: int, recommendations: int}|null
     */
    public function evaluateAndApply(DigitalAsset $asset): ?array
    {
        if ($asset->type !== 'meta_ads') {
            return null;
        }

        $result = $this->evaluate($asset);
        if (! $result->evaluationSuccessful || $result->run->id === null) {
            return null;
        }

        return $this->lifecycle->apply($result);
    }

    public function evaluate(DigitalAsset $asset): RuleEvaluationResult
    {
        $binding = CoreAssetBinding::query()
            ->with('externalResource')
            ->where('digital_asset_id', $asset->id)
            ->where('capability', MetaAdsSpecialistBindingResolver::CAPABILITY)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->first();

        $resource = $binding?->externalResource;
        if (! $binding instanceof CoreAssetBinding
            || ! $resource instanceof CoreExternalResource
            || $resource->resource_type !== MetaResourceType::META_AD_ACCOUNT
        ) {
            return $this->failed($asset);
        }

        $timezone = is_array($resource->metadata) && is_string($resource->metadata['reporting_timezone'] ?? null)
            ? (string) $resource->metadata['reporting_timezone']
            : null;

        $definition = new EvidenceDefinition(
            id: 'meta_ads.campaign_daily.analysis',
            statementKind: 'period_comparison',
            titleTemplate: 'Meta Ads campaign delivery versus the previous comparable period',
            sourceModule: MetaAdsFindingsCatalog::SOURCE_MODULE,
            provider: 'META_ADS',
            datasetId: 'meta_campaign_daily',
            physicalTable: 'meta_campaign_daily',
            resourceType: MetaResourceType::META_AD_ACCOUNT,
            bindingCapability: MetaAdsSpecialistBindingResolver::CAPABILITY,
            grainColumn: 'account_id',
            metricFields: ['spend', 'impressions', 'clicks'],
            formulaIds: ['FORMULA_PERIOD_RELATIVE_CHANGE'],
            defaultPeriodDays: 28,
        );

        $period = $this->eligibility->periodFromCoverageEnd(
            $definition,
            $this->coverageEnd($asset->id, $resource->id, 'meta_campaign_daily'),
        );
        if ($period === null) {
            return $this->failed($asset);
        }

        $daily = $this->gate->evaluate(
            $asset->id,
            $resource->id,
            'meta_campaign_daily',
            $period->previousStart,
            $period->currentEnd,
            $timezone,
        );
        if (! $daily->isFullyCovered()) {
            return $this->failed($asset);
        }

        $snapshot = $this->gate->evaluateSnapshot($asset->id, $resource->id, 'meta_campaign_snapshot', $timezone);
        if (! $snapshot->isUsable()) {
            return $this->failed($asset);
        }

        $typedReady = $this->gate->evaluate(
            $asset->id,
            $resource->id,
            'meta_typed_action_daily',
            $period->currentStart,
            $period->currentEnd,
            $timezone,
        )->isFullyCovered();

        $rows = $this->campaignRows($asset->id, $resource->id, $period->currentStart, $period->currentEnd, $typedReady);
        if ($rows === []) {
            return $this->failed($asset);
        }

        $run = Run::query()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => MetaAdsFindingsCatalog::SOURCE_MODULE,
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
            'metadata' => [
                'pipeline' => 'meta_ads_normalized_facts',
                'generated_by_ai' => false,
                'provider_calls' => 0,
                'ai_calls' => 0,
                'external_resource_id' => $resource->id,
                'binding_id' => $binding->id,
                'period' => $period->toArray(),
                'typed_actions_ready' => $typedReady,
                'provenance' => [
                    'datasets' => ['meta_campaign_daily', 'meta_campaign_snapshot'],
                    'collection_run_id' => $daily->integrityAuditRunUuid,
                ],
            ],
        ]);

        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'source_module' => MetaAdsFindingsCatalog::SOURCE_MODULE,
            'type' => MetaAdsBoundCollector::EVIDENCE_CAMPAIGN_PERFORMANCE,
            'title' => 'Meta Ads campaign performance from normalized facts',
            'is_canonical' => false,
            'is_derived' => true,
            'generated_by_ai' => false,
            'payload' => [
                'response_ok' => true,
                'derived_from' => 'data_pool',
                'external_resource_id' => $resource->id,
                'period' => $period->toArray(),
                'rows' => $rows,
            ],
            'observed_at' => now(),
        ]);

        return $this->boundEvaluator->evaluate($asset, [$run->fresh('evidence')]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function campaignRows(
        int $digitalAssetId,
        int $externalResourceId,
        string $start,
        string $end,
        bool $typedReady,
    ): array {
        $aggregates = DB::table('meta_campaign_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->whereBetween('reporting_date', [$start, $end])
            ->selectRaw('campaign_id, SUM(spend) as spend, SUM(impressions) as impressions, SUM(clicks) as clicks')
            ->groupBy('campaign_id')
            ->get();

        $rows = [];
        foreach ($aggregates as $aggregate) {
            $campaignId = (string) $aggregate->campaign_id;
            if ($campaignId === '') {
                continue;
            }

            $snapshot = DB::table('meta_campaign_snapshot')
                ->where('digital_asset_id', $digitalAssetId)
                ->where('external_resource_id', $externalResourceId)
                ->where('campaign_id', $campaignId)
                ->first();
            if ($snapshot === null) {
                continue;
            }

            $metadata = is_string($snapshot->metadata)
                ? json_decode($snapshot->metadata, true)
                : (is_array($snapshot->metadata) ? $snapshot->metadata : []);
            if (! is_array($metadata)) {
                $metadata = [];
            }

            $objective = is_string($metadata['objective'] ?? null) ? $metadata['objective'] : null;
            $status = is_string($metadata['effective_status'] ?? $metadata['status'] ?? null)
                ? (string) ($metadata['effective_status'] ?? $metadata['status'])
                : '';

            $actions = [];
            if ($typedReady) {
                $actionRows = DB::table('meta_typed_action_daily')
                    ->where('digital_asset_id', $digitalAssetId)
                    ->where('external_resource_id', $externalResourceId)
                    ->where('entity_level', 'campaign')
                    ->where('entity_id', $campaignId)
                    ->whereBetween('reporting_date', [$start, $end])
                    ->selectRaw('action_type, SUM(action_value) as action_value')
                    ->groupBy('action_type')
                    ->get();
                foreach ($actionRows as $actionRow) {
                    $actions[] = [
                        'raw_action_type' => (string) $actionRow->action_type,
                        'count' => (float) $actionRow->action_value,
                    ];
                }
            }

            $primary = $typedReady
                ? MetaResultResolver::resolve(
                    $actions,
                    $objective,
                    is_string($metadata['optimization_goal'] ?? null) ? $metadata['optimization_goal'] : null,
                    is_numeric($aggregate->spend) ? (float) $aggregate->spend : null,
                )
                : ['status' => 'deferred', 'reason' => 'typed_actions_not_collected'];

            $rows[] = [
                'campaign_id' => $campaignId,
                'campaign_name' => is_string($metadata['name'] ?? null) ? $metadata['name'] : $campaignId,
                'spend' => (float) $aggregate->spend,
                'impressions' => (float) $aggregate->impressions,
                'clicks' => (float) $aggregate->clicks,
                'effective_status' => $status,
                'status' => $status,
                'primary_result' => $primary,
            ];
        }

        return $rows;
    }

    private function coverageEnd(int $digitalAssetId, int $externalResourceId, string $datasetId): ?string
    {
        $end = DB::table('dataset_materializations')
            ->where('dataset_id', $datasetId)
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->value('coverage_end_date');

        return is_string($end) && $end !== '' ? $end : null;
    }

    private function failed(DigitalAsset $asset): RuleEvaluationResult
    {
        $run = new Run([
            'digital_asset_id' => $asset->id,
            'module_id' => MetaAdsFindingsCatalog::SOURCE_MODULE,
            'status' => 'failed',
        ]);

        return new RuleEvaluationResult(
            asset: $asset,
            sourceModule: MetaAdsFindingsCatalog::SOURCE_MODULE,
            run: $run,
            evaluationSuccessful: false,
            evaluatedRuleIds: [],
            matches: [],
            observedAt: now(),
        );
    }
}
