<?php

namespace App\Contracts\SearchDemand;

use App\Models\DigitalAsset;

interface SearchDemandSerpEnrichmentAdapter
{
    public function provider(): string;

    /** @return array{configured: bool, message: ?string} */
    public function readiness(): array;

    /**
     * @return array{estimated_cost_usd: ?float, provider_request_count: int, basis: array<string, mixed>}
     */
    public function estimate(int $serpQueryCount, int $metricQueryCount, bool $queryExpansionMiss, int $depth): array;

    /**
     * @param  list<array{portfolio_item_id: int, query_text: string}>  $queries
     * @return array{
     *   endpoint: string,
     *   request_payload: list<array<string, mixed>>,
     *   response_payload: array<string, mixed>,
     *   reported_cost_usd: ?float,
     *   observations: array<int, array<string, mixed>>
     * }
     */
    public function collectSerps(DigitalAsset $website, array $queries, int $depth, string $device): array;

    /**
     * @param  list<array{portfolio_item_id: int, query_text: string}>  $queries
     * @return array{
     *   endpoint: string,
     *   request_payload: list<array<string, mixed>>,
     *   response_payload: array<string, mixed>,
     *   reported_cost_usd: ?float,
     *   provider_task_id: ?string,
     *   metrics: array<int, array<string, mixed>>
     * }
     */
    public function collectKeywordMetrics(DigitalAsset $website, array $queries): array;

    /**
     * @param  list<array{portfolio_item_id: int, query_text: string}>  $queries
     * @return array{
     *   endpoint: string,
     *   request_payload: list<array<string, mixed>>,
     *   response_payload: array<string, mixed>,
     *   reported_cost_usd: ?float,
     *   candidates: list<array<string, mixed>>
     * }
     */
    public function collectQueryExpansions(DigitalAsset $website, array $queries): array;
}
