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

        $existing = ServiceCatalogName::query()
            ->with('service')
            ->where('normalized_key', $normalized)
            ->first();

        if ($existing instanceof ServiceCatalogName) {
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

                ServiceCatalogName::query()->create([
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
            $existing = ServiceCatalogName::query()
                ->with('service')
                ->where('normalized_key', $normalized)
                ->first();

            if ($existing instanceof ServiceCatalogName) {
                return ['service' => $existing->service, 'created' => false];
            }

            throw ValidationException::withMessages(['service_name' => 'Bu hizmet adı başka bir kayıt tarafından kullanılıyor.']);
        }
    }

    public function addAlias(ServiceCatalogItem $service, string $alias, ?string $locale = null, ?User $actor = null): ServiceCatalogName
    {
        $alias = trim($alias);
        $normalized = $this->normalizer->normalize($alias);

        if ($normalized === '') {
            throw ValidationException::withMessages(['alias' => 'Hizmet eş adı gereklidir.']);
        }

        $claim = ServiceCatalogName::query()->where('normalized_key', $normalized)->first();
        if ($claim instanceof ServiceCatalogName) {
            if ((int) $claim->service_catalog_item_id === (int) $service->id) {
                $claim->is_active = true;
                $claim->raw_label = $alias;
                $claim->save();

                return $claim;
            }

            throw ValidationException::withMessages(['alias' => 'Bu eş ad başka bir hizmet tarafından kullanılıyor.']);
        }

        $name = ServiceCatalogName::query()->create([
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

        $service->forceFill(['status' => $status, 'updated_by' => $actor?->id])->save();

        return $service->refresh();
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
