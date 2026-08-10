<?php

namespace MoxDop\Website\Discovery;

use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Services\Integrations\DataForSeo\DataForSeoApiClient;
use App\Services\Integrations\DataForSeo\DataForSeoEndpointAllowlist;
use App\Services\Integrations\DataForSeo\DataForSeoException;
use App\Services\Integrations\EvidenceFreshnessGuard;
use App\Services\Integrations\PaidRequestExecutor;
use App\Services\Integrations\PaidRequestFingerprint;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Support\Carbon;
use MoxDop\Website\SeoIntelligence\DataForSeoIntegrationResolver;
use MoxDop\Website\SeoIntelligence\WebsiteDomainTarget;
use Throwable;

/**
 * Bounded competitor-candidate discovery via DataForSEO Labs Competitors Domain.
 * Official endpoint: POST /v3/dataforseo_labs/google/competitors_domain/live
 */
final class CompetitorDomainCollector
{
    public const string USE_CASE = 'website_discovery_competitors_domain';

    public const int LIMIT = 10;

    public const int TTL_DAYS = 14;

    public function __construct(
        private readonly ?DataForSeoIntegrationResolver $resolver = null,
        private readonly ?DataForSeoApiClient $client = null,
        private readonly ?PaidRequestExecutor $executor = null,
        private readonly ?EvidenceFreshnessGuard $freshness = null,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     message: string,
     *     provider: ?string,
     *     query_note: ?string,
     *     competitors: list<array{domain: string, intersections: int|null, avg_position: float|null}>,
     *     evidence: ?Evidence,
     *     provider_called: bool
     * }
     */
    public function collect(DigitalAsset $asset, Run $run, Carbon|\DateTimeInterface $observedAt): array
    {
        $resolver = $this->resolver ?? app(DataForSeoIntegrationResolver::class);
        $status = $resolver->status();

        if (! $status['configured'] || ! $status['integration'] instanceof CoreIntegration) {
            return [
                'status' => 'unavailable',
                'message' => 'Unavailable — external competitor intelligence provider is not configured.',
                'provider' => null,
                'query_note' => null,
                'competitors' => [],
                'evidence' => null,
                'provider_called' => false,
            ];
        }

        $target = WebsiteDomainTarget::fromAsset($asset);
        if ($target === null) {
            return [
                'status' => 'unavailable',
                'message' => 'Website domain is required for competitor candidate discovery.',
                'provider' => ProviderRegistry::DATAFORSEO,
                'query_note' => null,
                'competitors' => [],
                'evidence' => null,
                'provider_called' => false,
            ];
        }

        if (! $asset->hasSeoMarketConfigured()) {
            return [
                'status' => 'unavailable',
                'message' => 'Choose the Website SEO market and language before requesting competitor candidates.',
                'provider' => ProviderRegistry::DATAFORSEO,
                'query_note' => null,
                'competitors' => [],
                'evidence' => null,
                'provider_called' => false,
            ];
        }

        $integration = $status['integration'];
        $params = [
            'target' => $target,
            'location_code' => (int) $asset->seo_market_location_code,
            'language_code' => (string) $asset->seo_market_language_code,
            'limit' => self::LIMIT,
            'item_types' => ['organic'],
        ];

        $fingerprint = PaidRequestFingerprint::make(
            ProviderRegistry::DATAFORSEO,
            self::USE_CASE,
            DataForSeoEndpointAllowlist::LABS_GOOGLE_COMPETITORS_DOMAIN_LIVE,
            $params,
        );

        $executor = $this->executor ?? app(PaidRequestExecutor::class);
        $client = $this->client ?? app(DataForSeoApiClient::class);
        $freshness = $this->freshness ?? app(EvidenceFreshnessGuard::class);

        try {
            $outcome = $executor->executeOrReuse(
                $fingerprint,
                function () use ($asset, $run, $integration, $params, $fingerprint, $observedAt, $client, $freshness): array {
                    $task = [
                        'target' => $params['target'],
                        'location_code' => $params['location_code'],
                        'language_code' => $params['language_code'],
                        'item_types' => $params['item_types'],
                        'limit' => $params['limit'],
                        'order_by' => ['metrics.organic.count,desc'],
                    ];

                    $response = $client->postCompetitorsDomainLive($integration, [$task]);
                    $taskRow = $response->firstTask();
                    $taskStatus = isset($taskRow['status_code']) ? (int) $taskRow['status_code'] : null;
                    if ($taskStatus !== null && $taskStatus !== 20000) {
                        throw new DataForSeoException(
                            'DataForSEO competitors domain task failed.',
                            kind: DataForSeoException::KIND_PROVIDER_STATUS,
                            providerStatusCode: $taskStatus,
                        );
                    }

                    $items = $this->extractItems($response->firstResult());
                    $freshUntil = Carbon::now()->addDays(self::TTL_DAYS);
                    $cost = $response->cost ?? 0.0;

                    $evidence = Evidence::query()->create([
                        'run_id' => $run->id,
                        'digital_asset_id' => $asset->id,
                        'source_module' => DiscoveryConfig::MODULE_ID,
                        'type' => DiscoveryConfig::EVIDENCE_COMPETITOR_CANDIDATES,
                        'request_fingerprint' => $fingerprint,
                        'title' => 'Public competitor candidates',
                        'payload' => [
                            'ok' => true,
                            'provider' => ProviderRegistry::DATAFORSEO,
                            'endpoint' => DataForSeoEndpointAllowlist::LABS_GOOGLE_COMPETITORS_DOMAIN_LIVE,
                            'target' => $params['target'],
                            'location_code' => $params['location_code'],
                            'language_code' => $params['language_code'],
                            'retrieved_at' => Carbon::parse($observedAt)->toIso8601String(),
                            'normalization_version' => DiscoveryConfig::VERSION,
                            'competitors' => $items,
                            'query_note' => 'Organic keyword/domain overlap via DataForSEO Labs Competitors Domain',
                        ],
                        'observed_at' => $observedAt,
                        'fresh_until' => $freshUntil,
                    ]);

                    $meta = $freshness->providerCallRunMetadata(
                        ProviderRegistry::DATAFORSEO,
                        self::USE_CASE,
                        $fingerprint,
                        'MISS',
                        [
                            ...$response->costProvenanceMetadata(),
                            'provider_calls' => 1,
                            'target' => $params['target'],
                        ],
                    );

                    return [
                        'evidence' => $evidence,
                        'reported_cost_usd' => (float) $cost,
                        'metadata' => array_merge($meta, [
                            'competitors' => $items,
                        ]),
                    ];
                },
                forceRefresh: false,
            );
        } catch (Throwable $exception) {
            return [
                'status' => 'failed',
                'message' => 'Competitor candidate discovery failed safely: '.$exception->getMessage(),
                'provider' => ProviderRegistry::DATAFORSEO,
                'query_note' => null,
                'competitors' => [],
                'evidence' => null,
                'provider_called' => false,
            ];
        }

        if (! $outcome['provider_called'] && $outcome['evidence'] instanceof Evidence) {
            $payload = is_array($outcome['evidence']->payload) ? $outcome['evidence']->payload : [];
            $items = is_array($payload['competitors'] ?? null) ? $payload['competitors'] : [];

            // Attach a run-local Evidence copy referencing fresh cache for this Discovery Run.
            $linked = Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => DiscoveryConfig::MODULE_ID,
                'type' => DiscoveryConfig::EVIDENCE_COMPETITOR_CANDIDATES,
                'request_fingerprint' => $fingerprint,
                'title' => 'Public competitor candidates (fresh cache)',
                'payload' => array_merge($payload, [
                    'cache_status' => 'HIT',
                    'reused_evidence_id' => $outcome['evidence']->id,
                ]),
                'observed_at' => $observedAt,
                'fresh_until' => $outcome['evidence']->fresh_until,
            ]);

