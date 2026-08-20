<?php

namespace App\Services\Analysis\Adapters;

use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Services\Analysis\Support\CollectedFactsAnalysisResult;
use App\Services\Analysis\Support\CollectedFactsBindingScope;
use App\Services\Analysis\Support\CollectedFactsJson;
use App\Services\Analysis\Support\DigitalAssetType;
use App\Services\Findings\FindingLifecycleService;
use App\Support\Integrations\Meta\MetaConnectorRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use MoxDop\MetaAds\Collection\MetaAdsBoundCollector;
use MoxDop\MetaAds\Findings\MetaAdsPerformanceBoundEvidenceEvaluator;

/**
 * meta_campaign_daily + snapshot (bound Ad Account) → existing inactive-with-spend rule.
 * Does not invent a primary Meta result mapping from typed actions.
 */
final class MetaAdsCollectedCampaignAdapter
{
    public const string PIPELINE = 'collected_facts_analysis';

    public const int PERIOD_DAYS = 28;

    public function __construct(
        private readonly MetaAdsPerformanceBoundEvidenceEvaluator $evaluator,
        private readonly FindingLifecycleService $lifecycle,
    ) {}

    public function evaluate(DigitalAsset $asset): CollectedFactsAnalysisResult
    {
        $binding = CollectedFactsBindingScope::activeBinding($asset, MetaConnectorRegistry::META_ADS);
        if ($binding === null || $binding->external_resource_id === null) {
            return CollectedFactsAnalysisResult::skipped(
                DigitalAssetType::MetaAds,
                'missing_meta_ads_binding',
                ['digital_asset_id' => $asset->id],
            );
        }

        $resourceId = (int) $binding->external_resource_id;
        $end = DB::table('meta_campaign_daily')
            ->where('digital_asset_id', $asset->id)
            ->where('external_resource_id', $resourceId)
            ->max('reporting_date');
        if (! is_string($end) || $end === '') {
            return CollectedFactsAnalysisResult::skipped(
                DigitalAssetType::MetaAds,
                'missing_meta_campaign_daily',
                [
                    'digital_asset_id' => $asset->id,
                    'external_resource_id' => $resourceId,
                    'dataset_id' => 'meta_campaign_daily',
                ],
            );
        }

        $periodEnd = CarbonImmutable::parse($end)->toDateString();
        $periodStart = CarbonImmutable::parse($end)->subDays(self::PERIOD_DAYS - 1)->toDateString();
        $rows = $this->aggregateCampaigns($asset->id, $resourceId, $periodStart, $periodEnd);
        if ($rows === []) {
            return CollectedFactsAnalysisResult::skipped(
                DigitalAssetType::MetaAds,
                'meta_campaign_daily_empty_window',
                [
                    'digital_asset_id' => $asset->id,
                    'external_resource_id' => $resourceId,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'dataset_id' => 'meta_campaign_daily',
                ],
            );
        }

        $collectionRunId = $this->latestCollectionRunId($asset->id, $resourceId, $periodStart, $periodEnd);
        $run = Run::query()->create([
            'digital_asset_id' => $asset->id,
            'core_asset_binding_id' => $binding->id,
            'module_id' => MetaAdsBoundCollector::MODULE_ID,
            'status' => 'running',
            'started_at' => now(),
            'metadata' => [
                'pipeline' => self::PIPELINE,
                'generated_by_ai' => false,
                'provider_calls' => 0,
                'ai_calls' => 0,
                'dataset_id' => 'meta_campaign_daily',
                'snapshot_dataset_id' => 'meta_campaign_snapshot',
                'external_resource_id' => $resourceId,
                'core_asset_binding_id' => $binding->id,
                'collection_run_id' => $collectionRunId,
                'period' => ['start' => $periodStart, 'end' => $periodEnd],
            ],
        ]);

        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'source_module' => MetaAdsBoundCollector::MODULE_ID,
            'type' => MetaAdsBoundCollector::EVIDENCE_CAMPAIGN_PERFORMANCE,
            'title' => 'Collected Meta Ads campaign daily facts',
            'collection_run_id' => $collectionRunId,
            'generated_by_ai' => false,
            'payload' => [
                'response_ok' => true,
                'generated_by_ai' => false,
                'rows' => $rows,
                'row_count' => count($rows),
                'period' => ['current' => ['start' => $periodStart, 'end' => $periodEnd]],
                'provenance' => [
                    'dataset_id' => 'meta_campaign_daily',
                    'snapshot_dataset_id' => 'meta_campaign_snapshot',
                    'physical_table' => 'meta_campaign_daily',
                    'digital_asset_id' => $asset->id,
                    'external_resource_id' => $resourceId,
                    'core_asset_binding_id' => $binding->id,
                    'collection_run_id' => $collectionRunId,
                ],
            ],
            'observed_at' => now(),
        ]);

        $result = $this->evaluator->evaluate($asset, [$run->fresh('evidence')]);
        $stats = $this->lifecycle->apply($result);
        $run->status = $result->evaluationSuccessful ? 'completed' : 'partial';
        $run->finished_at = now();
        $run->metadata = array_merge($run->metadata ?? [], [
            'evaluation_successful' => $result->evaluationSuccessful,
            'evaluated_rule_ids' => $result->evaluatedRuleIds,
            'findings' => $stats,
        ]);
        $run->save();

        return CollectedFactsAnalysisResult::evaluated(
            DigitalAssetType::MetaAds,
            $run,
            $stats,
            $result->evaluationSuccessful,
            $result->evaluatedRuleIds,
            [
                'digital_asset_id' => $asset->id,
                'external_resource_id' => $resourceId,
                'core_asset_binding_id' => $binding->id,
                'dataset_id' => 'meta_campaign_daily',
                'collection_run_id' => $collectionRunId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aggregateCampaigns(int $assetId, int $resourceId, string $start, string $end): array
    {
        $aggregated = DB::table('meta_campaign_daily')
            ->where('digital_asset_id', $assetId)
            ->where('external_resource_id', $resourceId)
            ->whereBetween('reporting_date', [$start, $end])
            ->selectRaw('campaign_id, account_id, SUM(spend) as spend, SUM(clicks) as clicks, SUM(impressions) as impressions')
            ->groupBy('campaign_id', 'account_id')
            ->get();

        $rows = [];
        foreach ($aggregated as $row) {
            $campaignId = trim((string) $row->campaign_id);
            if ($campaignId === '') {
                continue;
            }

            $snapshot = DB::table('meta_campaign_snapshot')
                ->where('digital_asset_id', $assetId)
                ->where('external_resource_id', $resourceId)
                ->where('campaign_id', $campaignId)
                ->first();
            $meta = $snapshot !== null ? CollectedFactsJson::decode($snapshot->metadata ?? null) : [];

            $rows[] = [
                'campaign_id' => $campaignId,
                'campaign_name' => is_string($meta['name'] ?? null) ? $meta['name'] : $campaignId,
                'spend' => is_numeric($row->spend) ? (float) $row->spend : null,
                'clicks' => is_numeric($row->clicks) ? (float) $row->clicks : null,
                'impressions' => is_numeric($row->impressions) ? (float) $row->impressions : null,
                'status' => is_string($meta['status'] ?? null) ? $meta['status'] : null,
                'effective_status' => is_string($meta['effective_status'] ?? null) ? $meta['effective_status'] : null,
            ];
        }

        return $rows;
    }

    private function latestCollectionRunId(int $assetId, int $resourceId, string $start, string $end): ?int
    {
        $value = DB::table('meta_campaign_daily')
            ->where('digital_asset_id', $assetId)
            ->where('external_resource_id', $resourceId)
            ->whereBetween('reporting_date', [$start, $end])
            ->max('last_collection_run_id');

        return is_numeric($value) ? (int) $value : null;
    }
}
