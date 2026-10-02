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
use App\Support\WebsiteTelephoneExtractor;
use DateTimeInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Deterministic Website ↔ Google Business Profile phone (NAP) consistency pack.
 *
 * Reads existing page_html + gbp_location_access Evidence only (no external fetches / writes).
 */
class CrossAssetWebsiteGbpPhoneConsistencyService
{
    public const MODULE_ID = 'cross-asset-analysis';

    public const PACK_ID = 'website-gbp-phone-consistency';

    public const EVIDENCE_TYPE_COMPARISON = 'cross_asset_phone_comparison';

    public const ASSET_TYPE_WEBSITE = 'website';

    public const ASSET_TYPE_GBP = 'google_business_profile';

    public const EVIDENCE_TYPE_PAGE_HTML = 'page_html';

    private const CONFIDENCE_HIGH = 0.9000;

    public function __construct(
        private readonly WebsiteTelephoneExtractor $telephoneExtractor = new WebsiteTelephoneExtractor,
    ) {}

    /**
     * Run the pack for a Website Digital Asset using Brand-scoped sibling GBP Evidence.
     */
    public function analyze(DigitalAsset $websiteAsset): Run
    {
        if ($websiteAsset->type !== self::ASSET_TYPE_WEBSITE) {
            throw new InvalidArgumentException('Website↔GBP phone pack requires a website Digital Asset.');
        }

        if ($websiteAsset->brand_id === null) {
            throw new InvalidArgumentException('Website↔GBP phone pack requires a Brand-scoped Website asset.');
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

            $websiteEvidence = $this->latestPageHtml($websiteAsset);
            $gbpEvidence = $gbpAsset instanceof DigitalAsset
                ? $this->latestSuccessfulGbpLocationAccess($gbpAsset)
                : null;

            $websitePhones = $this->websitePhonesFromEvidence($websiteEvidence);
            $gbpPhone = $this->gbpPhoneFromEvidence($gbpEvidence);
            $websiteNormalized = array_values(array_unique(array_filter(
                array_map(fn (string $phone): ?string => $this->telephoneExtractor->normalize($phone), $websitePhones),
            )));
            $gbpNormalized = is_string($gbpPhone) ? $this->telephoneExtractor->normalize($gbpPhone) : null;

            $skipReason = $this->determineSkipReason(
                gbpCandidateCount: $gbpCandidates->count(),
                websiteEvidence: $websiteEvidence,
                gbpEvidence: $gbpEvidence,
                websiteNormalized: $websiteNormalized,
                gbpNormalized: $gbpNormalized,
            );

            $phonesMatch = $skipReason === null
                && is_string($gbpNormalized)
                && in_array($gbpNormalized, $websiteNormalized, true);

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $websiteAsset->id,
                'source_module' => self::MODULE_ID,
                'type' => self::EVIDENCE_TYPE_COMPARISON,
                'title' => 'Website ↔ GBP phone comparison',
                'payload' => [
                    'pack_id' => self::PACK_ID,
                    'primary_digital_asset_id' => $websiteAsset->id,
                    'related_digital_asset_id' => $gbpAsset?->id,
                    'website_evidence_id' => $websiteEvidence?->id,
                    'gbp_evidence_id' => $gbpEvidence?->id,
                    'website_telephone_candidates' => $websitePhones,
                    'website_telephone_normalized' => $websiteNormalized,
                    'gbp_primary_phone' => $gbpPhone,
                    'gbp_telephone_normalized' => $gbpNormalized,
                    'phones_match' => $phonesMatch,
                    'skip_reason' => $skipReason,
                    'compared' => $skipReason === null,
                ],
                'observed_at' => $observedAt,
            ]);

            if ($skipReason === null && $phonesMatch === false && $gbpAsset instanceof DigitalAsset) {
                $this->upsertMismatchFinding(
                    websiteAsset: $websiteAsset,
                    gbpAsset: $gbpAsset,
                    run: $run,
                    websitePhones: $websitePhones,
                    gbpPhone: (string) $gbpPhone,
                    observedAt: $observedAt,
                );
            }

            $run->update([
                'status' => 'completed',
                'finished_at' => now(),
                'metadata' => array_merge($run->metadata ?? [], [
                    'skip_reason' => $skipReason,
                    'compared' => $skipReason === null,
                    'phones_match' => $phonesMatch,
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

    private function latestPageHtml(DigitalAsset $websiteAsset): ?Evidence
    {
        return Evidence::query()
            ->where('digital_asset_id', $websiteAsset->id)
            ->where('type', self::EVIDENCE_TYPE_PAGE_HTML)
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

    /**
     * @return list<string>
     */
    private function websitePhonesFromEvidence(?Evidence $evidence): array
    {
        if ($evidence === null || ! is_array($evidence->payload)) {
            return [];
        }

        $candidates = $evidence->payload['telephone_candidates'] ?? null;

        if (! is_array($candidates)) {
            return [];
        }

        $phones = [];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $phones[] = trim($candidate);
            }
        }

        return $phones;
    }

    private function gbpPhoneFromEvidence(?Evidence $evidence): ?string
    {
        if ($evidence === null || ! is_array($evidence->payload)) {
            return null;
        }

        $phone = $evidence->payload['primary_phone'] ?? null;

        return is_string($phone) && trim($phone) !== '' ? trim($phone) : null;
    }

    /**
     * @param  list<string>  $websiteNormalized
     */
    private function determineSkipReason(
        int $gbpCandidateCount,
        ?Evidence $websiteEvidence,
        ?Evidence $gbpEvidence,
        array $websiteNormalized,
        ?string $gbpNormalized,
    ): ?string {
        if ($gbpCandidateCount === 0) {
            return 'missing_gbp_asset';
        }

        if ($gbpCandidateCount > 1) {
            return 'ambiguous_gbp_asset';
        }

        if ($websiteEvidence === null) {
            return 'missing_website_page_html_evidence';
        }

        if ($gbpEvidence === null) {
            return 'missing_gbp_location_access_evidence';
        }

        if ($websiteNormalized === []) {
            return 'missing_website_telephone';
        }

        if ($gbpNormalized === null) {
            return 'missing_gbp_telephone';
        }

        return null;
    }

    /**
     * @param  list<string>  $websitePhones
     */
    private function upsertMismatchFinding(
        DigitalAsset $websiteAsset,
        DigitalAsset $gbpAsset,
        Run $run,
        array $websitePhones,
        string $gbpPhone,
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
            'title' => 'Website phone does not match Google Business Profile phone',
            'summary' => sprintf(
                'Brand Website telephone candidates [%s] differ from Google Business Profile primary phone %s on related asset #%d. Pack %s compared Evidence only; no external write performed.',
                implode(', ', $websitePhones),
                $gbpPhone,
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
            'title' => 'Align Website and Google Business Profile phone numbers',
            'action' => 'Review Website page telephone Evidence and Google Business Profile primaryPhone Evidence, then update the incorrect side in its native platform (Website CMS or Google Business Profile). DOP remains read-only.',
            'rationale' => sprintf(
                'Cross-asset pack %s found a phone mismatch between Website asset #%d and GBP asset #%d.',
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
}
