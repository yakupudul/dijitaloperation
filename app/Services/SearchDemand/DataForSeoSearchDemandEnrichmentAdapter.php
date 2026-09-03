<?php

namespace App\Services\SearchDemand;

use App\Contracts\SearchDemand\SearchDemandSerpEnrichmentAdapter;
use App\Models\DigitalAsset;
use App\Services\Integrations\DataForSeo\DataForSeoApiClient;
use App\Services\Integrations\DataForSeo\DataForSeoEndpointAllowlist;
use App\Services\Integrations\DataForSeo\DataForSeoException;
use App\Services\Integrations\DataForSeo\DataForSeoResponse;
use MoxDop\Website\SeoIntelligence\DataForSeoIntegrationResolver;

final class DataForSeoSearchDemandEnrichmentAdapter implements SearchDemandSerpEnrichmentAdapter
{
    public function __construct(
        private readonly DataForSeoApiClient $client,
        private readonly DataForSeoIntegrationResolver $integrations,
    ) {}

    public function provider(): string
    {
        return 'dataforseo';
    }

    public function readiness(): array
    {
        $status = $this->integrations->status();

        return [
            'configured' => (bool) $status['configured'],
            'message' => $status['configured'] ? null : $status['message'],
        ];
    }

    public function estimate(int $serpQueryCount, int $metricQueryCount, bool $queryExpansionMiss, int $depth): array
    {
        $serpRate = $this->nullableRate(config('moxdop.search_demand_enrichment.estimated_serp_cost_per_query_usd'));
        $metricRate = $this->nullableRate(config('moxdop.search_demand_enrichment.estimated_keyword_metric_batch_cost_usd'));
        $expansionRate = $this->nullableRate(config('moxdop.search_demand_enrichment.estimated_keyword_expansion_batch_cost_usd'));
        $unknownRate = ($serpQueryCount > 0 && $serpRate === null)
            || ($metricQueryCount > 0 && $metricRate === null)
            || ($queryExpansionMiss && $expansionRate === null);
        $depthMultiplier = (int) ceil($depth / 10);
        $estimated = $unknownRate
            ? null
            : ($serpQueryCount * ($serpRate ?? 0.0) * $depthMultiplier)
                + ($metricQueryCount > 0 ? ($metricRate ?? 0.0) : 0.0)
                + ($queryExpansionMiss ? ($expansionRate ?? 0.0) : 0.0);

        return [
            'estimated_cost_usd' => $estimated,
            'provider_request_count' => $serpQueryCount + ($metricQueryCount > 0 ? 1 : 0) + ($queryExpansionMiss ? 1 : 0),
            'basis' => [
                'kind' => 'deployment_configuration_estimate',
                'serp_query_count' => $serpQueryCount,
                'metric_query_count' => $metricQueryCount,
                'serp_depth' => $depth,
                'serp_depth_billing_units' => $depthMultiplier,
                'serp_tasks' => $serpQueryCount,
                'keyword_metric_tasks' => $metricQueryCount > 0 ? 1 : 0,
                'query_expansion_tasks' => $queryExpansionMiss ? 1 : 0,
                'serp_cost_per_query_usd' => $serpRate,
                'keyword_metric_batch_cost_usd' => $metricRate,
                'query_expansion_batch_cost_usd' => $expansionRate,
                'provider_quote' => false,
                'warning' => 'Final cost is the amount reported by DataForSEO after the paid requests.',
            ],
        ];
    }

