<?php

namespace App\Services\BrandSetup;

use App\Models\Brand;
use App\Models\BrandIntelligenceContext;
use App\Models\BrandSetupProposal;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\Integrations\ConfirmGoogleResourceBindingService;
use App\Services\Integrations\ConfirmMetaResourceBindingService;
use App\Services\SearchDemand\BrandQueryPortfolioService;
use App\Services\SearchDemand\SearchQueryLibraryService;
use App\Services\SearchDemand\ServiceCatalogService;
use App\Services\SearchDemand\ServiceKeywordService;
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
        private readonly SearchQueryLibraryService $library,
        private readonly BrandQueryPortfolioService $portfolio,
    ) {}

    /**
     * @param  list<string>  $itemKeys  selected asset/binding item keys
     * @param  list<int>  $serviceIndexes  selected service rows
     * @return list<array{key: string, label: string, ok: bool, message: string}>
     */
    public function apply(BrandSetupProposal $proposal, User $actor, array $itemKeys, array $serviceIndexes, bool $applyContext = true): array
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
        $keywordCount = 0;
        foreach ($serviceIndexes as $index) {
            $service = $services[$index] ?? null;
            if (! is_array($service) || ! in_array($service['status'], ['proposed', 'already'], true)) {
                continue;
            }
            $results[] = $this->service($service, $brand, $actor, $keywordCount);
        }
        if ($keywordCount > 0) {
            try {
                $inherited = $this->portfolio->inheritForBrand($brand, $actor);
                $results[] = ['key' => 'keywords', 'label' => 'Anahtar kelimeler', 'ok' => true, 'message' => sprintf('%d anahtar kelime sorgu kütüphanesine hizmetleriyle eklendi; markanın sorgu portföyüne %d yeni sorgu geçti.', $keywordCount, $inherited['created'])];
            } catch (Throwable $exception) {
                $results[] = ['key' => 'keywords', 'label' => 'Anahtar kelimeler', 'ok' => false, 'message' => 'Sorgular kütüphaneye eklendi ama marka portföyüne aktarılamadı: '.$exception->getMessage()];
            }
        }
        $sectorCode = data_get($proposal->summary, 'sector_code');
        if (is_string($sectorCode) && $brand->sectors()->count() === 0) {
            $category = ServiceCategory::query()->where('code', $sectorCode)->first();
            if ($category !== null) {
                $brand->sectors()->syncWithoutDetaching([$category->id]);
                $results[] = ['key' => 'sector', 'label' => 'Sektör: '.$category->name, 'ok' => true, 'message' => 'Markanın sektörü atandı.'];
            }
        }

        // 3b) İş bağlamı: fill the business context from the site, only fields the operator has not written.
        if ($applyContext && is_array($context = data_get($proposal->summary, 'business_context'))) {
            $results[] = $this->businessContext($brand, $context, $actor);
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
    private function service(array $service, $brand, User $actor, int &$keywordCount): array
    {
        $result = ['key' => 'service:'.$service['name'], 'label' => $service['name'], 'ok' => false, 'message' => ''];
        try {
            if ($service['status'] === 'proposed' && $service['is_new'] && is_string($service['sector_code'] ?? null)) {
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
            if ($service['status'] === 'proposed' && ! empty($service['is_core'])) {
                $offering->forceFill(['is_priority' => true])->save();
            }
            $offering = $offering->fresh();
            $matchingAdded = $this->storeMatchingPhrases($service, $offering);
            $keywordCount += $this->storeKeywords($service, $offering, $brand, $actor);

            $message = match (true) {
                $service['status'] === 'already' => 'Hizmet markada vardı.',
                $service['is_new'] => 'Katalogda yeni hizmet açıldı ve markaya eklendi.',
                default => 'Katalogdaki hizmet markaya eklendi.',
            };
            if ($matchingAdded > 0) {
                $message .= sprintf(' %d eşleştirme ifadesi eklendi.', $matchingAdded);
            }

            return array_merge($result, ['ok' => true, 'message' => $message]);
        } catch (Throwable $exception) {
            return array_merge($result, ['message' => $exception->getMessage()]);
        }
    }

    /** Append the approved matching expressions to the catalog service; operator-entered ones stay. */
    private function storeMatchingPhrases(array $service, $offering): int
    {
        $catalogItem = $offering?->service_catalog_item_id !== null ? ServiceCatalogItem::query()->find($offering->service_catalog_item_id) : null;
        if ($catalogItem === null) {
            return 0;
        }

        return count(app(ServiceKeywordService::class)->append($catalogItem, $service['matching_phrases'] ?? [$service['name']]));
    }

    /**
     * Location-free Search Console queries become the service's keywords in the shared query library
     * (sector → service → query), so brands elsewhere reuse them; locations come from each brand's
     * service areas at render time.
     */
    private function storeKeywords(array $service, $offering, $brand, User $actor): int
    {
        $catalogItem = $offering?->service_catalog_item_id !== null ? ServiceCatalogItem::query()->find($offering->service_catalog_item_id) : null;
        $sector = $catalogItem?->sector ?? ($service['sector_code'] ?? null) ?? $brand->sectorCodes()[0] ?? null;
        if ($catalogItem === null || ! is_string($sector)) {
            return 0;
        }
        $stored = 0;
        foreach ($service['keywords'] ?? [] as $keyword) {
            try {
                $this->library->store((string) $keyword['query'], 'search_console', [
                    'service_catalog_item_id' => $catalogItem->id,
                    'sector' => $sector,
                    'impressions' => $keyword['impressions'] ?? null,
                    'source_reference' => 'brand_setup:'.$brand->id,
                    'classification_source' => 'brand_setup',
                ], $actor);
                $stored++;
            } catch (Throwable) {
                // excluded or invalid queries are skipped
            }
        }

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $proposed
     * @return array{key: string, label: string, ok: bool, message: string}
     */
    private function businessContext(Brand $brand, array $proposed, User $actor): array
    {
        $context = BrandIntelligenceContext::query()->firstOrNew(['brand_id' => $brand->id]);
        $filled = [];
        foreach (['business_summary', 'business_model', 'positioning'] as $field) {
            if (trim((string) $context->{$field}) === '' && is_string($proposed[$field] ?? null) && trim($proposed[$field]) !== '') {
                $context->{$field} = trim($proposed[$field]);
                $filled[] = $field;
            }
        }
        foreach (['target_audiences', 'differentiators'] as $field) {
            if ((array) $context->{$field} === [] && is_array($proposed[$field] ?? null) && $proposed[$field] !== []) {
                $context->{$field} = array_values($proposed[$field]);
                $filled[] = $field;
            }
        }
        if ($filled === []) {
            return ['key' => 'context', 'label' => 'İş bağlamı', 'ok' => true, 'message' => 'İş bağlamı zaten doluydu; değiştirilmedi.'];
        }
        if (! $context->exists) {
            $context->source = BrandIntelligenceContext::SOURCE_PUBLIC_DISCOVERY;
            BrandIntelligenceContext::withLegacyIdentityProjection(function () use ($context): void {
                $context->business_goals = [];
                $context->conversion_goals = [];
                $context->priority_offerings = [];
                $context->save();
            });
        } else {
            $context->save();
        }
        $context->forceFill(['updated_by' => $actor->id])->save();

        return ['key' => 'context', 'label' => 'İş bağlamı', 'ok' => true, 'message' => sprintf('İş bağlamının %d alanı siteden dolduruldu (yazdığınız alanlara dokunulmadı).', count($filled))];
    }
}
