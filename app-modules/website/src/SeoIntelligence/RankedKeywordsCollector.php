<?php

namespace MoxDop\Website\SeoIntelligence;

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

/**
 * Website collector for DataForSEO Labs Google Ranked Keywords (organic).
 */
final class RankedKeywordsCollector
{
    public function __construct(
        private readonly DataForSeoApiClient $client,
        private readonly PaidRequestExecutor $executor,
        private readonly EvidenceFreshnessGuard $freshness,
        private readonly RankedKeywordsNormalizer $normalizer,
    ) {}

    /**
     * @return array<string, mixed> canonical fingerprint parameters
     */
    public function fingerprintParameters(DigitalAsset $asset, string $target): array
    {
        return [
            'target' => $target,
            'location_code' => (int) $asset->seo_market_location_code,
            'language_code' => (string) $asset->seo_market_language_code,
            'item_types' => ['organic'],
            'include_clickstream_data' => false,
            'historical_serp_mode' => 'live',
            'limit' => SeoIntelligenceConfig::rankedKeywordsLimit(),
            'order_by' => ['keyword_data.keyword_info.search_volume,desc'],
        ];
    }

    public function fingerprint(DigitalAsset $asset, string $target): string
    {
        return PaidRequestFingerprint::make(
            ProviderRegistry::DATAFORSEO,
            SeoIntelligenceConfig::rankedKeywordsUseCase(),
            DataForSeoEndpointAllowlist::LABS_GOOGLE_RANKED_KEYWORDS_LIVE,
            $this->fingerprintParameters($asset, $target),
        );
    }

    /**
     * @return array{
     *     ok: bool,
     *     run: Run,
     *     provider_called: bool,
     *     reported_cost_usd: float,
     *     cache_status: string,
     *     message: string,
     *     evidence_ids: list<int>
     * }
     */
    public function collect(DigitalAsset $asset, CoreIntegration $integration, bool $forceRefresh = false): array
    {
        $target = WebsiteDomainTarget::fromAsset($asset);
        if ($target === null) {
            throw new \InvalidArgumentException('Website domain is required for SEO keyword visibility.');
        }

        if (! $asset->hasSeoMarketConfigured()) {
            throw new \InvalidArgumentException('Choose the Website SEO market and language before running external keyword analysis.');
        }

        $fingerprint = $this->fingerprint($asset, $target);
        $params = $this->fingerprintParameters($asset, $target);
        $locationName = (string) ($asset->seo_market_location_name ?: $asset->seo_market_location_code);
        $languageName = (string) ($asset->seo_market_language_name ?: $asset->seo_market_language_code);

        $outcome = $this->executor->executeOrReuse(
            $fingerprint,
            function () use ($asset, $integration, $target, $fingerprint, $params, $locationName, $languageName): array {
                return $this->paidCollect(
                    $asset,
                    $integration,
                    $target,
                    $fingerprint,
                    $params,
                    $locationName,
                    $languageName,
                );
            },
            $forceRefresh,
        );

        if (! $outcome['provider_called']) {
            /** @var Evidence $evidence */
            $evidence = $outcome['evidence'];
            $run = $this->createCacheHitRun($asset, $integration, $fingerprint, $evidence, $locationName, $languageName, $target);

            return [
                'ok' => true,
                'run' => $run,
                'provider_called' => false,
                'reported_cost_usd' => 0.0,
                'cache_status' => $outcome['cache_status'],
                'message' => 'Fresh ranked-keyword Evidence reused; no DataForSEO request was made.',
                'evidence_ids' => [(int) $evidence->id],
            ];
        }

        /** @var array{run: Run, evidence_ids: list<int>, reported_cost_usd: float, message: string} $meta */
        $meta = $outcome['metadata'];

        return [
            'ok' => true,
            'run' => $meta['run'],
            'provider_called' => true,
            'reported_cost_usd' => $outcome['reported_cost_usd'],
            'cache_status' => $outcome['cache_status'],
            'message' => $meta['message'],
            'evidence_ids' => $meta['evidence_ids'],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{evidence: Evidence, reported_cost_usd: float, metadata: array<string, mixed>}
     */
    private function paidCollect(
        DigitalAsset $asset,
        CoreIntegration $integration,
        string $target,
        string $fingerprint,
        array $params,
        string $locationName,
        string $languageName,
    ): array {
        $startedAt = now();
        $run = Run::query()->create([
            'digital_asset_id' => $asset->id,
            'core_connection_id' => null,
            'core_asset_binding_id' => null,
            'module_id' => 'website',
            'status' => 'running',
            'started_at' => $startedAt,
            'finished_at' => null,
            'metadata' => [
                'trigger' => 'refresh_seo_intelligence',
                'capability' => SeoIntelligenceConfig::CAPABILITY_RANKED,
                'provider' => ProviderRegistry::DATAFORSEO,
                'use_case' => SeoIntelligenceConfig::rankedKeywordsUseCase(),
                'request_fingerprint' => $fingerprint,
                'cache_status' => 'MISS',
                'provider_calls' => 0,
                'reported_cost_usd' => 0.0,
                'target' => $target,
                'market' => [
                    'location_code' => $params['location_code'],
                    'location_name' => $locationName,
                    'language_code' => $params['language_code'],
                    'language_name' => $languageName,
                ],
                'integration_id' => $integration->id,
                'integration_name' => $integration->name,
            ],
        ]);

        try {
            $task = [
                'target' => $target,
                'location_code' => $params['location_code'],
                'language_code' => $params['language_code'],
                'item_types' => $params['item_types'],
                'include_clickstream_data' => false,
                'historical_serp_mode' => 'live',
                'limit' => $params['limit'],
                'order_by' => $params['order_by'],
            ];

            $response = $this->client->postRankedKeywordsLive($integration, [$task]);
            $taskRow = $response->firstTask();
            $taskStatus = isset($taskRow['status_code']) ? (int) $taskRow['status_code'] : null;

            if ($taskStatus !== null && $taskStatus !== 20000) {
                throw new DataForSeoException(
                    'DataForSEO ranked keywords task failed.',
                    kind: DataForSeoException::KIND_PROVIDER_STATUS,
                    providerStatusCode: $taskStatus,
                );
            }

            $retrievedAt = Carbon::now()->toIso8601String();
            $normalized = $this->normalizer->normalize(
                $response->firstResult(),
                $target,
                (int) $params['location_code'],
                (string) $params['language_code'],
                $locationName,
                $languageName,
                SeoIntelligenceConfig::rankedKeywordsLimit(),
                $retrievedAt,
            );

            $freshUntil = Carbon::now()->addDays(SeoIntelligenceConfig::rankedKeywordsTtlDays());
            $cost = $response->cost ?? 0.0;

            $summary = Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => 'website',
                'type' => SeoIntelligenceConfig::EVIDENCE_RANKED_SUMMARY,
                'request_fingerprint' => $fingerprint,
                'title' => 'DataForSEO ranked keywords summary',
                'payload' => $normalized['summary'],
                'observed_at' => $startedAt,
                'fresh_until' => $freshUntil,
            ]);

            $rows = Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => 'website',
                'type' => SeoIntelligenceConfig::EVIDENCE_RANKED_ROWS,
                'request_fingerprint' => $fingerprint,
                'title' => 'DataForSEO ranked keywords',
                'payload' => $normalized['rows'],
                'observed_at' => $startedAt,
                'fresh_until' => $freshUntil,
            ]);

