<?php

namespace App\Services;

use App\Enums\RecommendationOrigin;
use App\Enums\RecommendationSourceKind;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Services\GoogleAdsLandingFinalUrlsCollectService as GoogleAdsLandingEvidence;
use DateTimeInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Deterministic Website ↔ Google Ads landing URL consistency pack.
 *
 * Reads existing Evidence only (no external fetches / writes). Findings land on the
 * primary Website Digital Asset with related Google Ads asset identity in the summary.
 */
class CrossAssetWebsiteGoogleAdsLandingConsistencyService
{
    public const MODULE_ID = 'cross-asset-analysis';

    public const PACK_ID = 'website-google-ads-landing-consistency';

    public const EVIDENCE_TYPE_COMPARISON = 'cross_asset_landing_url_comparison';

    public const ASSET_TYPE_WEBSITE = 'website';

    public const ASSET_TYPE_GOOGLE_ADS = 'google_ads';

    public const EVIDENCE_TYPE_HTTP_FETCH = 'http_fetch';

    private const CONFIDENCE_HIGH = 0.9000;

    /**
     * Run the pack for a Website Digital Asset using Brand-scoped sibling Google Ads Evidence.
     */
    public function analyze(DigitalAsset $websiteAsset): Run
    {
        if ($websiteAsset->type !== self::ASSET_TYPE_WEBSITE) {
            throw new InvalidArgumentException('Website↔Google Ads landing pack requires a website Digital Asset.');
        }

        if ($websiteAsset->brand_id === null) {
            throw new InvalidArgumentException('Website↔Google Ads landing pack requires a Brand-scoped Website asset.');
        }

        $observedAt = now();

        $run = Run::query()->create([
            'digital_asset_id' => $websiteAsset->id,
            'core_connection_id' => null,
            'module_id' => self::MODULE_ID,
            'status' => 'running',
            'started_at' => $observedAt,
            'finished_at' => null,
            'metadata' => [
                'trigger' => 'programmatic',
                'pack_id' => self::PACK_ID,
                'primary_asset_type' => self::ASSET_TYPE_WEBSITE,
                'related_asset_type' => self::ASSET_TYPE_GOOGLE_ADS,
            ],
        ]);

        try {
            $adsCandidates = $this->googleAdsCandidatesForBrand((int) $websiteAsset->brand_id);
            $adsAsset = $adsCandidates->count() === 1 ? $adsCandidates->first() : null;

            $websiteEvidence = $this->latestSuccessfulHttpFetch($websiteAsset);
            $adsEvidence = $adsAsset instanceof DigitalAsset
                ? $this->latestSuccessfulAdsLandingUrls($adsAsset)
                : null;

            $websiteUrl = $this->websiteUrlFromEvidence($websiteEvidence);
            $adsFinalUrls = $this->adsFinalUrlsFromEvidence($adsEvidence);
            $adsHosts = $this->hostsFromUrls($adsFinalUrls);

            $websiteHost = is_string($websiteUrl) ? $this->hostFromUrl($websiteUrl) : null;

            $skipReason = $this->determineSkipReason(
                adsCandidateCount: $adsCandidates->count(),
                websiteEvidence: $websiteEvidence,
                adsEvidence: $adsEvidence,
                websiteUrl: $websiteUrl,
                adsHosts: $adsHosts,
            );

            $hostsMatch = $skipReason === null
                && is_string($websiteHost)
                && $adsHosts !== []
                && $this->allHostsMatchWebsite($websiteHost, $adsHosts);

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $websiteAsset->id,
                'source_module' => self::MODULE_ID,
                'type' => self::EVIDENCE_TYPE_COMPARISON,
                'title' => 'Website ↔ Google Ads landing URL comparison',
                'payload' => [
                    'pack_id' => self::PACK_ID,
                    'primary_digital_asset_id' => $websiteAsset->id,
                    'related_digital_asset_id' => $adsAsset?->id,
                    'website_evidence_id' => $websiteEvidence?->id,
                    'ads_evidence_id' => $adsEvidence?->id,
                    'website_url' => $websiteUrl,
                    'website_host' => $websiteHost,
                    'ads_final_urls' => $adsFinalUrls,
                    'ads_final_url_hosts' => $adsHosts,
                    'hosts_match' => $hostsMatch,
                    'skip_reason' => $skipReason,
                    'compared' => $skipReason === null,
                ],
                'observed_at' => $observedAt,
            ]);

