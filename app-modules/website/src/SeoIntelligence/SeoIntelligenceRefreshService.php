<?php

namespace MoxDop\Website\SeoIntelligence;

use App\Models\DigitalAsset;
use App\Services\Integrations\EvidenceFreshnessDecision;
use App\Services\Integrations\EvidenceFreshnessGuard;

/**
 * Orchestrates Website SEO Intelligence refresh (ranked keywords + keywords for site).
 *
 * Normal refresh respects Evidence freshness. Cache HIT creates provenance Runs
 * without duplicating Evidence or calling DataForSEO.
 */
final class SeoIntelligenceRefreshService
{
    public function __construct(
        private readonly DataForSeoIntegrationResolver $integrations,
        private readonly RankedKeywordsCollector $rankedKeywords,
        private readonly KeywordsForSiteCollector $keywordsForSite,
        private readonly EvidenceFreshnessGuard $freshness,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     both_fresh: bool,
     *     provider_calls: int,
     *     reported_cost_usd: float,
     *     message: string,
     *     results: list<array<string, mixed>>,
     *     blocked_reason: ?string
     * }
     */
    public function preview(DigitalAsset $asset): array
    {
        $status = $this->integrations->status();
        if (! $status['configured']) {
            return [
                'ok' => false,
                'both_fresh' => false,
                'provider_calls' => 0,
                'reported_cost_usd' => 0.0,
                'message' => (string) $status['message'],
                'results' => [],
                'blocked_reason' => 'dataforseo_not_configured',
            ];
        }

        if (! $asset->hasSeoMarketConfigured()) {
            return [
                'ok' => false,
                'both_fresh' => false,
                'provider_calls' => 0,
                'reported_cost_usd' => 0.0,
                'message' => 'Choose the Website\'s SEO market and language before running external keyword analysis.',
                'results' => [],
                'blocked_reason' => 'seo_market_not_configured',
            ];
        }

        $target = WebsiteDomainTarget::fromAsset($asset);
        if ($target === null) {
            return [
                'ok' => false,
                'both_fresh' => false,
                'provider_calls' => 0,
                'reported_cost_usd' => 0.0,
                'message' => 'Add a Website domain before running external keyword analysis.',
                'results' => [],
                'blocked_reason' => 'domain_missing',
            ];
        }

        $rankedFp = $this->rankedKeywords->fingerprint($asset, $target);
        $kfsFp = $this->keywordsForSite->fingerprint($asset, $target);
        $ranked = $this->freshness->evaluate($rankedFp);
        $kfs = $this->freshness->evaluate($kfsFp);
        $bothFresh = $ranked['decision'] === EvidenceFreshnessDecision::HitFresh
            && $kfs['decision'] === EvidenceFreshnessDecision::HitFresh;

        return [
            'ok' => true,
            'both_fresh' => $bothFresh,
            'provider_calls' => 0,
            'reported_cost_usd' => 0.0,
            'message' => $bothFresh
                ? 'SEO intelligence is already up to date. Fresh DataForSEO results will be reused.'
                : 'MoxDOP will refresh external keyword intelligence from DataForSEO. Fresh results are reused automatically; new provider requests may consume DataForSEO credits.',
            'results' => [],
            'blocked_reason' => null,
            'ranked_cache_status' => $ranked['cache_status'],
            'keywords_for_site_cache_status' => $kfs['cache_status'],
        ];
    }

    /**
     * @return array{
     *     ok: bool,
     *     both_fresh: bool,
     *     provider_calls: int,
     *     reported_cost_usd: float,
     *     message: string,
     *     results: list<array<string, mixed>>,
     *     blocked_reason: ?string
     * }
     */
    public function refresh(DigitalAsset $asset, bool $forceRefresh = false): array
    {
        $preview = $this->preview($asset);
        if (! $preview['ok']) {
            return $preview;
        }

        $status = $this->integrations->status();
        $integration = $status['integration'];
        if ($integration === null) {
            return [
                ...$preview,
                'ok' => false,
                'blocked_reason' => 'dataforseo_not_configured',
            ];
        }

        if ($preview['both_fresh'] && ! $forceRefresh) {
            $ranked = $this->rankedKeywords->collect($asset, $integration, false);
            $kfs = $this->keywordsForSite->collect($asset, $integration, false);

            return [
                'ok' => true,
                'both_fresh' => true,
                'provider_calls' => 0,
                'reported_cost_usd' => 0.0,
                'message' => 'SEO intelligence is already up to date. Fresh DataForSEO results were reused. No provider request was made.',
                'results' => [$ranked, $kfs],
                'blocked_reason' => null,
            ];
        }

        $results = [];
        $providerCalls = 0;
        $cost = 0.0;
        $ok = true;
        $errors = [];

        try {
            $ranked = $this->rankedKeywords->collect($asset, $integration, $forceRefresh);
            $results[] = $ranked;
            if ($ranked['provider_called']) {
                $providerCalls++;
                $cost += (float) $ranked['reported_cost_usd'];
            }
        } catch (\Throwable $exception) {
            $ok = false;
            $errors[] = $exception->getMessage();
        }

        try {
            $kfs = $this->keywordsForSite->collect($asset, $integration, $forceRefresh);
            $results[] = $kfs;
            if ($kfs['provider_called']) {
                $providerCalls++;
                $cost += (float) $kfs['reported_cost_usd'];
            }
        } catch (\Throwable $exception) {
            $ok = false;
            $errors[] = $exception->getMessage();
        }

        if ($providerCalls === 0 && $ok) {
            $message = 'SEO intelligence is already up to date. Fresh DataForSEO results were reused. No provider request was made.';
        } elseif ($ok) {
            $message = sprintf(
                'SEO intelligence updated · %d provider request%s · $%s reported DataForSEO cost',
                $providerCalls,
                $providerCalls === 1 ? '' : 's',
                number_format($cost, 4),
            );
        } else {
            $message = 'SEO intelligence refresh incomplete. Previous Evidence was preserved. '
                .implode(' ', $errors);
        }

        return [
            'ok' => $ok,
            'both_fresh' => $providerCalls === 0 && $ok,
            'provider_calls' => $providerCalls,
            'reported_cost_usd' => $cost,
            'message' => $message,
            'results' => $results,
            'blocked_reason' => null,
        ];
    }
}
