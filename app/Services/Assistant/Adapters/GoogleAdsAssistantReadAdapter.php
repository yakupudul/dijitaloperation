<?php

namespace App\Services\Assistant\Adapters;

use App\Enums\AssistantCoverageState;
use App\Enums\AssistantFreshnessState;
use App\Services\GoogleAds\GoogleAdsPoolReadRepository;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Support\Assistant\AssistantMetricRegistry;
use App\Support\Assistant\Dto\AssistantDateRange;
use App\Support\Assistant\Dto\AssistantProviderMetricResult;
use App\Support\Assistant\Dto\AssistantSessionScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Google Ads provider metric adapter — persisted pool only, no live provider calls.
 */
final class GoogleAdsAssistantReadAdapter
{
    public function __construct(
        private readonly GoogleAdsSpecialistBindingResolver $bindings,
        private readonly GoogleAdsPoolReadRepository $pool,
    ) {}

    public function lookupSpend(
        AssistantSessionScope $scope,
        AssistantDateRange $range,
        string $metricId = AssistantMetricRegistry::GOOGLE_ADS_SPEND,
    ): AssistantProviderMetricResult {
        $assetId = (int) $scope->digitalAssetId;
        $context = $this->bindings->resolve((string) $assetId);

        if (! $context->isReal()) {
            return new AssistantProviderMetricResult(
                metricId: $metricId,
                value: null,
                currency: null,
                unit: 'currency',
                requestedPeriod: $range,
                coveredPeriod: null,
                freshness: AssistantFreshnessState::Unknown,
                coverage: AssistantCoverageState::Missing,
                digitalAssetId: $assetId,
                provider: 'google_ads',
                opaqueSourceRef: 'google_ads:unavailable:'.$assetId,
                limitations: ['binding_not_ready'],
                unavailable: true,
                unavailableReason: $context->reason ?? 'binding_not_ready',
            );
        }

        if (! Schema::hasTable('google_ads_account_daily')) {
            return new AssistantProviderMetricResult(
                metricId: $metricId,
                value: null,
                currency: null,
                unit: 'currency',
                requestedPeriod: $range,
                coveredPeriod: null,
                freshness: AssistantFreshnessState::Unknown,
                coverage: AssistantCoverageState::Missing,
                digitalAssetId: $assetId,
                provider: 'google_ads',
                opaqueSourceRef: 'google_ads:table_missing',
                limitations: ['dataset_unavailable'],
                unavailable: true,
                unavailableReason: 'dataset_unavailable',
            );
        }

        $sums = $this->pool->accountDailySums(
            digitalAssetId: $assetId,
            externalResourceId: (int) $context->externalResourceId,
            customerId: (string) $context->customerId,
            start: $range->startDate,
            end: $range->endDate,
        );

        $requestedDays = CarbonImmutable::parse($range->startDate)
            ->diffInDays(CarbonImmutable::parse($range->endDate)) + 1;
        $coveredRows = (int) $sums['rows'];
        $coverage = match (true) {
            $coveredRows === 0 => AssistantCoverageState::Missing,
            $coveredRows < $requestedDays => AssistantCoverageState::Partial,
            default => AssistantCoverageState::Complete,
        };

        $latest = DB::table('google_ads_account_daily')
            ->where('digital_asset_id', $assetId)
            ->where('external_resource_id', (int) $context->externalResourceId)
            ->max('reporting_date');

        $coveredBounds = DB::table('google_ads_account_daily')
            ->where('digital_asset_id', $assetId)
            ->where('external_resource_id', (int) $context->externalResourceId)
            ->where('customer_id', (string) $context->customerId)
            ->whereBetween('reporting_date', [$range->startDate, $range->endDate])
            ->selectRaw('MIN(reporting_date) as covered_start')
            ->selectRaw('MAX(reporting_date) as covered_end')
            ->first();

        $freshness = AssistantFreshnessState::Unknown;
        if (is_string($latest) && $latest !== '') {
            $age = CarbonImmutable::parse($latest)->diffInDays(CarbonImmutable::today());
            $freshness = $age <= 2 ? AssistantFreshnessState::Fresh : AssistantFreshnessState::Stale;
        }

        if ($coverage === AssistantCoverageState::Missing) {
            return new AssistantProviderMetricResult(
                metricId: $metricId,
                value: null,
                currency: $sums['currency'],
                unit: 'currency',
                requestedPeriod: $range,
                coveredPeriod: null,
                freshness: $freshness,
                coverage: $coverage,
                digitalAssetId: $assetId,
                provider: 'google_ads',
                opaqueSourceRef: 'google_ads:account_daily:'.$assetId.':'.$range->startDate.':'.$range->endDate,
                limitations: ['no_rows_in_range'],
                unavailable: true,
                unavailableReason: 'no_data',
            );
        }

        $covered = new AssistantDateRange(
            token: $range->token,
            startDate: (string) ($coveredBounds->covered_start ?? $range->startDate),
            endDate: (string) ($coveredBounds->covered_end ?? $range->endDate),
            timezone: $context->timezone ?? $range->timezone,
        );

        $limitations = [];
        if ($coverage === AssistantCoverageState::Partial) {
            $limitations[] = 'partial_coverage';
        }
        if ($freshness === AssistantFreshnessState::Stale) {
            $limitations[] = 'stale_data';
        }

        $value = match ($metricId) {
            AssistantMetricRegistry::GOOGLE_ADS_IMPRESSIONS => (float) $sums['impressions'],
            AssistantMetricRegistry::GOOGLE_ADS_CLICKS => (float) $sums['clicks'],
            default => (float) $sums['cost_amount'],
        };

        return new AssistantProviderMetricResult(
            metricId: $metricId,
            value: round($value, 2),
            currency: $sums['currency'],
            unit: $metricId === AssistantMetricRegistry::GOOGLE_ADS_SPEND ? 'currency' : 'count',
            requestedPeriod: $range,
            coveredPeriod: $covered,
            freshness: $freshness,
            coverage: $coverage,
            digitalAssetId: $assetId,
            provider: 'google_ads',
            opaqueSourceRef: 'google_ads:account_daily:'.$assetId.':'.$range->startDate.':'.$range->endDate,
            limitations: $limitations,
        );
    }
}
