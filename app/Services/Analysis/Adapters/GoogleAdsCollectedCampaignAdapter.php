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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use MoxDop\GoogleAds\Collection\GoogleAdsBoundCollector;
use MoxDop\GoogleAds\Findings\GoogleAdsPerformanceBoundEvidenceEvaluator;

/**
 * google_ads_campaign_daily (bound resource) → existing campaign spend/zero-conversion rule.
 * Never reads Demo fixtures or sibling Brand/provider warehouse rows.
 */
final class GoogleAdsCollectedCampaignAdapter
{
    public const string PIPELINE = 'collected_facts_analysis';

    public const int PERIOD_DAYS = 28;

    public function __construct(
        private readonly GoogleAdsPerformanceBoundEvidenceEvaluator $evaluator,
        private readonly FindingLifecycleService $lifecycle,
    ) {}

    public function evaluate(DigitalAsset $asset): CollectedFactsAnalysisResult
    {
        $binding = CollectedFactsBindingScope::activeBinding($asset, GoogleAdsBoundCollector::CAPABILITY);
        if ($binding === null || $binding->external_resource_id === null) {
            return CollectedFactsAnalysisResult::skipped(
                DigitalAssetType::GoogleAds,
                'missing_google_ads_binding',
                ['digital_asset_id' => $asset->id],
            );
        }

        $resourceId = (int) $binding->external_resource_id;
        $end = DB::table('google_ads_campaign_daily')
            ->where('digital_asset_id', $asset->id)
            ->where('external_resource_id', $resourceId)
            ->max('reporting_date');
        if (! is_string($end) || $end === '') {
            return CollectedFactsAnalysisResult::skipped(
                DigitalAssetType::GoogleAds,
                'missing_google_ads_campaign_daily',
                [
                    'digital_asset_id' => $asset->id,
                    'external_resource_id' => $resourceId,
                    'dataset_id' => 'google_ads_campaign_daily',
                ],
            );
        }

        $periodEnd = CarbonImmutable::parse($end)->toDateString();
        $periodStart = CarbonImmutable::parse($end)->subDays(self::PERIOD_DAYS - 1)->toDateString();

        $rows = $this->aggregateCampaigns($asset->id, $resourceId, $periodStart, $periodEnd);
        if ($rows === []) {
            return CollectedFactsAnalysisResult::skipped(
                DigitalAssetType::GoogleAds,
                'google_ads_campaign_daily_empty_window',
                [
                    'digital_asset_id' => $asset->id,
                    'external_resource_id' => $resourceId,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'dataset_id' => 'google_ads_campaign_daily',
                ],
            );
        }

        $collectionRunId = $this->latestCollectionRunId($asset->id, $resourceId, $periodStart, $periodEnd);
        $run = Run::query()->create([
            'digital_asset_id' => $asset->id,
            'core_asset_binding_id' => $binding->id,
            'module_id' => GoogleAdsBoundCollector::MODULE_ID,
            'status' => 'running',
            'started_at' => now(),
            'metadata' => [
                'pipeline' => self::PIPELINE,
                'generated_by_ai' => false,
                'provider_calls' => 0,
                'ai_calls' => 0,
                'dataset_id' => 'google_ads_campaign_daily',
                'external_resource_id' => $resourceId,
                'core_asset_binding_id' => $binding->id,
                'collection_run_id' => $collectionRunId,
                'period' => ['start' => $periodStart, 'end' => $periodEnd],
            ],
        ]);

        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'source_module' => GoogleAdsBoundCollector::MODULE_ID,
            'type' => 'google_ads_campaign_performance',
            'title' => 'Collected Google Ads campaign daily facts',
            'collection_run_id' => $collectionRunId,
            'generated_by_ai' => false,
            'payload' => [
                'response_ok' => true,
                'generated_by_ai' => false,
                'rows' => $rows,
                'row_count' => count($rows),
                'period' => ['current' => ['start' => $periodStart, 'end' => $periodEnd]],
                'provenance' => [
                    'dataset_id' => 'google_ads_campaign_daily',
                    'physical_table' => 'google_ads_campaign_daily',
                    'digital_asset_id' => $asset->id,
                    'external_resource_id' => $resourceId,
                    'core_asset_binding_id' => $binding->id,
                    'collection_run_id' => $collectionRunId,
                ],
            ],
            'observed_at' => now(),
        ]);

        // Bound-evidence evaluators skip running Runs. Warehouse facts are already committed.
        $run->status = 'completed';
        $run->save();

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
            DigitalAssetType::GoogleAds,
            $run,
            $stats,
            $result->evaluationSuccessful,
            $result->evaluatedRuleIds,
            [
                'digital_asset_id' => $asset->id,
                'external_resource_id' => $resourceId,
                'core_asset_binding_id' => $binding->id,
                'dataset_id' => 'google_ads_campaign_daily',
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
        $aggregated = DB::table('google_ads_campaign_daily')
            ->where('digital_asset_id', $assetId)
            ->where('external_resource_id', $resourceId)
            ->whereBetween('reporting_date', [$start, $end])
            ->selectRaw('campaign_id, customer_id, SUM(cost_amount) as cost_sum, SUM(clicks) as clicks_sum, SUM(impressions) as impressions_sum, SUM(conversions) as conversions_sum')
            ->groupBy('campaign_id', 'customer_id')
            ->get();

        $rows = [];
        foreach ($aggregated as $row) {
            $campaignId = trim((string) $row->campaign_id);
            if ($campaignId === '') {
                continue;
            }

            $snapshot = DB::table('google_ads_campaign_snapshot')
                ->where('digital_asset_id', $assetId)
                ->where('external_resource_id', $resourceId)
                ->where('campaign_id', $campaignId)
                ->first();
            $meta = $snapshot !== null ? CollectedFactsJson::decode($snapshot->metadata ?? null) : [];
            $name = is_string($meta['name'] ?? null) ? $meta['name'] : $campaignId;

            $rows[] = [
                'campaign_id' => $campaignId,
                'campaign_name' => $name,
                'cost' => is_numeric($row->cost_sum) ? (float) $row->cost_sum : null,
                'clicks' => is_numeric($row->clicks_sum) ? (float) $row->clicks_sum : null,
                'impressions' => is_numeric($row->impressions_sum) ? (float) $row->impressions_sum : null,
                'conversions' => is_numeric($row->conversions_sum) ? (float) $row->conversions_sum : null,
            ];
        }

        return $rows;
    }

    private function latestCollectionRunId(int $assetId, int $resourceId, string $start, string $end): ?int
    {
        $value = DB::table('google_ads_campaign_daily')
            ->where('digital_asset_id', $assetId)
            ->where('external_resource_id', $resourceId)
            ->whereBetween('reporting_date', [$start, $end])
            ->max('last_collection_run_id');

        return is_numeric($value) ? (int) $value : null;
    }
}
