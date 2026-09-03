<?php

namespace App\Services\SearchDemand;

use App\Enums\IntelligenceCore\IntelligenceSourceClass;
use App\Enums\IntelligenceCore\SearchTermKind;
use App\Models\Brand;
use App\Models\BrandQueryPortfolioAsset;
use App\Models\BrandQueryPortfolioItem;
use App\Models\BrandServiceArea;
use App\Models\DigitalAsset;
use App\Models\SearchQueryLibraryItem;
use App\Models\User;
use App\Services\IntelligenceCore\Identity\SearchTermIdentityResolver;
use App\Services\IntelligenceCore\Identity\SearchTermNormalizer;
use App\Support\IntelligenceCore\IntelligenceSourceReference;
use App\Support\IntelligenceCore\IntelligenceTimeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BrandQueryPortfolioService
{
    public function __construct(
        private readonly SearchTermNormalizer $normalizer,
        private readonly SearchTermIdentityResolver $identities,
    ) {}

    /** @return array{created: int, existing: int, eligible: int} */
    public function inheritForBrand(Brand $brand, ?User $actor = null): array
    {
        $serviceIds = $brand->offerings()
            ->where('status', 'active')
            ->whereNotNull('service_catalog_item_id')
            ->pluck('service_catalog_item_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($serviceIds->isEmpty()) {
            throw ValidationException::withMessages([
                'selectedBrandId' => 'Önce markaya en az bir etkin katalog hizmeti ekleyin.',
            ]);
        }

        $libraryItems = SearchQueryLibraryItem::query()
            ->with('services')
            ->where('status', 'active')
            ->where('is_branded', false)
            ->whereHas('services', fn ($query) => $query->whereIn('service_catalog_items.id', $serviceIds->all()))
            ->orderBy('id')
            ->get();

        $created = 0;
        $existing = 0;

        DB::transaction(function () use ($brand, $actor, $serviceIds, $libraryItems, &$created, &$existing): void {
            foreach ($libraryItems as $libraryItem) {
                $portfolioItem = BrandQueryPortfolioItem::query()->firstOrCreate(
                    [
                        'brand_id' => $brand->id,
                        'search_query_library_item_id' => $libraryItem->id,
                    ],
                    [
                        'uuid' => (string) Str::uuid(),
                        'identity_hash' => hash('sha256', 'brand:'.$brand->id.'|library:'.$libraryItem->id),
                        'area_scope' => 'all_brand_areas',
                        'origin_type' => 'global_inherited',
                        'status' => 'active',
                        'global_proposal_status' => 'not_applicable',
                        'created_by' => $actor?->id,
                        'updated_by' => $actor?->id,
                    ],
                );

                if ($portfolioItem->wasRecentlyCreated) {
                    $created++;
                } else {
                    $existing++;
                }

                $applicableServiceIds = $libraryItem->services
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->intersect($serviceIds)
                    ->values();
                $portfolioItem->services()->sync(
                    $applicableServiceIds->mapWithKeys(fn (int $id): array => [
                        $id => ['provenance' => 'global_inheritance'],
                    ])->all(),
                );

                $this->resolveIdentity($portfolioItem->load('libraryItem'), $brand);
            }
        });

        return [
            'created' => $created,
            'existing' => $existing,
            'eligible' => $libraryItems->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{item: BrandQueryPortfolioItem, created: bool}
     */
    public function addBrandQuery(Brand $brand, string $query, array $attributes, ?User $actor = null): array
    {
        $language = $this->nullable($attributes['language_code'] ?? null);
        $market = $this->nullable($attributes['market_code'] ?? null);
        $normalized = $this->normalizer->normalize($query, $language);
        if ($normalized->canonicalText === '') {
            throw ValidationException::withMessages(['brandQueryText' => 'Markaya özel sorgu metni gereklidir.']);
        }

        $serviceId = is_numeric($attributes['service_catalog_item_id'] ?? null)
            ? (int) $attributes['service_catalog_item_id']
            : null;
        if ($serviceId !== null && ! $brand->offerings()
            ->where('status', 'active')
            ->where('service_catalog_item_id', $serviceId)
            ->exists()) {
            throw ValidationException::withMessages([
                'brandQueryServiceId' => 'Sorgu yalnızca markanın etkin hizmetlerinden birine bağlanabilir.',
            ]);
        }

        $identityHash = hash('sha256', json_encode([
            'brand_id' => $brand->id,
            'origin' => 'brand_custom',
            'canonical_text' => $normalized->canonicalText,
            'language_code' => $language,
            'market_code' => $market,
            'normalization_version' => $normalized->normalizationVersion,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $brand,
            $actor,
            $attributes,
            $language,
            $market,
            $normalized,
            $identityHash,
            $serviceId,
        ): array {
            $item = BrandQueryPortfolioItem::query()->firstOrCreate(
                ['identity_hash' => $identityHash],
                [
                    'uuid' => (string) Str::uuid(),
                    'brand_id' => $brand->id,
                    'custom_canonical_text' => $normalized->canonicalText,
                    'custom_folded_text' => $normalized->foldedText,
                    'language_code' => $language,
                    'market_code' => $market,
                    'demand_family_override' => $this->boundedNullable($attributes['demand_family'] ?? null, 255),
                    'location_scope_override' => $this->locationScope($attributes['location_scope'] ?? 'none'),
                    'location_value_override' => $this->boundedNullable($attributes['location_value'] ?? null, 255),
                    'is_branded_override' => (bool) ($attributes['is_branded'] ?? false),
                    'area_scope' => 'all_brand_areas',
                    'origin_type' => 'brand_custom',
                    'status' => 'active',
                    'global_proposal_status' => 'not_submitted',
                    'created_by' => $actor?->id,
                    'updated_by' => $actor?->id,
                ],
            );

            if ($serviceId !== null) {
                $item->services()->syncWithoutDetaching([
                    $serviceId => ['provenance' => 'brand_operator'],
                ]);
            }

            $created = $item->wasRecentlyCreated;
            $this->resolveIdentity($item->load('libraryItem'), $brand);

            return ['item' => $item->refresh(), 'created' => $created];
        });
    }

    /** @param array<string, mixed> $attributes */
    public function updateOverrides(
        BrandQueryPortfolioItem $item,
        array $attributes,
        ?User $actor = null,
    ): BrandQueryPortfolioItem {
        $language = $this->nullable($attributes['language_code'] ?? $item->effectiveLanguageCode());
        $queryOverride = $this->nullable($attributes['query_text_override'] ?? null);
        if ($queryOverride !== null) {
            $queryOverride = $this->normalizer->normalize($queryOverride, $language)->canonicalText;
        }

        $brandedOverride = match ((string) ($attributes['is_branded_override'] ?? 'inherit')) {
            'yes' => true,
            'no' => false,
            default => null,
        };

        $item->forceFill([
            'query_text_override' => $queryOverride,
            'language_code' => $language,
            'market_code' => $this->nullable($attributes['market_code'] ?? null),
            'demand_family_override' => $this->boundedNullable($attributes['demand_family_override'] ?? null, 255),
            'location_scope_override' => $this->nullable($attributes['location_scope_override'] ?? null) !== null
                ? $this->locationScope($attributes['location_scope_override'])
                : null,
            'location_value_override' => $this->boundedNullable($attributes['location_value_override'] ?? null, 255),
            'is_branded_override' => $brandedOverride,
            'updated_by' => $actor?->id,
        ])->save();

        $this->resolveIdentity($item->load('libraryItem'), $item->brand);

        return $item->refresh();
    }

    public function setStatus(BrandQueryPortfolioItem $item, string $status, ?User $actor = null): void
    {
        if (! in_array($status, ['active', 'excluded', 'archived'], true)) {
            throw ValidationException::withMessages(['status' => 'Geçersiz portföy durumu.']);
        }

        $item->forceFill(['status' => $status, 'updated_by' => $actor?->id])->save();
    }

    /** @param list<int|string> $areaIds */
    public function setAreas(BrandQueryPortfolioItem $item, array $areaIds, ?User $actor = null): void
    {
        $ids = collect($areaIds)
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            $item->serviceAreas()->detach();
            $item->forceFill(['area_scope' => 'all_brand_areas', 'updated_by' => $actor?->id])->save();

            return;
        }

        $validIds = BrandServiceArea::query()
            ->where('brand_id', $item->brand_id)
            ->where('status', 'active')
            ->whereKey($ids->all())
            ->pluck('id');
        if ($validIds->count() !== $ids->count()) {
            throw ValidationException::withMessages(['selectedAreaIds' => 'Seçilen bölgeler bu markaya ait değil.']);
        }

        $item->serviceAreas()->sync($validIds->all());
        $item->forceFill(['area_scope' => 'selected_areas', 'updated_by' => $actor?->id])->save();
    }

    public function setWebsiteStatus(
        BrandQueryPortfolioItem $item,
        int $digitalAssetId,
        string $status,
        ?User $actor = null,
    ): BrandQueryPortfolioAsset {
        if (! in_array($status, ['active', 'excluded'], true)) {
            throw ValidationException::withMessages(['assetStatus' => 'Geçersiz website sorgu durumu.']);
        }

        $asset = DigitalAsset::query()
            ->where('brand_id', $item->brand_id)
            ->where('type', 'website')
            ->findOrFail($digitalAssetId);

        return BrandQueryPortfolioAsset::query()->updateOrCreate(
            [
                'brand_query_portfolio_item_id' => $item->id,
                'digital_asset_id' => $asset->id,
            ],
            [
                'status' => $status,
                'updated_by' => $actor?->id,
            ],
        );
    }

    public function proposeToGlobal(BrandQueryPortfolioItem $item, ?User $actor = null): void
    {
        if ($item->origin_type !== 'brand_custom') {
            throw ValidationException::withMessages([
                'globalProposal' => 'Yalnızca markaya özel sorgular global kütüphaneye önerilebilir.',
            ]);
        }

        $item->forceFill([
            'global_proposal_status' => 'submitted',
            'global_proposed_at' => now(),
            'global_proposed_by' => $actor?->id,
            'updated_by' => $actor?->id,
        ])->save();
    }

    /**
     * Render-only location variants. No Service × Area or query × area rows are created.
     *
     * @return list<array{area_id: ?int, area: ?string, query: string}>
     */
    public function locationVariants(BrandQueryPortfolioItem $item): array
    {
        $text = $item->effectiveQueryText();
        if ($item->effectiveLocationScope() !== 'pattern') {
            return [['area_id' => null, 'area' => null, 'query' => $text]];
        }

        $item->loadMissing(['brand.serviceAreas', 'serviceAreas']);
        $areas = $item->area_scope === 'selected_areas'
            ? $item->serviceAreas
            : $item->brand->serviceAreas->where('status', 'active');
        $locationValue = $item->effectiveLocationValue();
        $template = str_contains($text, '{location}')
            ? $text
            : (($locationValue !== null && str_contains($locationValue, '{location}'))
                ? (trim(str_replace('{location}', '', $locationValue)) === '' ? $locationValue.' '.$text : $locationValue)
                : '{location} '.$text);

        return $areas
            ->map(function (BrandServiceArea $area) use ($template): array {
                $label = $area->district_name ?: ($area->city_name ?: ($area->country_name ?: $area->country_code));

                return [
                    'area_id' => $area->id,
                    'area' => $area->label(),
                    'query' => trim(str_replace('{location}', $label, $template)),
                ];
            })
            ->unique('query')
            ->take(100)
            ->values()
            ->all();
    }

    private function resolveIdentity(BrandQueryPortfolioItem $item, Brand $brand): void
    {
        $text = $item->effectiveQueryText();
        if ($text === '') {
            return;
        }

        $identity = $this->identities->resolve(
            brand: $brand,
            observedText: $text,
            termKind: SearchTermKind::MoxdopTopic,
            source: new IntelligenceSourceReference(
                providerOrSource: 'moxdop',
                sourceClass: IntelligenceSourceClass::OperatorMaintained,
                sourceSemantic: 'brand_query_portfolio',
                datasetId: 'brand-query-portfolio',
                sourceRecordKey: $item->uuid,
                contractVersion: 1,
            ),
            time: new IntelligenceTimeContext(
                sourceTimezone: 'UTC',
                observedAt: now(),
                retrievedAt: now(),
                marketCode: $item->effectiveMarketCode(),
                languageCode: $item->effectiveLanguageCode(),
            ),
            metadata: [
                'brand_query_portfolio_item_id' => $item->id,
                'search_query_library_item_id' => $item->search_query_library_item_id,
                'origin_type' => $item->origin_type,
            ],
        );

        $item->forceFill(['intelligence_search_term_identity_id' => $identity->id])->save();
    }

    private function locationScope(mixed $value): string
    {
        $scope = trim((string) $value);

        return in_array($scope, ['none', 'country', 'city', 'district', 'pattern'], true) ? $scope : 'none';
    }

    private function nullable(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function boundedNullable(mixed $value, int $length): ?string
    {
        $value = $this->nullable($value);

        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
