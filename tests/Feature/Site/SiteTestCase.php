<?php

namespace Tests\Feature\Site;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\Cluster;
use App\Models\ClusterQuery;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Query;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Queries\QueryNormalizer;
use App\Services\Website\Pages\PageStore;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\InsertsFacts;
use Tests\TestCase;

/** Shared fixture of the website screen tests: Panorama Ankara (dental), its site, services, area and Search Console. */
abstract class SiteTestCase extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    protected User $admin;

    protected Brand $brand;

    protected DigitalAsset $site;

    protected ServiceCategory $dental;

    protected ServiceCatalogItem $implant;

    protected ServiceCatalogItem $zirkonyum;

    protected BrandOffering $implantOffering;

    protected BrandOffering $zirkonyumOffering;

    protected CoreExternalResource $gsc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->dental = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş sağlığı', 'normalized_key' => 'dis sagligi']);
        $catalog = app(ServiceCatalogService::class);
        $this->implant = $catalog->resolveOrCreate('Diş İmplantı', 'dental', actor: $this->admin)['service'];
        $this->zirkonyum = $catalog->resolveOrCreate('Zirkonyum Kaplama', 'dental', actor: $this->admin)['service'];
        $customer = Customer::factory()->create(['status' => CustomerStatus::Active]);
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Panorama Ankara', 'sector_id' => $this->dental->id]);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'name' => 'panorama.com.tr', 'domain' => 'panorama.com.tr', 'primary_url' => 'https://panorama.com.tr/']);
        $this->implantOffering = BrandOffering::query()->create(['brand_id' => $this->brand->id, 'service_catalog_item_id' => $this->implant->id, 'status' => 'active', 'priority' => 'main', 'locked' => true]);
        $this->zirkonyumOffering = BrandOffering::query()->create(['brand_id' => $this->brand->id, 'service_catalog_item_id' => $this->zirkonyum->id, 'status' => 'active', 'priority' => 'secondary', 'locked' => true]);
        BrandServiceArea::query()->create(['brand_id' => $this->brand->id, 'name' => 'Çankaya şubesi', 'country_code' => 'TR', 'city_name' => 'Ankara', 'district_name' => 'Çankaya', 'normalized_key' => 'tr|ankara|cankaya', 'status' => 'active', 'physical_branch' => true]);
        $this->gsc = CoreExternalResource::factory()->searchConsole()->create();
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->site->id, 'external_resource_id' => $this->gsc->id, 'capability' => 'search_console']);
        Http::preventStrayRequests();
    }

    protected function enableAi(): void
    {
        config(['moxdop.anthropic.api_key' => 'test-anthropic-value']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
    }

    /** @param  array<string, mixed>  $extra */
    protected function page(string $path, string $title, array $extra = []): Page
    {
        $url = 'https://panorama.com.tr'.$path;

        return Page::query()->create(array_merge([
            'website_asset_id' => $this->site->id, 'url' => $url, 'url_hash' => PageStore::urlHash($url), 'path' => $path,
            'title' => $title, 'h1' => $title, 'language' => 'tr', 'category' => null, 'is_indexable' => true,
            'content_text' => $title.' sayfası.', 'content_hash' => hash('sha256', $path.$title), 'word_count' => 400,
        ], $extra));
    }

    /**
     * An approved cluster of a service with its real queries (+ Search Console source links).
     *
     * @param  list<string>  $queries  first = main query
     * @param  list<string>  $subtopics
     */
    protected function cluster(ServiceCatalogItem $service, string $name, array $queries, array $subtopics = [], string $intent = 'commercial'): Cluster
    {
        $ids = [];
        foreach ($queries as $text) {
            $query = Query::query()->create(['text' => $text, 'text_hash' => QueryNormalizer::hash($text), 'sector_id' => $this->dental->id, 'service_id' => $service->id, 'assignment' => 'rule']);
            DB::table('query_sources')->insert(['external_resource_id' => $this->gsc->id, 'source' => 'gsc', 'raw_query' => $text, 'month' => '2026-09-01',
                'impressions' => 0, 'clicks' => 0, 'query_id' => $query->id, 'created_at' => now(), 'updated_at' => now()]);
            $ids[] = $query->id;
        }
        $cluster = Cluster::query()->create(['sector_id' => $this->dental->id, 'service_id' => $service->id, 'name' => $name, 'intent' => $intent,
            'main_query_id' => $ids[0], 'page_type' => 'service', 'subtopics' => $subtopics, 'approved' => true]);
        foreach ($ids as $id) {
            ClusterQuery::query()->create(['cluster_id' => $cluster->id, 'query_id' => $id]);
        }

        return $cluster;
    }

    /** One Search Console query × page fact (2026-09-20, inside the 28-day window). */
    protected function fact(string $query, string $path, int $impressions, int $clicks, float $position, string $date = '2026-09-20'): void
    {
        $this->insertFacts('gsc_query_page_daily', [
            'digital_asset_id' => null, 'external_resource_id' => $this->gsc->id, 'site_url' => 'sc-domain:panorama.com.tr', 'search_type' => 'web',
            'reporting_date' => $date, 'query' => $query, 'page' => 'https://panorama.com.tr'.$path, 'clicks' => $clicks, 'impressions' => $impressions,
            'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
            'metadata' => json_encode(['provider_average_position' => $position]),
        ]);
    }
}