            if ($skipReason === null && $hostsMatch === false && $adsAsset instanceof DigitalAsset) {
                $this->upsertMismatchFinding(
                    websiteAsset: $websiteAsset,
                    adsAsset: $adsAsset,
                    run: $run,
                    websiteUrl: (string) $websiteUrl,
                    websiteHost: (string) $websiteHost,
                    adsFinalUrls: $adsFinalUrls,
                    adsHosts: $adsHosts,
                    observedAt: $observedAt,
                );
            }

            $run->update([
                'status' => 'completed',
                'finished_at' => now(),
                'metadata' => array_merge($run->metadata ?? [], [
                    'skip_reason' => $skipReason,
                    'compared' => $skipReason === null,
                    'hosts_match' => $hostsMatch,
                    'related_digital_asset_id' => $adsAsset?->id,
                ]),
            ]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'metadata' => array_merge($run->metadata ?? [], [
                    'error' => $exception->getMessage(),
                ]),
            ]);

            throw $exception;
        }

        return $run->fresh(['evidence', 'digitalAsset']) ?? $run;
    }

    /**
     * @return Collection<int, DigitalAsset>
     */
    private function googleAdsCandidatesForBrand(int $brandId): Collection
    {
        return DigitalAsset::query()
            ->where('brand_id', $brandId)
            ->where('type', self::ASSET_TYPE_GOOGLE_ADS)
            ->orderBy('id')
            ->get();
    }

    private function latestSuccessfulHttpFetch(DigitalAsset $websiteAsset): ?Evidence
    {
        return Evidence::query()
            ->where('digital_asset_id', $websiteAsset->id)
            ->where('type', self::EVIDENCE_TYPE_HTTP_FETCH)
            ->where('payload->response_is_ok', true)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first();
    }

    private function latestSuccessfulAdsLandingUrls(DigitalAsset $adsAsset): ?Evidence
    {
        return Evidence::query()
            ->where('digital_asset_id', $adsAsset->id)
            ->where('type', GoogleAdsLandingEvidence::EVIDENCE_TYPE_LANDING_FINAL_URLS)
            ->where('payload->ok', true)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first();
    }

    private function websiteUrlFromEvidence(?Evidence $evidence): ?string
    {
        if ($evidence === null || ! is_array($evidence->payload)) {
            return null;
        }

        $effective = $evidence->payload['effective_url'] ?? null;
        if (is_string($effective) && trim($effective) !== '') {
            return trim($effective);
        }

        $url = $evidence->payload['url'] ?? null;

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }

    /**
     * @return list<string>
     */
    private function adsFinalUrlsFromEvidence(?Evidence $evidence): array
    {
        if ($evidence === null || ! is_array($evidence->payload)) {
            return [];
        }

        $urls = $evidence->payload['final_urls'] ?? null;
        if (! is_array($urls)) {
            return [];
        }

        $normalized = [];
        foreach ($urls as $url) {
            if (is_string($url) && trim($url) !== '') {
                $normalized[] = trim($url);
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function hostsFromUrls(array $urls): array
    {
        $hosts = [];
        foreach ($urls as $url) {
            $host = $this->hostFromUrl($url);
            if ($host !== null) {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * @param  list<string>  $adsHosts
     */
    private function determineSkipReason(
        int $adsCandidateCount,
        ?Evidence $websiteEvidence,
        ?Evidence $adsEvidence,
        ?string $websiteUrl,
        array $adsHosts,
    ): ?string {
        if ($adsCandidateCount === 0) {
            return 'missing_google_ads_asset';
        }

        if ($adsCandidateCount > 1) {
            return 'ambiguous_google_ads_asset';
        }

        if ($websiteEvidence === null || $websiteUrl === null || $this->hostFromUrl($websiteUrl) === null) {
            return 'missing_website_http_fetch_evidence';
        }

        if ($adsEvidence === null) {
            return 'missing_google_ads_landing_final_urls_evidence';
        }

        if ($adsHosts === []) {
            return 'missing_google_ads_final_url_hosts';
        }

        return null;
    }

    /**
     * @param  list<string>  $adsHosts
     */
    private function allHostsMatchWebsite(string $websiteHost, array $adsHosts): bool
    {
        foreach ($adsHosts as $host) {
            if ($host !== $websiteHost) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $adsFinalUrls
     * @param  list<string>  $adsHosts
     */
    private function upsertMismatchFinding(
        DigitalAsset $websiteAsset,
        DigitalAsset $adsAsset,
        Run $run,
        string $websiteUrl,
        string $websiteHost,
        array $adsFinalUrls,
        array $adsHosts,
        DateTimeInterface $observedAt,
    ): Finding {
        $fingerprint = hash('sha256', implode('|', [
            self::PACK_ID,
            'primary_asset_id='.$websiteAsset->id,
            'related_asset_id='.$adsAsset->id,
        ]));

        $finding = Finding::query()->firstOrNew([
            'digital_asset_id' => $websiteAsset->id,
            'fingerprint' => $fingerprint,
        ]);

        if (! $finding->exists) {
            $finding->first_seen_at = $observedAt;
            $finding->source_module = self::MODULE_ID;
        }

        $finding->fill([
            'source_module' => self::MODULE_ID,
            'category' => 'cross-channel',
            'severity' => 'medium',
            'title' => 'Website URL does not match Google Ads landing page host(s)',
            'summary' => sprintf(
                'Brand Website host %s (%s) differs from Google Ads final URL host(s) %s on related asset #%d. Sample Ads URL: %s. Pack %s compared Evidence only; no external write performed.',
                $websiteHost,
                $websiteUrl,
                implode(', ', $adsHosts),
                $adsAsset->id,
                $adsFinalUrls[0] ?? '',
                self::PACK_ID,
            ),
            'confidence' => self::CONFIDENCE_HIGH,
            'status' => 'open',
            'last_seen_at' => $observedAt,
            'last_run_id' => $run->id,
        ]);

        $finding->save();

        $this->upsertRecommendation($finding, $websiteAsset, $adsAsset);

        return $finding;
    }

    private function upsertRecommendation(
        Finding $finding,
        DigitalAsset $websiteAsset,
        DigitalAsset $adsAsset,
    ): void {
        $recommendation = Recommendation::query()->firstOrNew([
            'finding_id' => $finding->id,
            'source_module' => self::MODULE_ID,
        ]);

        $recommendation->fill([
            'source_kind' => RecommendationSourceKind::Finding->value,
            'opportunity_id' => null,
            'origin' => RecommendationOrigin::DeterministicTemplate->value,
            'digital_asset_id' => $websiteAsset->id,
            'source_module' => self::MODULE_ID,
            'title' => 'Align Website and Google Ads landing page URLs',
            'action' => 'Review Website HTTP Evidence and Google Ads final URL Evidence, then update the incorrect landing URLs in Google Ads or the Website primary URL in its native platform. DOP remains read-only.',
            'rationale' => sprintf(
                'Cross-asset pack %s found a host mismatch between Website asset #%d and Google Ads asset #%d.',
                self::PACK_ID,
                $websiteAsset->id,
                $adsAsset->id,
            ),
            'priority' => 'medium',
            'effort' => 's',
            'status' => 'open',
        ]);

        $recommendation->save();
    }

    private function hostFromUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        return strtolower($host);
    }
}
