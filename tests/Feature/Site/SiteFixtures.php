<?php

namespace Tests\Feature\Site;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\Cluster;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Query;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Queries\QueryNormalizer;
use App\Services\Website\PageFetcher;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;

/** Panorama-like brand: dental sector, implant service, Çankaya / Ankara branch, website panorama.example. */
trait SiteFixtures
{
    private User $admin;

    private ServiceCategory $dental;

    private ServiceCatalogItem $implant;

    private Customer $customer;

    private Brand $brand;

    private DigitalAsset $site;

    private function setUpSite(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->dental = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş sağlığı', 'normalized_key' => 'dis sagligi']);
        $this->implant = app(ServiceCatalogService::class)->resolveOrCreate('Diş İmplantı', 'dental', actor: $this->admin)['service'];
        $this->customer = Customer::factory()->create(['status' => CustomerStatus::Active]);
        $this->brand = Brand::factory()->create(['customer_id' => $this->customer->id, 'name' => 'Panorama Ankara', 'sector_id' => $this->dental->id]);
        $this->site = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id, 'type' => 'website', 'status' => 'active', 'module_id' => 'website',
            'name' => 'Panorama Site', 'domain' => 'panorama.example', 'primary_url' => 'https://www.panorama.example/', 'languages' => ['tr'],
        ]);
        BrandOffering::query()->create(['brand_id' => $this->brand->id, 'service_catalog_item_id' => $this->implant->id, 'status' => 'active', 'priority' => 'main']);
        BrandServiceArea::query()->create(['brand_id' => $this->brand->id, 'name' => 'Çankaya şubesi', 'country_code' => 'TR', 'city_name' => 'Ankara',
            'district_name' => 'Çankaya', 'physical_branch' => true, 'normalized_key' => 'tr|ankara|cankaya', 'status' => 'active', 'priority_rank' => 1]);
    }

    private function cluster(string $name, string $mainQuery, string $intent, bool $approved = true): Cluster
    {
        $query = Query::query()->create(['text' => $mainQuery, 'text_hash' => QueryNormalizer::hash($mainQuery), 'sector_id' => $this->dental->id, 'service_id' => $this->implant->id]);

        return Cluster::query()->create([
            'sector_id' => $this->dental->id, 'service_id' => $this->implant->id, 'name' => $name, 'intent' => $intent,
            'main_query_id' => $query->id, 'page_type' => $intent === 'informational' ? 'guide' : 'service', 'approved' => $approved,
        ]);
    }

    private function enableAi(): void
    {
        config(['moxdop.anthropic.api_key' => 'test-anthropic-value']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
    }

    /** @param  array<string, ?string>  $pages  url => html (null = unreachable) */
    private function fakePages(array $pages): void
    {
        $this->app->instance(PageFetcher::class, new class($pages) extends PageFetcher
        {
            /** @var list<string> */
            public array $fetched = [];

            /** @param  array<string, ?string>  $pages */
            public function __construct(private array $pages) {}

            public function fetch(string $url): array
            {
                $this->fetched[] = $url;
                $html = $this->pages[$url] ?? null;

                return ['status_code' => $html === null ? 404 : 200, 'html' => $html, 'final_url' => $url, 'error' => $html === null ? 'http_404' : null];
            }
        });
    }
}
