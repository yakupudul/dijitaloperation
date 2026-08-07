<?php

namespace App\Services;

use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Services\MetaAdsAdDestinationUrlsCollectService as MetaAdsDestinationEvidence;
use DateTimeInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Deterministic Website ↔ Meta Ads destination URL consistency pack.
 *
 * Reads existing Evidence only (no external fetches / writes). Findings land on the
 * primary Website Digital Asset with related Meta Ads asset identity in the summary.
 */
class CrossAssetWebsiteMetaAdsDestinationConsistencyService
{
    public const MODULE_ID = 'cross-asset-analysis';

    public const PACK_ID = 'website-meta-ads-destination-consistency';

    public const EVIDENCE_TYPE_COMPARISON = 'cross_asset_meta_ads_destination_url_comparison';

    public const ASSET_TYPE_WEBSITE = 'website';

    public const ASSET_TYPE_META_ADS = 'meta_ads';

    public const EVIDENCE_TYPE_HTTP_FETCH = 'http_fetch';

    private const CONFIDENCE_HIGH = 0.9000;

    /**
     * Run the pack for a Website Digital Asset using Brand-scoped sibling Meta Ads Evidence.
     */
    public function analyze(DigitalAsset $websiteAsset): Run
    {
        if ($websiteAsset->type !== self::ASSET_TYPE_WEBSITE) {
            throw new InvalidArgumentException('Website↔Meta Ads destination pack requires a website Digital Asset.');
        }

        if ($websiteAsset->brand_id === null) {
            throw new InvalidArgumentException('Website↔Meta Ads destination pack requires a Brand-scoped Website asset.');
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
                'related_asset_type' => self::ASSET_TYPE_META_ADS,
            ],
        ]);

        try {
            $metaCandidates = $this->metaAdsCandidatesForBrand((int) $websiteAsset->brand_id);
            $metaAsset = $metaCandidates->count() === 1 ? $metaCandidates->first() : null;

            $websiteEvidence = $this->latestSuccessfulHttpFetch($websiteAsset);
            $metaEvidence = $metaAsset instanceof DigitalAsset
                ? $this->latestSuccessfulMetaDestinationUrls($metaAsset)
                : null;

            $websiteUrl = $this->websiteUrlFromEvidence($websiteEvidence);
            $metaDestinationUrls = $this->metaDestinationUrlsFromEvidence($metaEvidence);
            $metaHosts = $this->hostsFromUrls($metaDestinationUrls);

            $websiteHost = is_string($websiteUrl) ? $this->hostFromUrl($websiteUrl) : null;

            $skipReason = $this->determineSkipReason(
                metaCandidateCount: $metaCandidates->count(),
                websiteEvidence: $websiteEvidence,
                metaEvidence: $metaEvidence,
                websiteUrl: $websiteUrl,
                metaHosts: $metaHosts,
            );

            $hostsMatch = $skipReason === null
                && is_string($websiteHost)
                && $metaHosts !== []
                && $this->allHostsMatchWebsite($websiteHost, $metaHosts);

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $websiteAsset->id,
                'source_module' => self::MODULE_ID,
                'type' => self::EVIDENCE_TYPE_COMPARISON,
                'title' => 'Website ↔ Meta Ads destination URL comparison',
                'payload' => [
                    'pack_id' => self::PACK_ID,
                    'primary_digital_asset_id' => $websiteAsset->id,
                    'related_digital_asset_id' => $metaAsset?->id,
                    'website_evidence_id' => $websiteEvidence?->id,
                    'meta_ads_evidence_id' => $metaEvidence?->id,
                    'website_url' => $websiteUrl,
                    'website_host' => $websiteHost,
                    'meta_destination_urls' => $metaDestinationUrls,
                    'meta_destination_url_hosts' => $metaHosts,
                    'hosts_match' => $hostsMatch,
                    'skip_reason' => $skipReason,
                    'compared' => $skipReason === null,
                ],
                'observed_at' => $observedAt,
            ]);

            if ($skipReason === null && $hostsMatch === false && $metaAsset instanceof DigitalAsset) {
                $this->upsertMismatchFinding(
                    websiteAsset: $websiteAsset,
                    metaAsset: $metaAsset,
                    run: $run,
                    websiteUrl: (string) $websiteUrl,
                    websiteHost: (string) $websiteHost,
                    metaDestinationUrls: $metaDestinationUrls,
                    metaHosts: $metaHosts,
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
                    'related_digital_asset_id' => $metaAsset?->id,
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
    private function metaAdsCandidatesForBrand(int $brandId): Collection
    {
        return DigitalAsset::query()
            ->where('brand_id', $brandId)
            ->where('type', self::ASSET_TYPE_META_ADS)
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

    private function latestSuccessfulMetaDestinationUrls(DigitalAsset $metaAsset): ?Evidence
    {
        return Evidence::query()
            ->where('digital_asset_id', $metaAsset->id)
            ->where('type', MetaAdsDestinationEvidence::EVIDENCE_TYPE_AD_DESTINATION_URLS)
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
    private function metaDestinationUrlsFromEvidence(?Evidence $evidence): array
    {
        if ($evidence === null || ! is_array($evidence->payload)) {
            return [];
        }

        $urls = $evidence->payload['destination_urls'] ?? null;
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
     * @param  list<string>  $metaHosts
     */
    private function determineSkipReason(
        int $metaCandidateCount,
        ?Evidence $websiteEvidence,
        ?Evidence $metaEvidence,
        ?string $websiteUrl,
        array $metaHosts,
    ): ?string {
        if ($metaCandidateCount === 0) {
            return 'missing_meta_ads_asset';
        }

        if ($metaCandidateCount > 1) {
            return 'ambiguous_meta_ads_asset';
        }

        if ($websiteEvidence === null || $websiteUrl === null || $this->hostFromUrl($websiteUrl) === null) {
            return 'missing_website_http_fetch_evidence';
        }

        if ($metaEvidence === null) {
            return 'missing_meta_ads_ad_destination_urls_evidence';
        }

        if ($metaHosts === []) {
            return 'missing_meta_ads_destination_url_hosts';
        }

        return null;
    }

    /**
     * @param  list<string>  $metaHosts
     */
    private function allHostsMatchWebsite(string $websiteHost, array $metaHosts): bool
    {
        foreach ($metaHosts as $host) {
            if ($host !== $websiteHost) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $metaDestinationUrls
     * @param  list<string>  $metaHosts
     */
    private function upsertMismatchFinding(
        DigitalAsset $websiteAsset,
        DigitalAsset $metaAsset,
        Run $run,
        string $websiteUrl,
        string $websiteHost,
        array $metaDestinationUrls,
        array $metaHosts,
        DateTimeInterface $observedAt,
    ): Finding {
        $fingerprint = hash('sha256', implode('|', [
            self::PACK_ID,
            'primary_asset_id='.$websiteAsset->id,
            'related_asset_id='.$metaAsset->id,
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
            'title' => 'Website URL does not match Meta Ads destination host(s)',
            'summary' => sprintf(
                'Brand Website host %s (%s) differs from Meta Ads destination URL host(s) %s on related asset #%d. Sample Meta Ads URL: %s. Pack %s compared Evidence only; no external write performed.',
                $websiteHost,
                $websiteUrl,
                implode(', ', $metaHosts),
                $metaAsset->id,
                $metaDestinationUrls[0] ?? '',
                self::PACK_ID,
            ),
            'confidence' => self::CONFIDENCE_HIGH,
            'status' => 'open',
            'last_seen_at' => $observedAt,
            'last_run_id' => $run->id,
        ]);

        $finding->save();

        $this->upsertRecommendation($finding, $websiteAsset, $metaAsset);

        return $finding;
    }

    private function upsertRecommendation(
        Finding $finding,
        DigitalAsset $websiteAsset,
        DigitalAsset $metaAsset,
    ): void {
        $recommendation = Recommendation::query()->firstOrNew([
            'finding_id' => $finding->id,
            'source_module' => self::MODULE_ID,
        ]);

        $recommendation->fill([
            'digital_asset_id' => $websiteAsset->id,
            'source_module' => self::MODULE_ID,
            'title' => 'Align Website and Meta Ads destination URLs',
            'action' => 'Review Website HTTP Evidence and Meta Ads destination URL Evidence, then update the incorrect destination URLs in Meta Ads Manager or the Website primary URL in its native platform. DOP remains read-only.',
            'rationale' => sprintf(
                'Cross-asset pack %s found a host mismatch between Website asset #%d and Meta Ads asset #%d.',
                self::PACK_ID,
                $websiteAsset->id,
                $metaAsset->id,
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
