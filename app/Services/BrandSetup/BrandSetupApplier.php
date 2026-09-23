<?php

namespace App\Services\BrandSetup;

use App\Models\BrandSetupProposal;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\Integrations\ConfirmGoogleResourceBindingService;
use App\Services\Integrations\ConfirmMetaResourceBindingService;
use App\Services\SearchDemand\ServiceCatalogService;
use App\Services\SeoTasks\SeoPlanRunner;
use App\Support\Integrations\ResourceBindingPlan;
use Throwable;

/**
 * Applies the operator-approved part of a setup proposal through the existing, validated services
 * (binding confirmation, offerings, service catalog). Each item succeeds or fails on its own and the
 * outcome is recorded; nothing outside MoxDOP is written.
 */
final class BrandSetupApplier
{
    private const array ASSET_LABELS = [
        'google_business_profile' => 'İşletme Profili',
        'google_ads' => 'Google Ads',
        'meta_ads' => 'Meta Ads',
    ];

    public function __construct(
        private readonly ConfirmGoogleResourceBindingService $google,
        private readonly ConfirmMetaResourceBindingService $meta,
        private readonly BrandOfferingService $offerings,
        private readonly ServiceCatalogService $catalog,
    ) {}

    /**
     * @param  list<string>  $itemKeys  selected asset/binding item keys
     * @param  list<int>  $serviceIndexes  selected service rows
     * @return list<array{key: string, label: string, ok: bool, message: string}>
     */
    public function apply(BrandSetupProposal $proposal, User $actor, array $itemKeys, array $serviceIndexes): array
    {
        $brand = $proposal->brand()->firstOrFail();
        $selected = array_flip($itemKeys);
        $results = [];
        $items = $proposal->items ?? [];

        // 1) Website asset.
        $websiteItem = collect($items)->firstWhere('key', 'asset:website');
        $website = $websiteItem !== null && $websiteItem['asset_id'] ? DigitalAsset::query()->find($websiteItem['asset_id']) : null;
        if ($website === null && $websiteItem !== null && isset($selected['asset:website'])) {
            $host = BrandSetupMatcher::host((string) $websiteItem['url']);
            $website = DigitalAsset::query()->create([
                'brand_id' => $brand->id, 'name' => $host, 'type' => 'website', 'status' => 'active',
                'module_id' => 'website', 'domain' => $host, 'primary_url' => $websiteItem['url'],
            ]);
            $results[] = ['key' => 'asset:website', 'label' => $websiteItem['label'], 'ok' => true, 'message' => 'Web sitesi varlığı oluşturuldu.'];
        }

        // 2) Bindings.
        foreach ($items as $item) {
            if (($item['kind'] ?? null) !== 'bind' || ! isset($selected[$item['key']]) || $item['status'] !== 'proposed') {
                continue;
            }
            $results[] = $this->bind($item, $brand, $website, $actor);
        }

        // 3) Services and sector.
        $services = $proposal->services ?? [];
        foreach ($serviceIndexes as $index) {
            $service = $services[$index] ?? null;
            if (! is_array($service) || $service['status'] !== 'proposed') {
                continue;
            }
            $results[] = $this->service($service, $brand, $actor);
        }
        $sectorCode = data_get($proposal->summary, 'sector_code');
        if (is_string($sectorCode) && $brand->sectors()->count() === 0) {
            $category = ServiceCategory::query()->where('code', $sectorCode)->first();
            if ($category !== null) {
                $brand->sectors()->syncWithoutDetaching([$category->id]);
                $results[] = ['key' => 'sector', 'label' => 'Sektör: '.$category->name, 'ok' => true, 'message' => 'Markanın sektörü atandı.'];
            }
        }

        // 4) Follow-ups: crawl the site when services are still waiting; queue a first SEO plan.
        if ($website !== null && $proposal->services_status === 'waiting_for_site') {
            try {
                app(AsyncOperationService::class)->queuePublicDiscovery($website, $actor);
                $results[] = ['key' => 'discovery', 'label' => 'Site taraması', 'ok' => true, 'message' => 'Site taraması kuyruğa alındı; bitince "Otomatik kur" hizmetleri önerebilir.'];
            } catch (Throwable $exception) {
                $results[] = ['key' => 'discovery', 'label' => 'Site taraması', 'ok' => false, 'message' => 'Site taraması başlatılamadı: '.$exception->getMessage()];
            }
        }
        if ($website !== null && collect($results)->contains(fn (array $r): bool => $r['ok'] && str_starts_with($r['key'], 'search_console:'))) {
            try {
                app(SeoPlanRunner::class)->queue($website->fresh(), $actor);
                $results[] = ['key' => 'seo_plan', 'label' => 'SEO planı', 'ok' => true, 'message' => 'İlk SEO planı kuyruğa alındı.'];
            } catch (Throwable) {
                // not critical
            }
        }

        $proposal->forceFill([
            'status' => BrandSetupProposal::STATUS_APPLIED,
            'apply_result' => $results,
            'applied_by' => $actor->id,
            'applied_at' => now(),
        ])->save();

        return $results;
    }

