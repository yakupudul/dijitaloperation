<?php

namespace App\Services;

use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Services\GoogleBusinessProfileConnectionProbeService as GbpLocationAccessEvidence;
use App\Support\WebsitePostalAddressExtractor;
use DateTimeInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Deterministic Website ↔ Google Business Profile address (NAP) consistency pack.
 *
 * Reads existing page_html + gbp_location_access Evidence only (no external fetches / writes).
 */
class CrossAssetWebsiteGbpAddressConsistencyService
{
    public const MODULE_ID = 'cross-asset-analysis';

    public const PACK_ID = 'website-gbp-address-consistency';

    public const EVIDENCE_TYPE_COMPARISON = 'cross_asset_address_comparison';

    public const ASSET_TYPE_WEBSITE = 'website';

    public const ASSET_TYPE_GBP = 'google_business_profile';

    public const EVIDENCE_TYPE_PAGE_HTML = 'page_html';

    private const CONFIDENCE_HIGH = 0.9000;

    public function __construct(
        private readonly WebsitePostalAddressExtractor $addressExtractor = new WebsitePostalAddressExtractor,
    ) {}

    public function analyze(DigitalAsset $websiteAsset): Run
    {
        if ($websiteAsset->type !== self::ASSET_TYPE_WEBSITE) {
            throw new InvalidArgumentException('Website↔GBP address pack requires a website Digital Asset.');
        }

        if ($websiteAsset->brand_id === null) {
            throw new InvalidArgumentException('Website↔GBP address pack requires a Brand-scoped Website asset.');
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

            $websiteAddresses = $this->websiteAddressesFromEvidence($websiteEvidence);
            $gbpAddress = $this->gbpAddressFromEvidence($gbpEvidence);

            $websiteNormalized = [];
            foreach ($websiteAddresses as $address) {
                $key = $this->addressExtractor->normalizeKey([
                    'street_address' => $address['street_address'] ?? null,
                    'locality' => $address['locality'] ?? null,
                    'region' => $address['region'] ?? null,
                    'postal_code' => $address['postal_code'] ?? null,
                    'country' => $address['country'] ?? null,
                ]);
                if (is_string($key)) {
                    $websiteNormalized[] = $key;
                }
            }
            $websiteNormalized = array_values(array_unique($websiteNormalized));

            $gbpNormalized = is_array($gbpAddress)
                ? $this->addressExtractor->normalizeKey([
                    'address_lines' => $gbpAddress['address_lines'] ?? [],
                    'locality' => $gbpAddress['locality'] ?? null,
                    'region' => $gbpAddress['administrative_area'] ?? null,
                    'postal_code' => $gbpAddress['postal_code'] ?? null,
                    'country' => $gbpAddress['region_code'] ?? null,
                ])
                : null;

            $skipReason = $this->determineSkipReason(
                gbpCandidateCount: $gbpCandidates->count(),
                websiteEvidence: $websiteEvidence,
                gbpEvidence: $gbpEvidence,
                websiteNormalized: $websiteNormalized,
                gbpNormalized: $gbpNormalized,
            );

            $addressesMatch = $skipReason === null
                && is_string($gbpNormalized)
                && in_array($gbpNormalized, $websiteNormalized, true);

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $websiteAsset->id,
                'source_module' => self::MODULE_ID,
                'type' => self::EVIDENCE_TYPE_COMPARISON,
                'title' => 'Website ↔ GBP address comparison',
                'payload' => [
                    'pack_id' => self::PACK_ID,
                    'primary_digital_asset_id' => $websiteAsset->id,
                    'related_digital_asset_id' => $gbpAsset?->id,
                    'website_evidence_id' => $websiteEvidence?->id,
                    'gbp_evidence_id' => $gbpEvidence?->id,
                    'website_postal_address_candidates' => $websiteAddresses,
                    'website_address_normalized' => $websiteNormalized,
                    'gbp_storefront_address' => $gbpAddress,
                    'gbp_address_normalized' => $gbpNormalized,
                    'addresses_match' => $addressesMatch,
                    'skip_reason' => $skipReason,
                    'compared' => $skipReason === null,
                ],
                'observed_at' => $observedAt,
            ]);

