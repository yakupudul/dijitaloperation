<?php

namespace App\Services;

use App\Enums\RecommendationOrigin;
use App\Enums\RecommendationSourceKind;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Services\InstagramAccountProfileCollectService as InstagramProfileEvidence;
use App\Services\MetaAdsAdDestinationUrlsCollectService as MetaAdsDestinationEvidence;
use DateTimeInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Deterministic Instagram ↔ Meta Ads destination URL consistency pack.
 *
 * Reads existing Evidence only (no external fetches / writes). Findings land on the
 * primary Instagram Digital Asset with related Meta Ads asset identity in the summary.
 */
class CrossAssetInstagramMetaAdsDestinationConsistencyService
{
    public const MODULE_ID = 'cross-asset-analysis';

    public const PACK_ID = 'instagram-meta-ads-destination-consistency';

    public const EVIDENCE_TYPE_COMPARISON = 'cross_asset_instagram_meta_ads_destination_url_comparison';

    public const ASSET_TYPE_INSTAGRAM = 'instagram';

    public const ASSET_TYPE_META_ADS = 'meta_ads';

    private const CONFIDENCE_HIGH = 0.9000;

    /**
     * Run the pack for an Instagram Digital Asset using Brand-scoped sibling Meta Ads Evidence.
     */
    public function analyze(DigitalAsset $instagramAsset): Run
    {
        if ($instagramAsset->type !== self::ASSET_TYPE_INSTAGRAM) {
            throw new InvalidArgumentException('Instagram↔Meta Ads destination pack requires an instagram Digital Asset.');
        }

        if ($instagramAsset->brand_id === null) {
            throw new InvalidArgumentException('Instagram↔Meta Ads destination pack requires a Brand-scoped Instagram asset.');
        }

        $observedAt = now();

        $run = Run::query()->create([
            'digital_asset_id' => $instagramAsset->id,
            'core_connection_id' => null,
            'module_id' => self::MODULE_ID,
            'status' => 'running',
            'started_at' => $observedAt,
            'finished_at' => null,
            'metadata' => [
                'trigger' => 'programmatic',
                'pack_id' => self::PACK_ID,
                'primary_asset_type' => self::ASSET_TYPE_INSTAGRAM,
                'related_asset_type' => self::ASSET_TYPE_META_ADS,
            ],
        ]);

        try {
            $metaCandidates = $this->metaAdsCandidatesForBrand((int) $instagramAsset->brand_id);
            $metaAsset = $metaCandidates->count() === 1 ? $metaCandidates->first() : null;

            $instagramEvidence = $this->latestSuccessfulInstagramProfile($instagramAsset);
            $metaEvidence = $metaAsset instanceof DigitalAsset
                ? $this->latestSuccessfulMetaDestinationUrls($metaAsset)
                : null;

            $instagramWebsite = $this->instagramWebsiteFromEvidence($instagramEvidence);
            $metaDestinationUrls = $this->metaDestinationUrlsFromEvidence($metaEvidence);
            $metaHosts = $this->hostsFromUrls($metaDestinationUrls);

            $instagramHost = is_string($instagramWebsite) ? $this->hostFromUrl($instagramWebsite) : null;

            $skipReason = $this->determineSkipReason(
                metaCandidateCount: $metaCandidates->count(),
                instagramEvidence: $instagramEvidence,
                metaEvidence: $metaEvidence,
                instagramWebsite: $instagramWebsite,
                instagramHost: $instagramHost,
                metaHosts: $metaHosts,
            );

            $hostsMatch = $skipReason === null
                && is_string($instagramHost)
                && $metaHosts !== []
                && $this->allHostsMatchInstagram($instagramHost, $metaHosts);

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $instagramAsset->id,
                'source_module' => self::MODULE_ID,
                'type' => self::EVIDENCE_TYPE_COMPARISON,
                'title' => 'Instagram ↔ Meta Ads destination URL comparison',
                'payload' => [
                    'pack_id' => self::PACK_ID,
                    'primary_digital_asset_id' => $instagramAsset->id,
                    'related_digital_asset_id' => $metaAsset?->id,
                    'instagram_evidence_id' => $instagramEvidence?->id,
                    'meta_ads_evidence_id' => $metaEvidence?->id,
                    'instagram_website' => $instagramWebsite,
                    'instagram_host' => $instagramHost,
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
                    instagramAsset: $instagramAsset,
                    metaAsset: $metaAsset,
                    run: $run,
                    instagramWebsite: (string) $instagramWebsite,
                    instagramHost: (string) $instagramHost,
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

    private function latestSuccessfulInstagramProfile(DigitalAsset $instagramAsset): ?Evidence
    {
        return Evidence::query()
            ->where('digital_asset_id', $instagramAsset->id)
            ->where('type', InstagramProfileEvidence::EVIDENCE_TYPE_ACCOUNT_PROFILE)
            ->where('payload->ok', true)
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

    private function instagramWebsiteFromEvidence(?Evidence $evidence): ?string
    {
        if ($evidence === null || ! is_array($evidence->payload)) {
            return null;
        }

        $website = $evidence->payload['website'] ?? null;

        return is_string($website) && trim($website) !== '' ? trim($website) : null;
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
        ?Evidence $instagramEvidence,
        ?Evidence $metaEvidence,
        ?string $instagramWebsite,
        ?string $instagramHost,
        array $metaHosts,
    ): ?string {
        if ($metaCandidateCount === 0) {
            return 'missing_meta_ads_asset';
        }

        if ($metaCandidateCount > 1) {
            return 'ambiguous_meta_ads_asset';
        }

        if ($instagramEvidence === null) {
            return 'missing_instagram_account_profile_evidence';
        }

        if ($instagramWebsite === null || $instagramHost === null) {
            return 'missing_instagram_website';
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
    private function allHostsMatchInstagram(string $instagramHost, array $metaHosts): bool
    {
        foreach ($metaHosts as $host) {
            if ($host !== $instagramHost) {
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
        DigitalAsset $instagramAsset,
        DigitalAsset $metaAsset,
        Run $run,
        string $instagramWebsite,
        string $instagramHost,
        array $metaDestinationUrls,
        array $metaHosts,
        DateTimeInterface $observedAt,
    ): Finding {
        $fingerprint = hash('sha256', implode('|', [
            self::PACK_ID,
            'primary_asset_id='.$instagramAsset->id,
            'related_asset_id='.$metaAsset->id,
        ]));

        $finding = Finding::query()->firstOrNew([
            'digital_asset_id' => $instagramAsset->id,
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
            'title' => 'Instagram website URL does not match Meta Ads destination host(s)',
            'summary' => sprintf(
                'Brand Instagram profile website host %s (%s) differs from Meta Ads destination URL host(s) %s on related asset #%d. Sample Meta Ads URL: %s. Pack %s compared Evidence only; no external write performed.',
                $instagramHost,
                $instagramWebsite,
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

        $this->upsertRecommendation($finding, $instagramAsset, $metaAsset);

        return $finding;
    }

    private function upsertRecommendation(
        Finding $finding,
        DigitalAsset $instagramAsset,
        DigitalAsset $metaAsset,
    ): void {
        $recommendation = Recommendation::query()->firstOrNew([
            'finding_id' => $finding->id,
            'source_module' => self::MODULE_ID,
        ]);

        $recommendation->fill([
            'source_kind' => RecommendationSourceKind::Finding->value,
            'opportunity_id' => null,
            'origin' => RecommendationOrigin::DeterministicTemplate->value,
            'digital_asset_id' => $instagramAsset->id,
            'source_module' => self::MODULE_ID,
            'title' => 'Align Instagram profile website and Meta Ads destination URLs',
            'action' => 'Review Instagram account profile Evidence and Meta Ads destination URL Evidence, then update the incorrect destination URLs in Meta Ads Manager or the Instagram profile website in its native platform. DOP remains read-only.',
            'rationale' => sprintf(
                'Cross-asset pack %s found a host mismatch between Instagram asset #%d and Meta Ads asset #%d.',
                self::PACK_ID,
                $instagramAsset->id,
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
