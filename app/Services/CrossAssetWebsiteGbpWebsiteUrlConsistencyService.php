<?php

namespace App\Services;

use App\Enums\RecommendationOrigin;
use App\Enums\RecommendationSourceKind;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Services\GoogleBusinessProfileConnectionProbeService as GbpLocationAccessEvidence;
use DateTimeInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Deterministic Website ↔ Google Business Profile website URL consistency pack.
 *
 * Reads existing Evidence only (no external fetches / writes). Findings land on the
 * primary Website Digital Asset with related GBP asset identity in the summary.
 */
class CrossAssetWebsiteGbpWebsiteUrlConsistencyService
{
    public const MODULE_ID = 'cross-asset-analysis';

    public const PACK_ID = 'website-gbp-website-url-consistency';

    public const EVIDENCE_TYPE_COMPARISON = 'cross_asset_website_url_comparison';

    public const ASSET_TYPE_WEBSITE = 'website';

    public const ASSET_TYPE_GBP = 'google_business_profile';

    public const EVIDENCE_TYPE_HTTP_FETCH = 'http_fetch';

    private const CONFIDENCE_HIGH = 0.9000;

    /**
     * Run the pack for a Website Digital Asset using Brand-scoped sibling GBP Evidence.
     */
    public function analyze(DigitalAsset $websiteAsset): Run
    {
        if ($websiteAsset->type !== self::ASSET_TYPE_WEBSITE) {
            throw new InvalidArgumentException('Website↔GBP website URL pack requires a website Digital Asset.');
        }

        if ($websiteAsset->brand_id === null) {
            throw new InvalidArgumentException('Website↔GBP website URL pack requires a Brand-scoped Website asset.');
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
                'related_asset_type' => self::ASSET_TYPE_GBP,
            ],
        ]);

        try {
            $gbpCandidates = $this->gbpCandidatesForBrand((int) $websiteAsset->brand_id);
            $gbpAsset = $gbpCandidates->count() === 1 ? $gbpCandidates->first() : null;

            $websiteEvidence = $this->latestSuccessfulHttpFetch($websiteAsset);
            $gbpEvidence = $gbpAsset instanceof DigitalAsset
                ? $this->latestSuccessfulGbpLocationAccess($gbpAsset)
                : null;

            $websiteUrl = $this->websiteUrlFromEvidence($websiteEvidence);
            $gbpWebsiteUri = $this->gbpWebsiteUriFromEvidence($gbpEvidence);

            $skipReason = $this->determineSkipReason(
                gbpCandidateCount: $gbpCandidates->count(),
                websiteEvidence: $websiteEvidence,
                gbpEvidence: $gbpEvidence,
                websiteUrl: $websiteUrl,
                gbpWebsiteUri: $gbpWebsiteUri,
            );

            $websiteHost = is_string($websiteUrl) ? $this->hostFromUrl($websiteUrl) : null;
            $gbpHost = is_string($gbpWebsiteUri) ? $this->hostFromUrl($gbpWebsiteUri) : null;

            $hostsMatch = $skipReason === null
                && is_string($websiteHost)
                && is_string($gbpHost)
                && $websiteHost === $gbpHost;

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $websiteAsset->id,
                'source_module' => self::MODULE_ID,
                'type' => self::EVIDENCE_TYPE_COMPARISON,
                'title' => 'Website ↔ GBP website URL comparison',
                'payload' => [
                    'pack_id' => self::PACK_ID,
                    'primary_digital_asset_id' => $websiteAsset->id,
                    'related_digital_asset_id' => $gbpAsset?->id,
                    'website_evidence_id' => $websiteEvidence?->id,
                    'gbp_evidence_id' => $gbpEvidence?->id,
                    'website_url' => $websiteUrl,
                    'website_host' => $websiteHost,
                    'gbp_website_uri' => $gbpWebsiteUri,
                    'gbp_host' => $gbpHost,
                    'hosts_match' => $hostsMatch,
                    'skip_reason' => $skipReason,
                    'compared' => $skipReason === null,
                ],
                'observed_at' => $observedAt,
            ]);

            if ($skipReason === null && $hostsMatch === false && $gbpAsset instanceof DigitalAsset) {
                $this->upsertMismatchFinding(
                    websiteAsset: $websiteAsset,
                    gbpAsset: $gbpAsset,
                    run: $run,
                    websiteUrl: (string) $websiteUrl,
                    gbpWebsiteUri: (string) $gbpWebsiteUri,
                    websiteHost: (string) $websiteHost,
                    gbpHost: (string) $gbpHost,
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
                    'related_digital_asset_id' => $gbpAsset?->id,
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
    private function gbpCandidatesForBrand(int $brandId): Collection
    {
        return DigitalAsset::query()
            ->where('brand_id', $brandId)
            ->where('type', self::ASSET_TYPE_GBP)
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

    private function latestSuccessfulGbpLocationAccess(DigitalAsset $gbpAsset): ?Evidence
    {
        return Evidence::query()
            ->where('digital_asset_id', $gbpAsset->id)
            ->where('type', GbpLocationAccessEvidence::EVIDENCE_TYPE_GBP_LOCATION_ACCESS)
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

    private function gbpWebsiteUriFromEvidence(?Evidence $evidence): ?string
    {
        if ($evidence === null || ! is_array($evidence->payload)) {
            return null;
        }

        $uri = $evidence->payload['website_uri'] ?? null;

        return is_string($uri) && trim($uri) !== '' ? trim($uri) : null;
    }

    private function determineSkipReason(
        int $gbpCandidateCount,
        ?Evidence $websiteEvidence,
        ?Evidence $gbpEvidence,
        ?string $websiteUrl,
        ?string $gbpWebsiteUri,
    ): ?string {
        if ($gbpCandidateCount === 0) {
            return 'missing_gbp_asset';
        }

        if ($gbpCandidateCount > 1) {
            return 'ambiguous_gbp_asset';
        }

        if ($websiteEvidence === null || $websiteUrl === null || $this->hostFromUrl($websiteUrl) === null) {
            return 'missing_website_http_fetch_evidence';
        }

        if ($gbpEvidence === null) {
            return 'missing_gbp_location_access_evidence';
        }

        if ($gbpWebsiteUri === null || $this->hostFromUrl($gbpWebsiteUri) === null) {
            return 'missing_gbp_website_uri';
        }

        return null;
    }

    private function upsertMismatchFinding(
        DigitalAsset $websiteAsset,
        DigitalAsset $gbpAsset,
        Run $run,
        string $websiteUrl,
        string $gbpWebsiteUri,
        string $websiteHost,
        string $gbpHost,
        DateTimeInterface $observedAt,
    ): Finding {
        $fingerprint = hash('sha256', implode('|', [
            self::PACK_ID,
            'primary_asset_id='.$websiteAsset->id,
            'related_asset_id='.$gbpAsset->id,
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
            'title' => 'Website URL does not match Google Business Profile website',
            'summary' => sprintf(
                'Brand Website host %s (%s) differs from Google Business Profile website host %s (%s) on related asset #%d. Pack %s compared Evidence only; no external write performed.',
                $websiteHost,
                $websiteUrl,
                $gbpHost,
                $gbpWebsiteUri,
                $gbpAsset->id,
                self::PACK_ID,
            ),
            'confidence' => self::CONFIDENCE_HIGH,
            'status' => 'open',
            'last_seen_at' => $observedAt,
            'last_run_id' => $run->id,
        ]);

        $finding->save();

        $this->upsertRecommendation($finding, $websiteAsset, $gbpAsset);

        return $finding;
    }

    private function upsertRecommendation(
        Finding $finding,
        DigitalAsset $websiteAsset,
        DigitalAsset $gbpAsset,
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
            'title' => 'Align Website and Google Business Profile website URLs',
            'action' => 'Review the Website Digital Asset URL Evidence and the Google Business Profile websiteUri Evidence, then update the incorrect side in its native platform (Website CMS or Google Business Profile). DOP remains read-only.',
            'rationale' => sprintf(
                'Cross-asset pack %s found a host mismatch between Website asset #%d and GBP asset #%d.',
                self::PACK_ID,
                $websiteAsset->id,
                $gbpAsset->id,
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