            $runMeta = $this->freshness->providerCallRunMetadata(
                ProviderRegistry::DATAFORSEO,
                SeoIntelligenceConfig::rankedKeywordsUseCase(),
                $fingerprint,
                'MISS',
                [
                    ...$response->costProvenanceMetadata(),
                    'capability' => SeoIntelligenceConfig::CAPABILITY_RANKED,
                    'trigger' => 'refresh_seo_intelligence',
                    'provider_calls' => 1,
                    'target' => $target,
                    'market' => [
                        'location_code' => $params['location_code'],
                        'location_name' => $locationName,
                        'language_code' => $params['language_code'],
                        'language_name' => $languageName,
                    ],
                    'integration_id' => $integration->id,
                    'integration_name' => $integration->name,
                    'ttl_days' => SeoIntelligenceConfig::rankedKeywordsTtlDays(),
                    'ttl_policy' => 'MoxDOP cost/freshness policy (ranked keywords source updates weekly).',
                ],
            );

            $run->update([
                'status' => 'completed',
                'finished_at' => now(),
                'metadata' => $runMeta,
            ]);

            return [
                'evidence' => $summary,
                'reported_cost_usd' => (float) $cost,
                'metadata' => [
                    'run' => $run->fresh(),
                    'evidence_ids' => [(int) $summary->id, (int) $rows->id],
                    'message' => 'Ranked keywords refreshed from DataForSEO.',
                    'reported_cost_usd' => (float) $cost,
                ],
            ];
        } catch (\Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'metadata' => array_merge(is_array($run->metadata) ? $run->metadata : [], [
                    'error' => $exception instanceof DataForSeoException
                        ? $exception->getMessage()
                        : 'SEO keyword visibility refresh failed.',
                    'provider_calls' => 1,
                    'cache_status' => 'MISS',
                    'provider_status_code' => $exception instanceof DataForSeoException
                        ? $exception->providerStatusCode
                        : null,
                ]),
            ]);

            throw $exception;
        }
    }

    private function createCacheHitRun(
        DigitalAsset $asset,
        CoreIntegration $integration,
        string $fingerprint,
        Evidence $evidence,
        string $locationName,
        string $languageName,
        string $target,
    ): Run {
        $meta = $this->freshness->cacheHitRunMetadata(
            ProviderRegistry::DATAFORSEO,
            SeoIntelligenceConfig::rankedKeywordsUseCase(),
            $fingerprint,
            $evidence,
        );

        return Run::query()->create([
            'digital_asset_id' => $asset->id,
            'core_connection_id' => null,
            'core_asset_binding_id' => null,
            'module_id' => 'website',
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
            'metadata' => array_merge($meta, [
                'trigger' => 'refresh_seo_intelligence',
                'capability' => SeoIntelligenceConfig::CAPABILITY_RANKED,
                'provider_calls' => 0,
                'target' => $target,
                'market' => [
                    'location_code' => $asset->seo_market_location_code,
                    'location_name' => $locationName,
                    'language_code' => $asset->seo_market_language_code,
                    'language_name' => $languageName,
                ],
                'integration_id' => $integration->id,
                'integration_name' => $integration->name,
            ]),
        ]);
    }
}