    /** @return array{key: string, label: string, ok: bool, message: string} */
    private function bind(array $item, $brand, ?DigitalAsset $website, User $actor): array
    {
        $result = ['key' => $item['key'], 'label' => $item['label'], 'ok' => false, 'message' => ''];
        $resource = CoreExternalResource::query()->find($item['resource_id']);
        if ($resource === null) {
            return array_merge($result, ['message' => 'Hesap artık listede yok; entegrasyonu yenileyin.']);
        }
        try {
            if ($item['target'] === 'website') {
                if ($website === null) {
                    return array_merge($result, ['message' => 'Önce web sitesi varlığı gerekiyor.']);
                }
                $this->google->bindExisting($website, $resource, $actor, allowReplace: false);

                return array_merge($result, ['ok' => true, 'message' => 'Web sitesine bağlandı.']);
            }

            $type = substr((string) $item['target'], strlen('new:'));
            $plan = new ResourceBindingPlan(
                $resource,
                $brand,
                ResourceBindingPlan::MODE_CREATE_ASSET,
                null,
                (self::ASSET_LABELS[$type] ?? $type).' · '.$resource->display_name,
                $actor,
            );
            $outcome = $resource->provider === 'meta' ? $this->meta->confirm($plan) : $this->google->confirm($plan);

            return array_merge($result, [
                'ok' => (bool) ($outcome['ok'] ?? false),
                'message' => (string) ($outcome['message'] ?? (($outcome['ok'] ?? false) ? 'Bağlandı.' : 'Bağlanamadı.')),
            ]);
        } catch (Throwable $exception) {
            return array_merge($result, ['message' => $exception->getMessage()]);
        }
    }

    /** @return array{key: string, label: string, ok: bool, message: string} */
    private function service(array $service, $brand, User $actor): array
    {
        $result = ['key' => 'service:'.$service['name'], 'label' => $service['name'], 'ok' => false, 'message' => ''];
        try {
            if ($service['is_new'] && is_string($service['sector_code'] ?? null)) {
                // New catalog entry under the suggested sector (existing names are found, never duplicated).
                $this->catalog->resolveOrCreate($service['name'], $service['sector_code'], actor: $actor);
            }
            $offering = $this->offerings->resolveOrCreate($brand, $service['name'], actor: $actor)['offering'];
            foreach ($service['aliases'] ?? [] as $alias) {
                try {
                    $this->offerings->addAlias($offering, (string) $alias, null, $actor);
                } catch (Throwable) {
                    // alias collisions are not fatal
                }
            }
            if (! empty($service['is_core'])) {
                $offering->forceFill(['is_priority' => true])->save();
            }

            return array_merge($result, ['ok' => true, 'message' => $service['is_new'] ? 'Katalogda yeni hizmet açıldı ve markaya eklendi.' : 'Katalogdaki hizmet markaya eklendi.']);
        } catch (Throwable $exception) {
            return array_merge($result, ['message' => $exception->getMessage()]);
        }
    }
}
