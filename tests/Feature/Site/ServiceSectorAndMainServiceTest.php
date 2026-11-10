<?php

namespace Tests\Feature\Site;

use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandOffering;
use App\Models\ContentSiteState;
use App\Models\ServiceCategory;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Site\ContentTopics;

/**
 * moximu.com 2026-11-10 ("Moxdop moximu.com için içerik fikri yok"): its new clinic services were created without a
 * catalog sector, so they never got queries, clusters, a topic map or ideas; and the kept map stayed on a non-main
 * service. A brand service takes its brand's sector, and ★ main services' maps come first.
 */
final class ServiceSectorAndMainServiceTest extends SiteTestCase
{
    public function test_a_service_without_a_sector_takes_the_sector_of_the_brands_that_sell_it(): void
    {
        $catalog = app(ServiceCatalogService::class);
        $orphan = $catalog->resolveOrCreate('Klinik SEO', null)['service'];
        $this->assertNull($orphan->sector);
        BrandOffering::query()->create(['brand_id' => $this->brand->id, 'service_catalog_item_id' => $orphan->id, 'status' => 'active', 'priority' => 'main']);

        $this->assertSame(1, $catalog->adoptBrandSectors());
        $this->assertSame('dental', $orphan->fresh()->sector);
        $this->assertSame(0, $catalog->adoptBrandSectors(), 'nothing left to adopt');
    }

    public function test_a_service_sold_in_two_sectors_or_with_a_same_name_twin_keeps_no_sector(): void
    {
        $catalog = app(ServiceCatalogService::class);
        $shared = $catalog->resolveOrCreate('Ortak Hizmet', null)['service'];
        $other = ServiceCategory::query()->firstOrCreate(['code' => 'beauty'], ['name' => 'Güzellik', 'normalized_key' => 'guzellik']);
        $otherBrand = Brand::factory()->create(['customer_id' => $this->brand->customer_id, 'sector_id' => $other->id]);
        BrandOffering::query()->create(['brand_id' => $this->brand->id, 'service_catalog_item_id' => $shared->id, 'status' => 'active']);
        BrandOffering::query()->create(['brand_id' => $otherBrand->id, 'service_catalog_item_id' => $shared->id, 'status' => 'active']);
        $this->assertNull($catalog->adoptBrandSector($shared));
        $this->assertNull($shared->fresh()->sector);
    }

    public function test_an_offering_made_for_a_brand_whose_legacy_sector_code_is_empty_gets_the_brand_sector(): void
    {
        $this->brand->forceFill(['sector' => null])->saveQuietly(); // the legacy mirror column empty, sector_id set
        $offering = app(BrandOfferingService::class)->resolveOrCreate($this->brand->fresh(), 'Google İşletme Profili Yönetimi')['offering'];

        $this->assertSame('dental', $offering->catalogItem->fresh()->sector);
    }

    public function test_a_main_service_with_clusters_takes_the_place_of_a_kept_secondary_map(): void
    {
        $this->implantOffering->forceFill(['priority' => 'secondary', 'is_priority' => false])->save();
        foreach ([$this->cluster($this->implant, 'İmplant', ['implant']), $this->cluster($this->zirkonyum, 'Zirkonyum', ['zirkonyum'])] as $cluster) {
            BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id, 'state' => 'no_page']);
        }
        ContentSiteState::for($this->site)->forceFill(['active_service_ids' => [$this->implant->id]])->save();
        $topics = app(ContentTopics::class);
        $this->assertSame([$this->implant->id], $topics->activeServices($this->site, $this->brand), 'no main service waits: the kept map stays');

        $this->zirkonyumOffering->forceFill(['priority' => 'main'])->save(); // the brand's new direction: zirkonyum is ★
        $this->assertSame([$this->zirkonyum->id], $topics->activeServices($this->site, $this->brand));
    }
}