    public function collectSerps(DigitalAsset $website, array $queries, int $depth, string $device): array
    {
        if (count($queries) !== 1) {
            throw new \InvalidArgumentException('DataForSEO Live SERP accepts exactly one task per API call.');
        }
        $integration = $this->configuredIntegration();
        $tasks = array_map(fn (array $query): array => [
            'language_code' => (string) $website->seo_market_language_code,
            'location_code' => (int) $website->seo_market_location_code,
            'keyword' => $query['query_text'],
            'device' => $device,
            'depth' => $depth,
            'tag' => 'portfolio_item:'.$query['portfolio_item_id'],
        ], $queries);
        $response = $this->client->postSerpGoogleOrganicLiveRegular($integration, $tasks);
        $observations = [];

        foreach ($response->tasks as $index => $task) {
            $fallback = $queries[$index] ?? null;
            $tag = data_get($task, 'data.tag');
            $itemId = is_string($tag) && preg_match('/^portfolio_item:(\d+)$/', $tag, $matches) === 1
                ? (int) $matches[1]
                : (int) ($fallback['portfolio_item_id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }

            $taskStatus = is_numeric($task['status_code'] ?? null) ? (int) $task['status_code'] : null;
            if ($taskStatus !== null && $taskStatus !== DataForSeoResponse::SUCCESS_STATUS) {
                $observations[$itemId] = [
                    'status' => 'failed',
                    'provider_task_id' => is_string($task['id'] ?? null) ? $task['id'] : null,
                    'error' => (string) ($task['status_message'] ?? 'DataForSEO SERP task failed.'),
                ];
                continue;
            }

            $result = is_array(($task['result'] ?? [])[0] ?? null) ? $task['result'][0] : [];
            $items = is_array($result['items'] ?? null) ? $result['items'] : [];
            $features = is_array($result['item_types'] ?? null) ? $result['item_types'] : [];
            $organic = [];
            $brandMatches = [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $type = trim((string) ($item['type'] ?? 'unknown'));
                if ($type !== '') {
                    $features[] = $type;
                }
                $candidateUrl = trim((string) ($item['url'] ?? ''));
                $candidateDomain = $candidateUrl !== '' ? $this->domain($candidateUrl) : null;
                if (in_array($type, ['organic', 'featured_snippet'], true) && $candidateUrl !== '' && $this->isBrandDomain($candidateDomain, (string) $website->domain)) {
                    $brandMatches[] = [
                        'rank_group' => is_numeric($item['rank_group'] ?? null) ? (int) $item['rank_group'] : null,
                        'url' => $candidateUrl,
                    ];
                }
                if ($type !== 'organic') {
                    continue;
                }
                $url = $candidateUrl;
                if ($url === '' || count($organic) >= $depth) {
                    continue;
                }
                $domain = $this->domain($url);
                $isBrand = $this->isBrandDomain($domain, (string) $website->domain);
                $organic[] = [
                    'rank_group' => is_numeric($item['rank_group'] ?? null) ? (int) $item['rank_group'] : null,
                    'rank_absolute' => is_numeric($item['rank_absolute'] ?? null) ? (int) $item['rank_absolute'] : null,
                    'url' => $url,
                    'domain' => $domain,
                    'title' => $this->nullableString($item['title'] ?? null),
                    'description' => $this->nullableString($item['description'] ?? $item['snippet'] ?? null),
                    'is_brand_domain' => $isBrand,
                    'observed_payload' => [
                        'type' => 'organic',
                        'rank_group' => $item['rank_group'] ?? null,
                        'rank_absolute' => $item['rank_absolute'] ?? null,
                        'breadcrumb' => $item['breadcrumb'] ?? null,
                    ],
                ];
            }

            $brandResult = collect($brandMatches)->sortBy(fn (array $match): int => $match['rank_group'] ?? PHP_INT_MAX)->first();
            $observations[$itemId] = [
                'status' => 'completed',
                'provider_task_id' => is_string($task['id'] ?? null) ? $task['id'] : null,
                'result_count' => is_numeric($result['se_results_count'] ?? null) ? (int) $result['se_results_count'] : null,
                'serp_features' => array_values(array_unique($features)),
                'brand_rank' => is_array($brandResult) ? $brandResult['rank_group'] : null,
                'brand_url' => is_array($brandResult) ? $brandResult['url'] : null,
                'organic_results' => $organic,
            ];
        }

        return [
            'endpoint' => DataForSeoEndpointAllowlist::SERP_GOOGLE_ORGANIC_LIVE_REGULAR,
            'request_payload' => $tasks,
            'response_payload' => $response->raw ?? [],
            'reported_cost_usd' => $this->reportedCost($response),
            'observations' => $observations,
        ];
    }

    public function collectKeywordMetrics(DigitalAsset $website, array $queries): array
    {
        $integration = $this->configuredIntegration();
        $task = [
            'keywords' => array_values(array_map(fn (array $query): string => $query['query_text'], $queries)),
            'language_code' => (string) $website->seo_market_language_code,
            'location_code' => (int) $website->seo_market_location_code,
            'include_adult_keywords' => false,
            'tag' => 'search_demand_keyword_metrics',
        ];
        $response = $this->client->postGoogleAdsSearchVolumeLive($integration, [$task]);
        $providerTask = $response->firstTask();
        $taskStatus = is_numeric($providerTask['status_code'] ?? null) ? (int) $providerTask['status_code'] : null;
        $taskError = $taskStatus !== null && $taskStatus !== DataForSeoResponse::SUCCESS_STATUS
            ? (string) ($providerTask['status_message'] ?? 'DataForSEO keyword-metrics task failed.')
            : null;
        $resultRows = is_array($providerTask['result'] ?? null) ? $providerTask['result'] : [];
        $idsByQuery = [];
        foreach ($queries as $query) {
            $idsByQuery[$this->fold($query['query_text'])] = $query['portfolio_item_id'];
        }
        $metrics = [];
        foreach ($resultRows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $keyword = trim((string) ($row['keyword'] ?? ''));
            $itemId = $idsByQuery[$this->fold($keyword)] ?? null;
            if ($itemId === null) {
                continue;
            }
            $metrics[(int) $itemId] = [
                'search_volume' => is_numeric($row['search_volume'] ?? null) ? (int) $row['search_volume'] : null,
                'cpc' => is_numeric($row['cpc'] ?? null) ? (float) $row['cpc'] : null,
                'competition' => $this->nullableString($row['competition'] ?? null),
                'competition_index' => is_numeric($row['competition_index'] ?? null) ? (int) $row['competition_index'] : null,
                'monthly_searches' => is_array($row['monthly_searches'] ?? null) ? $row['monthly_searches'] : null,
            ];
        }

        return [
            'endpoint' => DataForSeoEndpointAllowlist::KEYWORDS_DATA_GOOGLE_ADS_SEARCH_VOLUME_LIVE,
            'request_payload' => [$task],
            'response_payload' => $response->raw ?? [],
            'reported_cost_usd' => $this->reportedCost($response),
            'provider_task_id' => is_string($providerTask['id'] ?? null) ? $providerTask['id'] : null,
            'task_error' => $taskError,
            'metrics' => $metrics,
        ];
    }

    public function collectQueryExpansions(DigitalAsset $website, array $queries): array
    {
        $integration = $this->configuredIntegration();
        $limit = max(1, min(100, (int) config('moxdop.search_demand_enrichment.max_expansion_candidates', 50)));
        $task = [
            'keywords' => array_values(array_map(fn (array $query): string => $query['query_text'], $queries)),
            'language_code' => (string) $website->seo_market_language_code,
            'location_code' => (int) $website->seo_market_location_code,
            'include_serp_info' => false,
            'limit' => $limit,
            'tag' => 'search_demand_query_expansion',
        ];
        $response = $this->client->postKeywordIdeasLive($integration, [$task]);
        $providerTask = $response->firstTask();
        $taskStatus = is_numeric($providerTask['status_code'] ?? null) ? (int) $providerTask['status_code'] : null;
        $taskError = $taskStatus !== null && $taskStatus !== DataForSeoResponse::SUCCESS_STATUS
            ? (string) ($providerTask['status_message'] ?? 'DataForSEO query-expansion task failed.')
            : null;
        $result = $response->firstResult() ?? [];
        $items = is_array($result['items'] ?? null) ? $result['items'] : [];
        $candidates = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $info = is_array($item['keyword_info'] ?? null) ? $item['keyword_info'] : [];
            $keyword = trim((string) ($item['keyword'] ?? ''));
            if ($keyword === '') {
                continue;
            }
            $candidates[] = [
                'keyword' => $keyword,
                'search_volume' => is_numeric($info['search_volume'] ?? null) ? (int) $info['search_volume'] : null,
                'cpc' => is_numeric($info['cpc'] ?? null) ? (float) $info['cpc'] : null,
                'competition' => $this->nullableString($info['competition_level'] ?? null),
                'competition_index' => is_numeric($info['competition'] ?? null)
                    ? (int) round((float) $info['competition'] * 100)
                    : null,
                'monthly_searches' => is_array($info['monthly_searches'] ?? null) ? $info['monthly_searches'] : null,
            ];
            if (count($candidates) >= $limit) {
                break;
            }
        }

        return [
            'endpoint' => DataForSeoEndpointAllowlist::LABS_GOOGLE_KEYWORD_IDEAS_LIVE,
            'request_payload' => [$task],
            'response_payload' => $response->raw ?? [],
            'reported_cost_usd' => $this->reportedCost($response),
            'task_error' => $taskError,
            'candidates' => $candidates,
        ];
    }

    private function configuredIntegration(): \App\Models\CoreIntegration
    {
        $integration = $this->integrations->active();
        $readiness = $this->readiness();
        if (! $readiness['configured'] || $integration === null) {
            throw new DataForSeoException(
                $readiness['message'] ?? 'DataForSEO Integration is not configured.',
                kind: DataForSeoException::KIND_ENDPOINT_NOT_ALLOWED,
            );
        }

        return $integration;
    }

    private function reportedCost(DataForSeoResponse $response): ?float
    {
        if ($response->cost !== null) {
            return $response->cost;
        }
        $costs = collect($response->tasks)
            ->map(fn (array $task): mixed => $task['cost'] ?? null)
            ->filter(fn (mixed $cost): bool => is_numeric($cost));

        return $costs->isEmpty() ? null : (float) $costs->sum();
    }

    private function domain(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return preg_replace('/^www\./i', '', strtolower($host)) ?: null;
    }

    private function isBrandDomain(?string $resultDomain, string $brandDomain): bool
    {
        $brand = $this->domain(str_contains($brandDomain, '://') ? $brandDomain : 'https://'.$brandDomain);
        if ($resultDomain === null || $brand === null) {
            return false;
        }

        return $resultDomain === $brand || str_ends_with($resultDomain, '.'.$brand);
    }

    private function fold(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value), 'UTF-8');
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function nullableRate(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value >= 0 ? (float) $value : null;
    }
}