            return [
                'status' => 'succeeded',
                'message' => 'Fresh competitor Evidence reused; no DataForSEO request was made.',
                'provider' => ProviderRegistry::DATAFORSEO,
                'query_note' => 'Organic keyword/domain overlap via DataForSEO Labs Competitors Domain',
                'competitors' => $this->normalizeItems($items),
                'evidence' => $linked,
                'provider_called' => false,
            ];
        }

        $meta = is_array($outcome['metadata'] ?? null) ? $outcome['metadata'] : [];
        $items = is_array($meta['competitors'] ?? null) ? $meta['competitors'] : [];

        return [
            'status' => 'succeeded',
            'message' => 'Competitor candidates retrieved from DataForSEO.',
            'provider' => ProviderRegistry::DATAFORSEO,
            'query_note' => 'Organic keyword/domain overlap via DataForSEO Labs Competitors Domain',
            'competitors' => $this->normalizeItems($items),
            'evidence' => $outcome['evidence'] instanceof Evidence ? $outcome['evidence'] : null,
            'provider_called' => true,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $result
     * @return list<array{domain: string, intersections: int|null, avg_position: float|null}>
     */
    private function extractItems(?array $result): array
    {
        $items = is_array($result['items'] ?? null) ? $result['items'] : [];
        $out = [];
        $seen = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $domain = isset($item['domain']) && is_string($item['domain'])
                ? strtolower(trim($item['domain']))
                : '';
            if ($domain === '' || isset($seen[$domain])) {
                continue;
            }
            $seen[$domain] = true;

            $intersections = null;
            if (isset($item['intersections']) && is_numeric($item['intersections'])) {
                $intersections = (int) $item['intersections'];
            } elseif (isset($item['metrics']['organic']['count']) && is_numeric($item['metrics']['organic']['count'])) {
                $intersections = (int) $item['metrics']['organic']['count'];
            }

            $avg = null;
            if (isset($item['avg_position']) && is_numeric($item['avg_position'])) {
                $avg = (float) $item['avg_position'];
            } elseif (isset($item['metrics']['organic']['pos_avg']) && is_numeric($item['metrics']['organic']['pos_avg'])) {
                $avg = (float) $item['metrics']['organic']['pos_avg'];
            }

            $out[] = [
                'domain' => $domain,
                'intersections' => $intersections,
                'avg_position' => $avg,
            ];

            if (count($out) >= self::LIMIT) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<array{domain: string, intersections: int|null, avg_position: float|null}>
     */
    private function normalizeItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $domain = isset($item['domain']) && is_string($item['domain']) ? strtolower(trim($item['domain'])) : '';
            if ($domain === '') {
                continue;
            }
            $out[] = [
                'domain' => $domain,
                'intersections' => isset($item['intersections']) && is_numeric($item['intersections']) ? (int) $item['intersections'] : null,
                'avg_position' => isset($item['avg_position']) && is_numeric($item['avg_position']) ? (float) $item['avg_position'] : null,
            ];
        }

        return $out;
    }
}
