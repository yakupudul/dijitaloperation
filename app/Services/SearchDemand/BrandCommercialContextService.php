<?php

namespace App\Services\SearchDemand;

use App\Enums\OfferingStatus;
use App\Models\Brand;
use App\Models\BrandIntelligenceContext;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\ServiceCatalogItem;
use App\Models\User;
use App\Services\BrandIntelligence\BrandIntelligenceContextWriteService;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Support\Options\CountryOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BrandCommercialContextService
{
    public function __construct(
        private readonly ServiceCatalogService $catalog,
        private readonly BrandOfferingService $offerings,
        private readonly BrandIntelligenceContextWriteService $contextWriter,
    ) {}

    /** Add one reviewed area without archiving other areas or changing operator priorities. */
    public function addServiceArea(Brand $brand, array $data): BrandServiceArea
    {
        $country = strtoupper(trim((string) ($data['country_code'] ?? '')));
        if (! array_key_exists($country, CountryOptions::options())) {
            throw ValidationException::withMessages(['countryCode' => 'Geçerli bir ülke seçin.']);
        }
        $data['country_code'] = $country;
        foreach (['city_name', 'district_name'] as $field) {
            if (! is_string($data[$field] ?? '') || mb_strlen($data[$field] ?? '') > 120) {
                throw ValidationException::withMessages(['area' => 'İl ve ilçe bilgisi en fazla 120 karakter olabilir.']);
            }
        }
        if (filled($data['district_name'] ?? null) && blank($data['city_name'] ?? null)) {
            throw ValidationException::withMessages(['cityName' => 'İlçe için il bilgisini de girin.']);
        }
        $normalized = $this->normalizeAreas([$data])[0];
        $area = BrandServiceArea::query()->firstOrNew(['brand_id' => $brand->id, 'normalized_key' => $normalized['normalized_key']]);
        if ($area->exists && $area->status !== 'active') {
            throw ValidationException::withMessages(['area' => 'Bu bölge arşivlenmiş. Önce marka bölge listesinden gözden geçirin.']);
        }
        if (! $area->exists) {
            $area->fill($normalized + ['status' => 'active'])->save();
        }

        return $area;
    }

    /**
     * @param  list<int|string>  $serviceCatalogIds
     * @param  list<int|string>  $priorityServiceCatalogIds
     * @param  list<array{country_code?: string, city_name?: string, district_name?: string}>  $areas
     */
    public function sync(
        Brand $brand,
        array $serviceCatalogIds,
        array $priorityServiceCatalogIds,
        array $areas,
        ?string $customServiceName = null,
        bool $customServicePriority = false,
        ?User $actor = null,
        ?string $customServiceSector = null,
        ?array $allowedSectorCodes = null,
    ): void {
        DB::transaction(function () use ($brand, $serviceCatalogIds, $priorityServiceCatalogIds, $areas, $customServiceName, $customServicePriority, $actor, $customServiceSector, $allowedSectorCodes): void {
            $catalogIds = array_values(array_unique(array_filter(array_map('intval', $serviceCatalogIds))));
            $customCatalogId = null;

            if (trim((string) $customServiceName) !== '') {
                $result = $this->catalog->resolveOrCreate(
                    label: (string) $customServiceName,
                    sector: $customServiceSector ?: $brand->sector,
                    actor: $actor,
                );
                $customCatalogId = (int) $result['service']->id;
                $catalogIds[] = $customCatalogId;
                $catalogIds = array_values(array_unique($catalogIds));
            }

            $services = ServiceCatalogItem::query()
                ->with('primaryName')
                ->whereIn('id', $catalogIds)
                ->where('status', 'active')
                ->get()
                ->keyBy(fn (ServiceCatalogItem $item): int => (int) $item->id);

            if ($services->count() !== count($catalogIds)) {
                throw ValidationException::withMessages(['selected_service_catalog_ids' => 'Seçilen hizmetlerden biri bulunamadı veya arşivlendi.']);
            }

            if ($allowedSectorCodes !== null && $services->contains(
                fn (ServiceCatalogItem $service): bool => ! in_array($service->sector, $allowedSectorCodes, true)
            )) {
                throw ValidationException::withMessages(['selected_service_catalog_ids' => __('brand-form.scope_error')]);
            }
            if ($customCatalogId !== null && $customServiceSector !== null
                && $services->get($customCatalogId)?->sector !== $customServiceSector) {
                throw ValidationException::withMessages(['new_service_name' => __('brand-form.existing_sector_error')]);
            }

            $brandOfferingIdsByCatalog = [];
            foreach ($catalogIds as $catalogId) {
                $service = $services->get($catalogId);
                $label = $service?->primaryName?->raw_label;
                if (! is_string($label) || $label === '') {
                    continue;
                }

                $offering = BrandOffering::query()
                    ->where('brand_id', $brand->id)
                    ->where('service_catalog_item_id', $catalogId)
                    ->first();

                if (! $offering instanceof BrandOffering) {
                    $resolved = $this->offerings->resolveOrCreate($brand, $label, actor: $actor);
                    $offering = $resolved['offering'];
                    if ($offering->service_catalog_item_id === null) {
                        $offering->service_catalog_item_id = $catalogId;
                    } elseif ((int) $offering->service_catalog_item_id !== $catalogId) {
                        throw ValidationException::withMessages(['selected_service_catalog_ids' => "{$label} başka bir katalog hizmetine bağlı."]);
                    }
                }

                $offering->status = OfferingStatus::Active;
                $offering->save();
                $brandOfferingIdsByCatalog[$catalogId] = (int) $offering->id;
            }

            BrandOffering::query()
                ->where('brand_id', $brand->id)
                ->whereNotNull('service_catalog_item_id')
                ->whereNotIn('service_catalog_item_id', $catalogIds === [] ? [-1] : $catalogIds)
                ->get()
                ->each(fn (BrandOffering $offering) => $this->offerings->archive($offering, $actor));

            $priorityCatalogIds = array_values(array_unique(array_map('intval', $priorityServiceCatalogIds)));
            if ($customServicePriority && $customCatalogId !== null) {
                $priorityCatalogIds[] = $customCatalogId;
                $priorityCatalogIds = array_values(array_unique($priorityCatalogIds));
            }
            $priorityOfferingIds = [];
            foreach ($priorityCatalogIds as $catalogId) {
                if (isset($brandOfferingIdsByCatalog[$catalogId])) {
                    $priorityOfferingIds[] = $brandOfferingIdsByCatalog[$catalogId];
                }
            }
            $this->offerings->setPriorityOrder($brand, $priorityOfferingIds, $actor);

            $normalizedAreas = $this->normalizeAreas($areas);
            $desiredKeys = [];
            foreach ($normalizedAreas as $rank => $area) {
                $desiredKeys[] = $area['normalized_key'];
                $existing = BrandServiceArea::query()->where('brand_id', $brand->id)->where('normalized_key', $area['normalized_key'])->first();
                if (! $existing && $area['country_code'] === 'TR') {
                    $existing = BrandServiceArea::query()->where('brand_id', $brand->id)->where('country_code', 'TR')->get()
                        ->first(fn ($row): bool =>
                            \App\Support\Options\LocationOptions::fold((string) $row->city_name) === \App\Support\Options\LocationOptions::fold((string) $area['city_name'])
                            && \App\Support\Options\LocationOptions::fold((string) $row->district_name) === \App\Support\Options\LocationOptions::fold((string) $area['district_name']));
                }
                $existing ??= new BrandServiceArea(['brand_id' => $brand->id]);
                $existing->fill(array_merge($area, ['status' => 'active', 'priority_rank' => $rank + 1]))->save();
            }

            BrandServiceArea::query()
                ->where('brand_id', $brand->id)
                ->when($desiredKeys !== [], fn ($query) => $query->whereNotIn('normalized_key', $desiredKeys))
                ->when($desiredKeys === [], fn ($query) => $query)
                ->update(['status' => 'archived', 'priority_rank' => null]);

            $countries = collect($normalizedAreas)->pluck('country_code')->unique()->values()->all();
            $brand->forceFill([
                'primary_country' => $countries[0] ?? null,
                'target_markets' => $countries,
            ])->save();

            $this->projectBrandContext($brand, $services->values()->all(), $normalizedAreas, $actor);
        });
    }

    /** @param list<ServiceCatalogItem> $services @param list<array<string, mixed>> $areas */
    private function projectBrandContext(Brand $brand, array $services, array $areas, ?User $actor): void
    {
        $context = BrandIntelligenceContext::query()->firstOrNew(['brand_id' => $brand->id]);
        $context->products_services = collect($services)
            ->map(fn (ServiceCatalogItem $service): array => [
                'name' => $service->primaryName?->raw_label,
                'description' => $service->description,
            ])
            ->filter(fn (array $row): bool => is_string($row['name']) && $row['name'] !== '')
            ->values()
            ->all();
        $context->target_markets = collect($areas)
            ->map(fn (array $area): array => [
                'name' => collect([$area['district_name'], $area['city_name'], $area['country_name'] ?: $area['country_code']])
                    ->filter()
                    ->implode(', '),
                'note' => null,
            ])
            ->values()
            ->all();
        $context->source = BrandIntelligenceContext::SOURCE_OPERATOR;
        $context->updated_by = $actor?->id;

        if ($context->exists) {
            $context->save();
        } else {
            BrandIntelligenceContext::withLegacyIdentityProjection(function () use ($context): void {
                $context->priority_offerings = [];
                $context->business_goals = [];
                $context->conversion_goals = [];
                $context->save();
            });
        }

        $this->contextWriter->projectIdentityFields($brand);
    }

    /**
     * @param  list<array{country_code?: string, city_name?: string, district_name?: string}>  $areas
     * @return list<array{country_code: string, country_name: ?string, city_name: ?string, district_name: ?string, normalized_key: string}>
     */
    private function normalizeAreas(array $areas): array
    {
        $result = [];
        foreach ($areas as $area) {
            $countryCode = strtoupper(trim((string) ($area['country_code'] ?? '')));
            if ($countryCode === '') {
                continue;
            }

            $validated = \App\Support\Options\LocationOptions::normalizeArea($countryCode, $area['city_name'] ?? null, $area['district_name'] ?? null);
            $city = $validated['city_name'];
            $district = $validated['district_name'];
            $identity = mb_strtolower(implode('|', [$countryCode, $city ?? '', $district ?? '']), 'UTF-8');
            $key = hash('sha256', $identity);

            $result[$key] = [
                'country_code' => $countryCode,
                'country_name' => CountryOptions::label($countryCode),
                'city_name' => $city,
                'district_name' => $district,
                'normalized_key' => $key,
            ];
        }

        return array_values($result);
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
