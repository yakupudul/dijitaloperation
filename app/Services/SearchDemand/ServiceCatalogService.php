<?php

namespace App\Services\SearchDemand;

use App\Models\ServiceCatalogItem;
use App\Models\ServiceCatalogName;
use App\Models\User;
use App\Support\BrandIntelligence\IdentityLabelNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ServiceCatalogService
{
    public function __construct(private readonly IdentityLabelNormalizer $normalizer) {}

    /** @return array{service: ServiceCatalogItem, created: bool} */
    public function resolveOrCreate(
        string $label,
        ?string $sector = null,
        ?string $description = null,
        ?string $locale = null,
        ?User $actor = null,
        string $provenance = 'operator',
    ): array {
        $label = trim($label);
        $normalized = $this->normalizer->normalize($label);

        if ($normalized === '') {
            throw ValidationException::withMessages(['service_name' => 'Hizmet adı gereklidir.']);
        }

        $existing = ServiceCatalogName::withoutGlobalScope('visible_service')
            ->with('service')
            ->where('normalized_key', $normalized)
            ->first();

        if ($existing instanceof ServiceCatalogName) {
            if ($existing->service === null) {
                throw ValidationException::withMessages(['service_name' => 'Bu ad silinmiş bir hizmete ait. Hizmetler ekranındaki Silinenler filtresinden geri alın.']);
            }

            return ['service' => $existing->service, 'created' => false];
        }

        try {
            $service = DB::transaction(function () use ($label, $normalized, $sector, $description, $locale, $actor, $provenance): ServiceCatalogItem {
                $service = ServiceCatalogItem::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'sector' => $this->nullable($sector),
                    'description' => $this->nullable($description),
                    'status' => 'active',
                    'created_by' => $actor?->id,
                    'updated_by' => $actor?->id,
                ]);

                ServiceCatalogName::withoutGlobalScope('visible_service')->create([
                    'service_catalog_item_id' => $service->id,
                    'raw_label' => $label,
                    'normalized_key' => $normalized,
                    'locale' => $this->nullable($locale),
                    'name_kind' => 'primary',
                    'is_primary' => true,
                    'is_active' => true,
                    'provenance' => $provenance,
                    'normalization_version' => $this->normalizer->version(),
                ]);

                return $service;
            });

            return ['service' => $service, 'created' => true];
        } catch (UniqueConstraintViolationException) {
            $existing = ServiceCatalogName::withoutGlobalScope('visible_service')
                ->with('service')
                ->where('normalized_key', $normalized)
                ->first();

            if ($existing instanceof ServiceCatalogName) {
                if ($existing->service === null) {
                    throw ValidationException::withMessages(['service_name' => 'Bu ad silinmiş bir hizmete ait. Hizmetler ekranındaki Silinenler filtresinden geri alın.']);
                }

                return ['service' => $existing->service, 'created' => false];
            }

            throw ValidationException::withMessages(['service_name' => 'Bu hizmet adı başka bir kayıt tarafından kullanılıyor.']);
        }
    }

    public function addAlias(ServiceCatalogItem $service, string $alias, ?string $locale = null, ?User $actor = null): ServiceCatalogName
    {
        $service = ServiceCatalogItem::query()->findOrFail($service->id);
        $alias = trim($alias);
        $normalized = $this->normalizer->normalize($alias);

        if ($normalized === '') {
            throw ValidationException::withMessages(['alias' => 'Hizmet eş adı gereklidir.']);
        }

        $claim = ServiceCatalogName::withoutGlobalScope('visible_service')->where('normalized_key', $normalized)->first();
        if ($claim instanceof ServiceCatalogName) {
            if ((int) $claim->service_catalog_item_id === (int) $service->id) {
                if ($claim->is_primary) {
                    return $claim;
                }
                $claim->is_active = true;
                $claim->raw_label = $alias;
                $claim->save();

                return $claim;
            }

            throw ValidationException::withMessages(['alias' => 'Bu eş ad başka bir hizmet tarafından kullanılıyor.']);
        }

        $name = ServiceCatalogName::withoutGlobalScope('visible_service')->create([
            'service_catalog_item_id' => $service->id,
            'raw_label' => $alias,
            'normalized_key' => $normalized,
            'locale' => $this->nullable($locale),
            'name_kind' => 'alias',
            'is_primary' => false,
            'is_active' => true,
            'provenance' => 'operator_alias',
            'normalization_version' => $this->normalizer->version(),
        ]);

        $service->forceFill(['updated_by' => $actor?->id])->save();

        return $name;
    }

    public function setStatus(ServiceCatalogItem $service, string $status, ?User $actor = null): ServiceCatalogItem
    {
        if (! in_array($status, ['active', 'archived'], true)) {
            throw ValidationException::withMessages(['status' => 'Geçersiz hizmet durumu.']);
        }

        $service = ServiceCatalogItem::query()->findOrFail($service->id);
        $service->forceFill(['status' => $status, 'updated_by' => $actor?->id])->save();

        return $service->refresh();
    }

    public function update(ServiceCatalogItem $service, string $label, ?string $sector, ?string $description, ?User $actor = null): ServiceCatalogItem
    {
        try {
            return DB::transaction(function () use ($service, $label, $sector, $description, $actor): ServiceCatalogItem {
                $service = ServiceCatalogItem::query()->lockForUpdate()->findOrFail($service->id);
                $label = trim($label);
                $normalized = $this->normalizer->normalize($label);
                if ($normalized === '') {
                    throw ValidationException::withMessages(['service_name' => 'Hizmet adı gereklidir.']);
                }
                if ($sector !== null && $sector !== ''
                    && ! \App\Models\ServiceCategory::query()->where('code', $sector)->exists()) {
                    throw ValidationException::withMessages(['service_sector' => 'Sektör bulunamadı. Listeyi yenileyin.']);
                }
                $claim = ServiceCatalogName::withoutGlobalScope('visible_service')->where('normalized_key', $normalized)->lockForUpdate()->first();
                if ($claim !== null && (int) $claim->service_catalog_item_id !== (int) $service->id) {
                    throw ValidationException::withMessages(['service_name' => 'Bu ad veya eş ad başka bir hizmete ait.']);
                }
                $service->names()->where('is_primary', true)->update(['is_primary' => false, 'name_kind' => 'alias']);
                if ($claim === null) {
                    $claim = new ServiceCatalogName([
                        'service_catalog_item_id' => $service->id, 'normalized_key' => $normalized,
                        'normalization_version' => $this->normalizer->version(),
                        'provenance' => 'operator', 'locale' => app()->getLocale(),
                    ]);
                }
                $claim->fill(['raw_label' => $label, 'is_primary' => true, 'is_active' => true, 'name_kind' => 'primary'])->save();
                $service->forceFill(['sector' => $this->nullable($sector), 'description' => $this->nullable($description), 'updated_by' => $actor?->id])->save();

                foreach ($service->brandOfferings()->orderBy('id')->lockForUpdate()->get() as $offering) {
                    app(\App\Services\BrandIntelligence\BrandOfferingService::class)->renameLocal($offering, $label, $actor);
                }
                $this->refreshBrandContexts($service);

                return $service->fresh(['primaryName']);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['service_name' => 'Bu ad başka bir kayıt tarafından kullanılıyor. Değişiklik kaydedilmedi.']);
        }
    }

    public function delete(ServiceCatalogItem $service, ?User $actor = null): void
    {
        DB::transaction(function () use ($service, $actor): void {
            $service = ServiceCatalogItem::query()->lockForUpdate()->findOrFail($service->id);
            $brandIds = $service->brandOfferings()->pluck('brand_id')->all();
            $service->forceFill(['updated_by' => $actor?->id])->save();
            $service->delete();
            $this->refreshBrandContexts($service, $brandIds);
        });
    }

    public function restore(int $id, ?User $actor = null): void
    {
        DB::transaction(function () use ($id, $actor): void {
            $service = ServiceCatalogItem::onlyTrashed()->lockForUpdate()->findOrFail($id);
            $service->restore();
            $service->forceFill(['updated_by' => $actor?->id])->save();
            $this->refreshBrandContexts($service);
        });
    }

    public function removeAlias(ServiceCatalogItem $service, int $nameId): void
    {
        $name = $service->names()->where('is_primary', false)->findOrFail($nameId);
        $name->update(['is_active' => false]);
    }

    /** Rebuild current presentation fields; historical observations remain unchanged. */
    public function refreshBrandContexts(ServiceCatalogItem $service, ?array $brandIds = null): void
    {
        $brandIds ??= $service->brandOfferings()->pluck('brand_id')->all();
        foreach (\App\Models\Brand::query()->whereIn('id', $brandIds)->get() as $brand) {
            $offerings = \App\Models\BrandOffering::query()->with(['primaryName', 'catalogItem'])
                ->where('brand_id', $brand->id)->where('status', 'active')->orderBy('id')->get();
            $rows = $offerings->map(fn ($offering) => [
                'name' => $offering->primaryName?->raw_label,
                'description' => $offering->catalogItem?->description,
            ])->filter(fn ($row) => filled($row['name']))->values()->all();
            \App\Models\BrandIntelligenceContext::query()->where('brand_id', $brand->id)
                ->update(['products_services' => json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            app(\App\Services\BrandIntelligence\BrandIntelligenceContextWriteService::class)->projectIdentityFields($brand);
        }
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
