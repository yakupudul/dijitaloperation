<?php

namespace App\Services\Website\PublicDiscovery;

use App\Enums\OfferingStatus;
use App\Models\Brand;
use App\Models\BrandIntelligenceContext;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\DigitalAsset;
use App\Models\DiscoveryCandidate;
use App\Models\User;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\SearchDemand\BrandCommercialContextService;
use App\Services\SearchDemand\SearchDemandCompetitorLibraryService;
use App\Services\SearchDemand\ServiceCatalogService;
use Illuminate\Validation\ValidationException;
use MoxDop\Website\Discovery\PublicPageExtractor;

final class DiscoveryCandidateApplicationService
{
    public function __construct(
        private readonly BrandOfferingService $offerings,
        private readonly ServiceCatalogService $catalog,
        private readonly BrandCommercialContextService $commercial,
        private readonly SearchDemandCompetitorLibraryService $competitors,
        private readonly PublicPageExtractor $extractor,
    ) {}

    /** Called inside the review transaction. The receipt describes an actual destination. */
    public function apply(DiscoveryCandidate $candidate, string $value, User $actor, array $options = []): array
    {
        $brand = Brand::query()->lockForUpdate()->findOrFail($candidate->brand_id);
        $asset = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')
            ->lockForUpdate()->findOrFail($candidate->digital_asset_id);
        $field = $candidate->target_field;
        $receipt = ['state' => 'applied', 'destination' => $field, 'brand_id' => $brand->id,
            'actor_id' => $actor->id, 'applied_at' => now()->toIso8601String(), 'label' => $value];
        $context = $brand->intelligenceContext()->firstOrNew(['brand_id' => $brand->id]);

        if ($field === 'products_services') {
            $offering = ! empty($options['offering_id'])
                ? BrandOffering::query()->where('brand_id', $brand->id)->find($options['offering_id'])
                : $this->offerings->resolveOrCreate($brand, $value, actor: $actor)['offering'];
            if ($offering === null || $offering->status !== OfferingStatus::Active) {
                throw ValidationException::withMessages(['offeringId' => 'Bu markaya ait aktif bir hizmet seçin. Arşivlenen hizmet otomatik açılmaz.']);
            }
            $label = $offering->primaryName?->raw_label ?? $value;
            $service = $offering->catalogItem ?? $this->catalog->resolveOrCreate($label, sector: $brand->sector, actor: $actor, provenance: 'public_discovery')['service'];
            if ($service->status !== 'active') {
                throw ValidationException::withMessages(['offeringId' => 'Kütüphanedeki hizmet arşivlenmiş. Önce kütüphaneden gözden geçirin.']);
            }
            if ($offering->service_catalog_item_id === null) {
                $offering->update(['service_catalog_item_id' => $service->id]);
            }
            $this->appendNamed($context, 'products_services', $label);
            $receipt += ['record_id' => $offering->id, 'service_catalog_item_id' => $service->id];
            $receipt['label'] = $label;
        } elseif (in_array($field, ['service_areas', 'target_markets'], true)
            || ($field === 'physical_addresses' && ($options['confirm_service_area'] ?? false))) {
            if (! ($options['confirm_service_area'] ?? false)) {
                throw ValidationException::withMessages(['confirmServiceArea' => 'Hizmet verilen bölgeyi ayrıca doğrulayın; adres tek başına hizmet kapsamı değildir.']);
            }
            $area = ! empty($options['service_area_id'])
                ? BrandServiceArea::query()->where('brand_id', $brand->id)->where('status', 'active')->find($options['service_area_id'])
                : $this->commercial->addServiceArea($brand, $options);
            if ($area === null) {
                throw ValidationException::withMessages(['serviceAreaId' => 'Bu markaya ait aktif bir bölge seçin.']);
            }
            $this->appendNamed($context, 'target_markets', $area->label());
            $receipt = array_merge($receipt, ['destination' => 'service_areas', 'record_id' => $area->id, 'label' => $area->label()]);
        } elseif ($field === 'known_competitors') {
            $competitor = $this->competitors->acceptDiscoveryCandidate($candidate, $value, $actor);
            $this->appendNamed($context, 'known_competitors', $competitor->normalized_domain, ['url' => 'https://'.$competitor->normalized_domain]);
            $receipt += ['record_id' => $competitor->id];
        } elseif ($field === 'social_links') {
            $url = trim(preg_replace('/^[a-z]+:\s+(?=https?:)/i', '', $value) ?? $value);
            $profile = $this->extractor->normalizeSocialProfile($url);
            if ($profile === null) {
                throw ValidationException::withMessages(['editedValue' => 'Geçerli bir sosyal profil URL’si girin; gönderi veya paylaşım bağlantısı kullanmayın.']);
            }

            return array_merge($receipt, $profile, ['state' => 'integration_ready', 'destination' => 'integrations', 'record_id' => $candidate->id]);
        } elseif ($field === 'languages') {
            if (! preg_match('/^[a-z]{2,3}(?:-[a-zA-Z0-9]{2,8})*$/', $value) || strtolower($value) === 'x-default') {
                throw ValidationException::withMessages(['editedValue' => 'Geçerli bir dil kodu girin (tr, en, en-GB).']);
            }
            $asset->update(['languages' => array_values(array_unique(array_merge($asset->languages ?? [], [$value])))]);

            return $receipt + ['record_id' => $asset->id];
        } elseif (in_array($field, ['business_summary', 'positioning'], true)) {
            $current = (string) ($context->{$field} ?? '');
            if (filled($current) && $this->normalize($current) !== $this->normalize($value)) {
                if (! ($options['replace_existing'] ?? false)) {
                    $candidate->support_json = array_merge($candidate->support_json ?? [], ['conflict_with_existing' => $current]);

                    return array_merge($receipt, ['state' => 'conflict', 'kept_value' => $current]);
                }
                if (($options['expected_current'] ?? null) !== $current) {
                    throw ValidationException::withMessages(['editedValue' => 'Mevcut bilgi değişmiş. İnceleme ekranını yeniden açın.']);
                }
            }
            $context->{$field} = $value;
        } elseif (in_array($field, ['differentiators', 'target_audiences'], true)) {
            $this->appendNamed($context, $field, $value);
        } else {
            return array_merge($receipt, ['state' => 'observation_only', 'destination' => 'source_information', 'record_id' => $candidate->id]);
        }
        $context->source = $value !== $candidate->proposed_value ? BrandIntelligenceContext::SOURCE_PUBLIC_DISCOVERY_EDITED : BrandIntelligenceContext::SOURCE_PUBLIC_DISCOVERY;
        $context->updated_by = $actor->id;
        $context->save();

        return $receipt;
    }

    private function appendNamed(BrandIntelligenceContext $context, string $field, string $label, array $extra = []): void
    {
        $rows = $context->{$field} ?? [];
        foreach ($rows as $row) {
            if ($this->normalize(is_string($row) ? $row : (string) ($row['name'] ?? '')) === $this->normalize($label)) {
                return;
            }
        }
        $rows[] = ['name' => $label, 'description' => 'Reviewed public discovery'] + $extra;
        $context->{$field} = $rows;
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