            if ($skipReason === null && $addressesMatch === false && $gbpAsset instanceof DigitalAsset) {
                $this->upsertMismatchFinding(
                    websiteAsset: $websiteAsset,
                    gbpAsset: $gbpAsset,
                    run: $run,
                    websiteAddresses: $websiteAddresses,
                    gbpAddress: $gbpAddress ?? [],
                    observedAt: $observedAt,
                );
            }

            $run->update([
                'status' => 'completed',
                'finished_at' => now(),
                'metadata' => array_merge($run->metadata ?? [], [
                    'skip_reason' => $skipReason,
                    'compared' => $skipReason === null,
                    'addresses_match' => $addressesMatch,
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
     * @return list<array{
     *     street_address: string|null,
     *     locality: string|null,
     *     region: string|null,
     *     postal_code: string|null,
     *     country: string|null,
     *     formatted: string
     * }>
     */
    private function websiteAddressesFromEvidence(?Evidence $evidence): array
    {
        if ($evidence === null || ! is_array($evidence->payload)) {
            return [];
        }

        $candidates = $evidence->payload['postal_address_candidates'] ?? null;

        if (! is_array($candidates)) {
            return [];
        }

        $addresses = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $formatted = $candidate['formatted'] ?? null;
            if (! is_string($formatted) || trim($formatted) === '') {
                continue;
            }

            $addresses[] = [
                'street_address' => is_string($candidate['street_address'] ?? null) ? $candidate['street_address'] : null,
                'locality' => is_string($candidate['locality'] ?? null) ? $candidate['locality'] : null,
                'region' => is_string($candidate['region'] ?? null) ? $candidate['region'] : null,
                'postal_code' => is_string($candidate['postal_code'] ?? null) ? $candidate['postal_code'] : null,
                'country' => is_string($candidate['country'] ?? null) ? $candidate['country'] : null,
                'formatted' => trim($formatted),
            ];
        }

        return $addresses;
    }

    /**
     * @return array{
     *     region_code: string|null,
     *     postal_code: string|null,
     *     administrative_area: string|null,
     *     locality: string|null,
     *     address_lines: list<string>
     * }|null
     */
    private function gbpAddressFromEvidence(?Evidence $evidence): ?array
    {
        if ($evidence === null || ! is_array($evidence->payload)) {
            return null;
        }

        $address = $evidence->payload['storefront_address'] ?? null;

        return is_array($address) ? $address : null;
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
            return 'missing_website_address';
        }

        if ($gbpNormalized === null) {
            return 'missing_gbp_address';
        }

        return null;
    }

    /**
     * @param  list<array{formatted: string}>  $websiteAddresses
     * @param  array<string, mixed>  $gbpAddress
     */
    private function upsertMismatchFinding(
        DigitalAsset $websiteAsset,
        DigitalAsset $gbpAsset,
        Run $run,
        array $websiteAddresses,
        array $gbpAddress,
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

        $websiteFormatted = array_map(
            fn (array $address): string => (string) $address['formatted'],
            $websiteAddresses,
        );
        $gbpFormatted = implode(', ', array_values(array_filter([
            ...(is_array($gbpAddress['address_lines'] ?? null) ? $gbpAddress['address_lines'] : []),
            $gbpAddress['locality'] ?? null,
            $gbpAddress['administrative_area'] ?? null,
            $gbpAddress['postal_code'] ?? null,
            $gbpAddress['region_code'] ?? null,
        ], fn ($value): bool => is_string($value) && trim($value) !== '')));

        $finding->fill([
            'source_module' => self::MODULE_ID,
            'category' => 'cross-channel',
            'severity' => 'medium',
            'title' => 'Website address does not match Google Business Profile address',
            'summary' => sprintf(
                'Brand Website postal address candidates [%s] differ from Google Business Profile storefront address [%s] on related asset #%d. Pack %s compared Evidence only; no external write performed.',
                implode(' | ', $websiteFormatted),
                $gbpFormatted,
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
            'digital_asset_id' => $websiteAsset->id,
            'source_module' => self::MODULE_ID,
            'title' => 'Align Website and Google Business Profile addresses',
            'action' => 'Review Website page postal-address Evidence and Google Business Profile storefrontAddress Evidence, then update the incorrect side in its native platform (Website CMS or Google Business Profile). DOP remains read-only.',
            'rationale' => sprintf(
                'Cross-asset pack %s found an address mismatch between Website asset #%d and GBP asset #%d.',
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
