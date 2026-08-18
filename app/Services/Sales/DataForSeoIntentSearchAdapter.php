<?php

namespace App\Services\Sales;

use App\Services\Integrations\DataForSeo\DataForSeoApiClient;
use App\Services\Integrations\DataForSeo\DataForSeoEndpointAllowlist;
use App\Services\Integrations\DataForSeo\DataForSeoException;
use App\Support\Sales\IntentSearchConfig;
use MoxDop\Website\SeoIntelligence\DataForSeoIntegrationResolver;

/**
 * Single V1 Intent Search adapter: DataForSEO Google Organic SERP Live Regular.
 */
final class DataForSeoIntentSearchAdapter
{
    public function __construct(
        private readonly DataForSeoApiClient $client,
        private readonly DataForSeoIntegrationResolver $integrations,
    ) {}

    /**
     * @return array{reality: string, configured: bool, message: ?string}
     */
    public function reality(): array
    {
        $status = $this->integrations->status();

        if (! $status['configured']) {
            return [
                'reality' => 'unavailable',
                'configured' => false,
                'message' => $status['message'] ?? __('operator.sales_intent.provider_unconfigured'),
            ];
        }

        if (! IntentSearchConfig::paidCallsEnabled()) {
            return [
                'reality' => 'unavailable',
                'configured' => true,
                'message' => __('operator.sales_intent.paid_calls_off'),
            ];
        }

        return [
            'reality' => 'real',
            'configured' => true,
            'message' => null,
        ];
    }

    /**
     * @return list<array{
     *     query: string,
     *     source_url: string,
     *     source_title: string,
     *     observed_snippet: string,
     *     rank: ?int,
     *     cost_usd: ?float
     * }>
     */
    public function search(string $query, string $languageCode = 'tr', string $locationName = 'Turkey'): array
    {
        $reality = $this->reality();
        if ($reality['reality'] !== 'real') {
            throw new DataForSeoException(
                (string) $reality['message'],
                kind: DataForSeoException::KIND_ENDPOINT_NOT_ALLOWED,
            );
        }

        $integration = $this->integrations->active();
        if ($integration === null) {
            return [];
        }

        $response = $this->client->postSerpGoogleOrganicLiveRegular($integration, [[
            'language_code' => $languageCode !== '' ? $languageCode : 'tr',
            'location_name' => $locationName !== '' ? $locationName : 'Turkey',
            'keyword' => $query,
            'depth' => IntentSearchConfig::maxResultsPerQuery(),
        ]]);

        $tasks = $response->tasks;
        $cost = $response->cost;

        $items = [];
        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            $results = is_array($task['result'] ?? null) ? $task['result'] : [];
            foreach ($results as $result) {
                if (! is_array($result)) {
                    continue;
                }
                $organic = is_array($result['items'] ?? null) ? $result['items'] : [];
                foreach ($organic as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $url = trim((string) ($item['url'] ?? ''));
                    if ($url === '') {
                        continue;
                    }
                    $items[] = [
                        'query' => $query,
                        'source_url' => $url,
                        'source_title' => (string) ($item['title'] ?? ''),
                        'observed_snippet' => (string) ($item['description'] ?? $item['snippet'] ?? ''),
                        'rank' => isset($item['rank_group']) ? (int) $item['rank_group'] : null,
                        'cost_usd' => $cost,
                    ];
                    if (count($items) >= IntentSearchConfig::maxResultsPerQuery()) {
                        return $items;
                    }
                }
            }
        }

        return $items;
    }

    public function endpoint(): string
    {
        return DataForSeoEndpointAllowlist::SERP_GOOGLE_ORGANIC_LIVE_REGULAR;
    }
}
