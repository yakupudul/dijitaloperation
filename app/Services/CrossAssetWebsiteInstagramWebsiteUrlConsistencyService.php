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
use DateTimeInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Deterministic Website ↔ Instagram website URL consistency pack.
 *
 * Reads existing Evidence only (no external fetches / writes). Findings land on the
 * primary Website Digital Asset with related Instagram asset identity in the summary.
 */
class CrossAssetWebsiteInstagramWebsiteUrlConsistencyService
{
    public const MODULE_ID = 'cross-asset-analysis';

    public const PACK_ID = 'website-instagram-website-url-consistency';

    public const EVIDENCE_TYPE_COMPARISON = 'cross_asset_instagram_website_url_comparison';

    public const ASSET_TYPE_WEBSITE = 'website';

    public const ASSET_TYPE_INSTAGRAM = 'instagram';

    public const EVIDENCE_TYPE_HTTP_FETCH = 'http_fetch';

    private const CONFIDENCE_HIGH = 0.9000;

    /**
     * Run the pack for a Website Digital Asset using Brand-scoped sibling Instagram Evidence.
     */
    public function analyze(DigitalAsset $websiteAsset): Run
    {
        if ($websiteAsset->type !== self::ASSET_TYPE_WEBSITE) {
            throw new InvalidArgumentException('Website↔Instagram website URL pack requires a website Digital Asset.');
        }

        if ($websiteAsset->brand_id === null) {
            throw new InvalidArgumentException('Website↔Instagram website URL pack requires a Brand-scoped Website asset.');
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
                'related_asset_type' => self::ASSET_TYPE_INSTAGRAM,
            ],
        ]);

        try {
            $instagramCandidates = $this->instagramCandidatesForBrand((int) $websiteAsset->brand_id);
            $instagramAsset = $instagramCandidates->count() === 1 ? $instagramCandidates->first() : null;

            $websiteEvidence = $this->latestSuccessfulHttpFetch($websiteAsset);
            $instagramEvidence = $instagramAsset instanceof DigitalAsset
                ? $this->latestSuccessfulInstagramProfile($instagramAsset)
                : null;

            $websiteUrl = $this->websiteUrlFromEvidence($websiteEvidence);
            $instagramWebsite = $this->instagramWebsiteFromEvidence($instagramEvidence);

            $websiteHost = is_string($websiteUrl) ? $this->hostFromUrl($websiteUrl) : null;
            $instagramHost = is_string($instagramWebsite) ? $this->hostFromUrl($instagramWebsite) : null;

            $skipReason = $this->determineSkipReason(
                instagramCandidateCount: $instagramCandidates->count(),
                websiteEvidence: $websiteEvidence,
                instagramEvidence: $instagramEvidence,
                websiteUrl: $websiteUrl,
                instagramWebsite: $instagramWebsite,
                websiteHost: $websiteHost,
                instagramHost: $instagramHost,
            );

            $hostsMatch = $skipReason === null
                && is_string($websiteHost)
                && is_string($instagramHost)
                && $websiteHost === $instagramHost;

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $websiteAsset->id,
                'source_module' => self::MODULE_ID,
                'type' => self::EVIDENCE_TYPE_COMPARISON,
                'title' => 'Website ↔ Instagram website URL comparison',
                'payload' => [
                    'pack_id' => self::PACK_ID,
                    'primary_digital_asset_id' => $websiteAsset->id,
                    'related_digital_asset_id' => $instagramAsset?->id,
                    'website_evidence_id' => $websiteEvidence?->id,
                    'instagram_evidence_id' => $instagramEvidence?->id,
                    'website_url' => $websiteUrl,
                    'website_host' => $websiteHost,
                    'instagram_website' => $instagramWebsite,
                    'instagram_host' => $instagramHost,
                    'hosts_match' => $hostsMatch,
                    'skip_reason' => $skipReason,
                    'compared' => $skipReason === null,
                ],
                'observed_at' => $observedAt,
            ]);

            if ($skipReason === null && $hostsMatch === false && $instagramAsset instanceof DigitalAsset) {
                $this->upsertMismatchFinding(
                    websiteAsset: $websiteAsset,
                    instagramAsset: $instagramAsset,
                    run: $run,
                    websiteUrl: (string) $websiteUrl,
                    websiteHost: (string) $websiteHost,
                    instagramWebsite: (string) $instagramWebsite,
                    instagramHost: (string) $instagramHost,
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
                    'related_digital_asset_id' => $instagramAsset?->id,
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
    private function instagramCandidatesForBrand(int $brandId): Collection
    {
        return DigitalAsset::query()
            ->where('brand_id', $brandId)
            ->where('type', self::ASSET_TYPE_INSTAGRAM)
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

    private function instagramWebsiteFromEvidence(?Evidence $evidence): ?string
    {
        if ($evidence === null || ! is_array($evidence->payload)) {
            return null;
        }

        $website = $evidence->payload['website'] ?? null;

        return is_string($website) && trim($website) !== '' ? trim($website) : null;
    }

    private function determineSkipReason(
        int $instagramCandidateCount,
        ?Evidence $websiteEvidence,
        ?Evidence $instagramEvidence,
        ?string $websiteUrl,
        ?string $instagramWebsite,
        ?string $websiteHost,
        ?string $instagramHost,
    ): ?string {
        if ($instagramCandidateCount === 0) {
            return 'missing_instagram_asset';
        }

        if ($instagramCandidateCount > 1) {
            return 'ambiguous_instagram_asset';
        }

        if ($websiteEvidence === null || $websiteUrl === null || $websiteHost === null) {
            return 'missing_website_http_fetch_evidence';
        }

        if ($instagramEvidence === null) {
            return 'missing_instagram_account_profile_evidence';
        }

        if ($instagramWebsite === null || $instagramHost === null) {
            return 'missing_instagram_website';
        }

        return null;
    }

    private function upsertMismatchFinding(
        DigitalAsset $websiteAsset,
        DigitalAsset $instagramAsset,
        Run $run,
        string $websiteUrl,
        string $websiteHost,
        string $instagramWebsite,
        string $instagramHost,
        DateTimeInterface $observedAt,
    ): Finding {
        $fingerprint = hash('sha256', implode('|', [
            self::PACK_ID,
            'primary_asset_id='.$websiteAsset->id,
            'related_asset_id='.$instagramAsset->id,
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
            'title' => 'Website URL does not match Instagram profile website host',
            'summary' => sprintf(
                'Brand Website host %s (%s) differs from Instagram profile website host %s (%s) on related asset #%d. Pack %s compared Evidence only; no external write performed.',
                $websiteHost,
                $websiteUrl,
                $instagramHost,
                $instagramWebsite,
                $instagramAsset->id,
                self::PACK_ID,
            ),
            'confidence' => self::CONFIDENCE_HIGH,
            'status' => 'open',
            'last_seen_at' => $observedAt,
            'last_run_id' => $run->id,
        ]);

        $finding->save();

        $this->upsertRecommendation($finding, $websiteAsset, $instagramAsset);

        return $finding;
    }

    private function upsertRecommendation(
        Finding $finding,
        DigitalAsset $websiteAsset,
        DigitalAsset $instagramAsset,
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
            'title' => 'Align Website and Instagram profile website URLs',
            'action' => 'Review Website HTTP Evidence and Instagram account profile Evidence, then update the incorrect website URL on Instagram or the Website primary URL in its native platform. DOP remains read-only.',
            'rationale' => sprintf(
                'Cross-asset pack %s found a host mismatch between Website asset #%d and Instagram asset #%d.',
                self::PACK_ID,
                $websiteAsset->id,
                $instagramAsset->id,
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
