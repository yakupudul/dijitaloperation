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
 * Website collector for DataForSEO Labs Google Keywords For Site.
 */
final class KeywordsForSiteCollector
{
    public function __construct(
        private readonly DataForSeoApiClient $client,
        private readonly PaidRequestExecutor $executor,
        private readonly EvidenceFreshnessGuard $freshness,
        private readonly KeywordsForSiteNormalizer $normalizer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function fingerprintParameters(DigitalAsset $asset, string $target): array
    {
        $minVolume = SeoIntelligenceConfig::keywordsForSiteMinVolume();

        return [
            'target' => $target,
            'location_code' => (int) $asset->seo_market_location_code,
            'language_code' => (string) $asset->seo_market_language_code,
            'include_serp_info' => false,
            'include_clickstream_data' => false,
            'limit' => SeoIntelligenceConfig::keywordsForSiteLimit(),
            'order_by' => ['relevance,desc', 'keyword_info.search_volume,desc'],
            'filters' => ['keyword_info.search_volume', '>', $minVolume > 0 ? $minVolume - 1 : 0],
        ];
    }

    public function fingerprint(DigitalAsset $asset, string $target): string
    {
        return PaidRequestFingerprint::make(
            ProviderRegistry::DATAFORSEO,
            SeoIntelligenceConfig::keywordsForSiteUseCase(),
            DataForSeoEndpointAllowlist::LABS_GOOGLE_KEYWORDS_FOR_SITE_LIVE,
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
            throw new \InvalidArgumentException('Website domain is required for keyword opportunities.');
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
                'message' => 'Fresh keyword-opportunity Evidence reused; no DataForSEO request was made.',
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
                'capability' => SeoIntelligenceConfig::CAPABILITY_KEYWORDS_FOR_SITE,
                'provider' => ProviderRegistry::DATAFORSEO,
                'use_case' => SeoIntelligenceConfig::keywordsForSiteUseCase(),
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
                'include_serp_info' => false,
                'include_clickstream_data' => false,
                'limit' => $params['limit'],
                'order_by' => $params['order_by'],
                'filters' => $params['filters'],
            ];

            $response = $this->client->postKeywordsForSiteLive($integration, [$task]);
            $taskRow = $response->firstTask();
            $taskStatus = isset($taskRow['status_code']) ? (int) $taskRow['status_code'] : null;

            if ($taskStatus !== null && $taskStatus !== 20000) {
                throw new DataForSeoException(
                    'DataForSEO keywords for site task failed.',
                    kind: DataForSeoException::KIND_PROVIDER_STATUS,
                    providerStatusCode: $taskStatus,
                );
            }

            $retrievedAt = Carbon::now()->toIso8601String();
            $payload = $this->normalizer->normalize(
                $response->firstResult(),
                $target,
                (int) $params['location_code'],
                (string) $params['language_code'],
                $locationName,
                $languageName,
                SeoIntelligenceConfig::keywordsForSiteLimit(),
                SeoIntelligenceConfig::keywordsForSiteMinVolume(),
                $retrievedAt,
            );

            $freshUntil = Carbon::now()->addDays(SeoIntelligenceConfig::keywordsForSiteTtlDays());
            $cost = $response->cost ?? 0.0;

            $evidence = Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => 'website',
                'type' => SeoIntelligenceConfig::EVIDENCE_KEYWORD_OPPORTUNITIES,
                'request_fingerprint' => $fingerprint,
                'title' => 'DataForSEO keyword opportunities',
                'payload' => $payload,
                'observed_at' => $startedAt,
                'fresh_until' => $freshUntil,
            ]);

            $runMeta = $this->freshness->providerCallRunMetadata(
                ProviderRegistry::DATAFORSEO,
                SeoIntelligenceConfig::keywordsForSiteUseCase(),
                $fingerprint,
                'MISS',
                [
                    ...$response->costProvenanceMetadata(),
                    'capability' => SeoIntelligenceConfig::CAPABILITY_KEYWORDS_FOR_SITE,
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
                    'ttl_days' => SeoIntelligenceConfig::keywordsForSiteTtlDays(),
                    'ttl_policy' => 'MoxDOP cost/freshness policy (keyword database metrics do not need minute-level refresh).',
                ],
            );

            $run->update([
                'status' => 'completed',
                'finished_at' => now(),
                'metadata' => $runMeta,
            ]);

            return [
                'evidence' => $evidence,
                'reported_cost_usd' => (float) $cost,
                'metadata' => [
                    'run' => $run->fresh(),
                    'evidence_ids' => [(int) $evidence->id],
                    'message' => 'Keyword opportunities refreshed from DataForSEO.',
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
                        : 'Keyword opportunities refresh failed.',
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
            SeoIntelligenceConfig::keywordsForSiteUseCase(),
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
                'capability' => SeoIntelligenceConfig::CAPABILITY_KEYWORDS_FOR_SITE,
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
