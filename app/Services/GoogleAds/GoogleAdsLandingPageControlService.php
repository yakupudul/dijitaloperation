<?php

namespace App\Services\GoogleAds;

use App\Models\DigitalAsset;
use App\Models\GoogleAdsBudgetPlan;
use App\Services\GoogleAds\Support\GoogleAdsBindingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decision-oriented Google Ads landing-page control center.
 *
 * Provider performance is read only from the canonical Google Ads pool. Website,
 * GA4, Search Console, CRM and business-outcome facts are never inferred here.
 */
final class GoogleAdsLandingPageControlService
{
    public function __construct(
        private readonly GoogleAdsSpecialistBindingResolver $bindingResolver,
        private readonly GoogleAdsPoolReadRepository $pool,
    ) {}

    /** @return array<string,mixed> */
    public function workspace(string $assetId, ?string $start, ?string $end): array
    {
        $binding = $this->bindingResolver->resolve($assetId);
        if (
            $binding->mode !== GoogleAdsBindingMode::RealBound
            || $binding->externalResourceId === null
            || $binding->customerId === null
            || ! ctype_digit($assetId)
            || ! filled($start)
            || ! filled($end)
        ) {
            return $this->emptyWorkspace('not_available');
        }

        if (! Schema::hasTable('google_ads_landing_page_daily')) {
            return $this->emptyWorkspace('dataset_unavailable');
        }

        $digitalAssetId = (int) $assetId;
        $externalResourceId = (int) $binding->externalResourceId;
        $customerId = (string) $binding->customerId;
        $currency = (string) ($binding->currency ?? 'XXX');

        $providerRows = $this->pool->topLandingPages(
            $digitalAssetId,
            $externalResourceId,
            $customerId,
            (string) $start,
            (string) $end,
            250,
        );

        $latestMeta = $this->latestMetadataByUrl(
            $digitalAssetId,
            $externalResourceId,
            $customerId,
            (string) $start,
            (string) $end,
        );

        $rows = collect($providerRows)->map(function (array $row) use ($latestMeta): array {
            $url = (string) ($row['landing_page'] ?? '');
            $spend = (float) ($row['cost_amount'] ?? 0);
            $clicks = (int) ($row['clicks'] ?? 0);
            $impressions = (int) ($row['impressions'] ?? 0);
            $conversions = (float) ($row['conversions'] ?? 0);
            $meta = $latestMeta[$url] ?? [];

            return [
                'id' => 'lp-'.substr(sha1($url), 0, 16),
                'url' => $url,
                'host' => $this->host($url),
                'path' => $this->path($url),
                'spend' => round($spend, 2),
                'clicks' => $clicks,
                'impressions' => $impressions,
                'conversions' => $conversions,
                'ctr' => $impressions > 0 ? ($clicks / $impressions) * 100 : null,
                'cvr' => $clicks > 0 ? ($conversions / $clicks) * 100 : null,
                'cpa' => $conversions > 0 ? $spend / $conversions : null,
                'currency' => $row['currency'] ?? null,
                'speed_score' => $this->numericMeta($meta, ['speed_score', 'speedScore']),
                'mobile_friendly_clicks_pct' => $this->numericMeta($meta, ['mobile_friendly_clicks_percentage', 'mobileFriendlyClicksPercentage']),
                'unexpanded_final_url' => (string) ($meta['unexpanded_final_url'] ?? $url),
                'provider_observed_at' => $meta['_reporting_date'] ?? null,
                'decision' => null,
                'decision_group' => 'monitor',
                'decision_reason' => null,
                'decision_severity' => 'info',
            ];
        })->filter(fn (array $row): bool => $row['url'] !== '')->values();

        if ($rows->isEmpty()) {
            return array_merge($this->emptyWorkspace('no_rows'), [
                'connected' => true,
                'currency' => $currency,
                'period' => ['start' => $start, 'end' => $end],
                'readiness' => $this->readiness($digitalAssetId, collect()),
            ]);
        }

        $targetCpa = $this->targetCpa($digitalAssetId, (string) $start, (string) $end);
        $benchmarks = $this->benchmarks($rows, $targetCpa);
        $decisions = collect();

        $rows = $rows->map(function (array $row) use ($benchmarks, $targetCpa, $decisions): array {
            $result = $this->classify($row, $benchmarks, $targetCpa);
            $row = array_merge($row, $result['row']);
            foreach ($result['decisions'] as $decision) {
                $decisions->push($decision);
            }

            return $row;
        })->sortByDesc('spend')->values();

        $spend = (float) $rows->sum('spend');
        $clicks = (int) $rows->sum('clicks');
        $impressions = (int) $rows->sum('impressions');
        $conversions = (float) $rows->sum('conversions');
        $riskRows = $rows->where('decision_group', 'risk')->values();
        $strongRows = $rows->where('decision_group', 'strong')->values();
        $croRows = $rows->where('decision_group', 'cro')->values();
        $top3Spend = (float) $rows->take(3)->sum('spend');

        $decisions = $decisions
            ->sortBy(fn (array $item): array => [
                $this->severityPriority((string) $item['severity']),
                -((float) ($item['spend'] ?? 0)),
            ])->values();

        return [
            'connected' => true,
            'state' => 'real',
            'currency' => $currency,
            'period' => ['start' => $start, 'end' => $end],
            'summary' => [
                'urls' => $rows->count(),
                'spend' => round($spend, 2),
                'clicks' => $clicks,
                'impressions' => $impressions,
                'conversions' => round($conversions, 2),
                'ctr' => $impressions > 0 ? ($clicks / $impressions) * 100 : null,
                'cvr' => $clicks > 0 ? ($conversions / $clicks) * 100 : null,
                'cpa' => $conversions > 0 ? $spend / $conversions : null,
                'risk_spend' => round((float) $riskRows->sum('spend'), 2),
                'risk_pages' => $riskRows->count(),
                'strong_pages' => $strongRows->count(),
                'cro_pages' => $croRows->count(),
                'top3_spend_share' => $spend > 0 ? ($top3Spend / $spend) * 100 : null,
                'target_cpa' => $targetCpa,
            ],
            'benchmarks' => $benchmarks,
            'rows' => $rows->all(),
            'decision_inbox' => $decisions->all(),
            'opportunity_map' => [
                'strong' => $strongRows->all(),
                'cro' => $croRows->all(),
                'risk' => $riskRows->all(),
                'monitor' => $rows->where('decision_group', 'monitor')->values()->all(),
            ],
            'readiness' => $this->readiness($digitalAssetId, $rows),
            'boundaries' => [
                'provider' => 'Spend, clicks, impressions and conversions are Google Ads provider facts from the landing-page dataset.',
                'risk' => 'Risk spend is a MOXDOP review classification; it is not proof that the spend was wasted.',
                'cross_asset' => 'Website, GA4, Search Console and CRM facts remain unavailable until a canonical cross-asset join exists.',
                'write' => 'This control center does not automatically change final URLs, exclusions, bids or campaigns.',
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    public function findRow(string $assetId, ?string $start, ?string $end, string $rowId): ?array
    {
        return collect($this->workspace($assetId, $start, $end)['rows'] ?? [])->firstWhere('id', $rowId);
    }

    /** @param Collection<int,array<string,mixed>> $rows @return array<string,mixed> */
    private function benchmarks(Collection $rows, ?float $targetCpa): array
    {
        $spends = $rows->pluck('spend')->filter(fn ($v): bool => is_numeric($v) && (float) $v > 0)->map(fn ($v): float => (float) $v)->sort()->values();
        $clicks = $rows->pluck('clicks')->filter(fn ($v): bool => is_numeric($v) && (int) $v > 0)->map(fn ($v): float => (float) $v)->sort()->values();
        $cpas = $rows->pluck('cpa')->filter(fn ($v): bool => is_numeric($v) && (float) $v > 0)->map(fn ($v): float => (float) $v)->sort()->values();
        $cvrs = $rows->pluck('cvr')->filter(fn ($v): bool => is_numeric($v) && (float) $v >= 0)->map(fn ($v): float => (float) $v)->sort()->values();

        $totalSpend = (float) $rows->sum('spend');
        $totalConversions = (float) $rows->sum('conversions');
        $totalClicks = (int) $rows->sum('clicks');

        return [
            'target_cpa' => $targetCpa,
            'account_cpa' => $totalConversions > 0 ? $totalSpend / $totalConversions : null,
            'account_cvr' => $totalClicks > 0 ? ($totalConversions / $totalClicks) * 100 : null,
            'spend_median' => $this->percentile($spends, 0.50),
            'spend_p75' => $this->percentile($spends, 0.75),
            'clicks_p75' => $this->percentile($clicks, 0.75),
            'cpa_p25' => $this->percentile($cpas, 0.25),
            'cpa_p75' => $this->percentile($cpas, 0.75),
            'cvr_p25' => $this->percentile($cvrs, 0.25),
            'cvr_p75' => $this->percentile($cvrs, 0.75),
            'method' => 'relative_distribution',
        ];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $benchmarks @return array{row:array<string,mixed>,decisions:list<array<string,mixed>>} */
    private function classify(array $row, array $benchmarks, ?float $targetCpa): array
    {
        $spend = (float) $row['spend'];
        $clicks = (int) $row['clicks'];
        $conversions = (float) $row['conversions'];
        $cpa = is_numeric($row['cpa']) ? (float) $row['cpa'] : null;
        $cvr = is_numeric($row['cvr']) ? (float) $row['cvr'] : null;
        $p75Spend = (float) ($benchmarks['spend_p75'] ?? 0);
        $medianSpend = (float) ($benchmarks['spend_median'] ?? 0);
        $p75Clicks = (float) ($benchmarks['clicks_p75'] ?? 0);
        $p25Cpa = $benchmarks['cpa_p25'];
        $p75Cpa = $benchmarks['cpa_p75'];
        $p25Cvr = $benchmarks['cvr_p25'];
        $p75Cvr = $benchmarks['cvr_p75'];
        $decisions = [];

        $highExposure = $spend > 0 && (($p75Spend > 0 && $spend >= $p75Spend) || ($p75Clicks > 0 && $clicks >= $p75Clicks));
        $materialExposure = $spend > 0 && ($medianSpend <= 0 || $spend >= $medianSpend);

        if ($conversions <= 0 && $highExposure) {
            $decisions[] = $this->decision('critical', 'zero_conversion_high_exposure', $row, 'risk');
        } elseif ($conversions <= 0 && $spend > 0) {
            $decisions[] = $this->decision('review', 'zero_conversion', $row, 'risk');
        }

        if ($cpa !== null && $materialExposure) {
            if ($targetCpa !== null && $targetCpa > 0 && $cpa > $targetCpa * 1.5) {
                $decisions[] = $this->decision('review', 'cpa_above_target', $row, 'risk', ['target_cpa' => $targetCpa]);
            } elseif ($targetCpa === null && is_numeric($p75Cpa) && $cpa >= (float) $p75Cpa) {
                $decisions[] = $this->decision('review', 'cpa_high_relative', $row, 'risk');
            }
        }

        if ($cvr !== null && is_numeric($p25Cvr) && $cvr <= (float) $p25Cvr && $materialExposure && $conversions > 0) {
            $decisions[] = $this->decision('review', 'low_cvr', $row, 'cro');
        }

        if (is_numeric($row['speed_score'])) {
            $speed = (float) $row['speed_score'];
            if ($speed <= 4) {
                $decisions[] = $this->decision('critical', 'slow_provider_speed', $row, 'cro');
            } elseif ($speed <= 6) {
                $decisions[] = $this->decision('review', 'medium_provider_speed', $row, 'cro');
            }
        }

        if (is_numeric($row['mobile_friendly_clicks_pct'])) {
            $mobile = (float) $row['mobile_friendly_clicks_pct'];
            if ($mobile < 80) {
                $decisions[] = $this->decision('review', 'mobile_friendly_low', $row, 'cro');
            }
        }

        $strongByTarget = $targetCpa !== null && $cpa !== null && $cpa <= $targetCpa;
        $strongByDistribution = $targetCpa === null
            && $cpa !== null
            && is_numeric($p25Cpa)
            && $cpa <= (float) $p25Cpa;
        $strongCvr = $cvr !== null && is_numeric($p75Cvr) && $cvr >= (float) $p75Cvr;

        if ($conversions > 0 && $materialExposure && ($strongByTarget || $strongByDistribution) && $strongCvr) {
            $decisions[] = $this->decision('positive', 'strong_page', $row, 'strong');
        }

        $primary = collect($decisions)->sortBy(fn (array $item): array => [
            $this->severityPriority((string) $item['severity']),
            $this->groupPriority((string) $item['group']),
        ])->first();

        if (! is_array($primary)) {
            $primary = $this->decision('info', 'monitor', $row, 'monitor');
        }

        return [
            'row' => [
                'decision' => $primary['code'],
                'decision_group' => $primary['group'],
                'decision_reason' => $primary['message'],
                'decision_severity' => $primary['severity'],
            ],
            'decisions' => $decisions,
        ];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $extra @return array<string,mixed> */
    private function decision(string $severity, string $code, array $row, string $group, array $extra = []): array
    {
        return [
            'severity' => $severity,
            'code' => $code,
            'group' => $group,
            'row_id' => $row['id'],
            'url' => $row['url'],
            'spend' => $row['spend'],
            'clicks' => $row['clicks'],
            'conversions' => $row['conversions'],
            'cpa' => $row['cpa'],
            'cvr' => $row['cvr'],
            'message' => $code,
        ] + $extra;
    }

    /** @return array<string,array<string,mixed>> */
    private function latestMetadataByUrl(int $digitalAssetId, int $externalResourceId, string $customerId, string $start, string $end): array
    {
        $central = DB::table('google_ads_landing_page_daily')
            ->where('external_resource_id', $externalResourceId)
            ->where('customer_id', $customerId)
            ->whereNull('digital_asset_id')
            ->whereBetween('reporting_date', [$start, $end])
            ->exists();

        $query = DB::table('google_ads_landing_page_daily')
            ->where('external_resource_id', $externalResourceId)
            ->where('customer_id', $customerId)
            ->whereBetween('reporting_date', [$start, $end]);
        $central ? $query->whereNull('digital_asset_id') : $query->where('digital_asset_id', $digitalAssetId);

        return $query
            ->orderByDesc('reporting_date')
            ->orderByDesc('last_collected_at')
            ->get(['landing_page', 'reporting_date', 'metadata'])
            ->unique('landing_page')
            ->mapWithKeys(function (object $row): array {
                $meta = $this->decodeMetadata($row->metadata);
                $meta['_reporting_date'] = (string) $row->reporting_date;
                return [(string) $row->landing_page => $meta];
            })->all();
    }

    private function targetCpa(int $digitalAssetId, string $start, string $end): ?float
    {
        if (! Schema::hasTable('google_ads_budget_plans')) {
            return null;
        }

        $value = GoogleAdsBudgetPlan::query()
            ->where('digital_asset_id', $digitalAssetId)
            ->whereDate('period_start', $start)
            ->whereDate('period_end', $end)
            ->value('target_cpa');

        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    /** @param Collection<int,array<string,mixed>> $rows @return array<string,array<string,mixed>> */
    private function readiness(int $digitalAssetId, Collection $rows): array
    {
        $asset = DigitalAsset::query()->find($digitalAssetId);
        $brandId = $asset?->brand_id;
        $types = $brandId !== null
            ? DigitalAsset::query()->where('brand_id', $brandId)->pluck('type')->map(fn ($v) => strtolower((string) $v))->all()
            : [];

        $speedCoverage = $rows->filter(fn (array $row): bool => is_numeric($row['speed_score'] ?? null))->count();
        $mobileCoverage = $rows->filter(fn (array $row): bool => is_numeric($row['mobile_friendly_clicks_pct'] ?? null))->count();

        return [
            'google_landing_health' => [
                'state' => ($speedCoverage + $mobileCoverage) > 0 ? 'partial' : 'unavailable',
                'coverage' => max($speedCoverage, $mobileCoverage),
                'note' => ($speedCoverage + $mobileCoverage) > 0
                    ? 'Google Ads landing-page mobile/speed signals are available for part of the observed URLs.'
                    : 'Google Ads mobile-friendly and speed fields are not present in the currently collected canonical rows yet.',
            ],
            'website' => [
                'state' => in_array('website', $types, true) ? 'asset_available' : 'unavailable',
                'note' => in_array('website', $types, true)
                    ? 'A Website asset exists for this brand, but technical CRO data is not canonically joined to Google Ads landing pages yet.'
                    : 'No Website asset is available for canonical technical/CRO cross-analysis.',
            ],
            'ga4' => [
                'state' => in_array('ga4', $types, true) ? 'asset_available' : 'unavailable',
                'note' => in_array('ga4', $types, true)
                    ? 'A GA4 asset exists, but sessions, engagement and CTA events are not canonically joined by landing URL yet.'
                    : 'No GA4 asset is available for behavioral landing-page analysis.',
            ],
            'search_console' => [
                'state' => in_array('search_console', $types, true) ? 'asset_available' : 'unavailable',
                'note' => in_array('search_console', $types, true)
                    ? 'A Search Console asset exists, but organic page evidence is not joined to this paid landing-page workspace yet.'
                    : 'No Search Console asset is available for page-level cross-analysis.',
            ],
            'intent_page_match' => [
                'state' => 'unavailable',
                'note' => 'Search-term/keyword → landing-page association is not canonical in the current pool, so MOXDOP does not guess message or intent fit.',
            ],
            'expanded_url' => [
                'state' => 'unavailable',
                'note' => 'Expanded final URL and landing-page source (advertiser vs automatic) are not canonical in the current landing-page dataset yet.',
            ],
            'business_outcomes' => [
                'state' => 'unavailable',
                'note' => 'Qualified lead, sale and verified revenue are not attributed to landing URLs in the current canonical model.',
            ],
        ];
    }

    private function percentile(Collection $values, float $p): ?float
    {
        if ($values->isEmpty()) {
            return null;
        }
        if ($values->count() === 1) {
            return (float) $values->first();
        }

        $position = ($values->count() - 1) * $p;
        $lower = (int) floor($position);
        $upper = (int) ceil($position);
        if ($lower === $upper) {
            return (float) $values[$lower];
        }

        $weight = $position - $lower;
        return ((float) $values[$lower] * (1 - $weight)) + ((float) $values[$upper] * $weight);
    }

    /** @param array<string,mixed> $meta @param list<string> $keys */
    private function numericMeta(array $meta, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = data_get($meta, $key);
            if (is_numeric($value)) {
                return (float) $value;
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function decodeMetadata(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private function host(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?: '');
    }

    private function path(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?: '');
        return $query !== '' ? $path.'?'.$query : $path;
    }

    private function severityPriority(string $severity): int
    {
        return match ($severity) {
            'critical' => 1,
            'review' => 2,
            'positive' => 3,
            default => 4,
        };
    }

    private function groupPriority(string $group): int
    {
        return match ($group) {
            'risk' => 1,
            'cro' => 2,
            'strong' => 3,
            default => 4,
        };
    }

    /** @return array<string,mixed> */
    private function emptyWorkspace(string $state): array
    {
        return [
            'connected' => false,
            'state' => $state,
            'currency' => 'XXX',
            'period' => null,
            'summary' => [
                'urls' => 0,
                'spend' => null,
                'clicks' => null,
                'impressions' => null,
                'conversions' => null,
                'ctr' => null,
                'cvr' => null,
                'cpa' => null,
                'risk_spend' => null,
                'risk_pages' => 0,
                'strong_pages' => 0,
                'cro_pages' => 0,
                'top3_spend_share' => null,
                'target_cpa' => null,
            ],
            'benchmarks' => [],
            'rows' => [],
            'decision_inbox' => [],
            'opportunity_map' => ['strong' => [], 'cro' => [], 'risk' => [], 'monitor' => []],
            'readiness' => [],
            'boundaries' => [],
        ];
    }
}
