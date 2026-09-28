<?php

namespace Tests\Feature\Queries;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Integrations\DiscoveredAssetsPage;
use App\Livewire\Operator\Library\SearchQueryLibraryPage;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\QueryVariant;
use App\Models\SearchQueryLibraryItem;
use App\Models\User;
use App\Services\Ownership\OwnershipIntegrity;
use App\Services\Queries\QueryPipeline;
use App\Services\SearchDemand\ServiceCatalogService;
use App\Services\SearchDemand\ServiceKeywordService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Brain\InsertsFacts;
use Tests\TestCase;

/** Ownership invariants, grouping approval, integrity check and the two screens (Keşfedilen varlıklar, Sorgular). */
final class OwnershipAndScreensTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    private User $admin;

    private CoreIntegration $google;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->google = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $this->google->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'developer_token' => 'dev']]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $this->google->id, 'encrypted_payload' => ['access_token' => 'atok', 'refresh_token' => 'rtok'], 'expires_at' => now()->addHour()]);
        config(['moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret', 'moxdop.google.developer_token' => 'dev']);
        Http::preventStrayRequests();
        Bus::fake();
    }

    public function test_one_account_one_asset_is_enforced_by_the_database(): void
    {
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $gsc = $this->resource('search_console', 'sc-domain:atlasdis.com', 'atlasdis.com', ['site_url' => 'sc-domain:atlasdis.com']);
        $first = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'domain' => 'atlasdis.com', 'primary_url' => 'https://atlasdis.com/']);
        $second = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'domain' => 'atlas2.com', 'primary_url' => 'https://atlas2.com/']);
        $this->bind($first, $gsc);

        $this->expectException(QueryException::class);
        $this->bind($second, $gsc);
    }

    public function test_integrity_lists_violations_and_fixes_only_safe_ones(): void
    {
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Atlas']);
        $site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'domain' => 'atlasdis.com', 'primary_url' => 'https://atlasdis.com/']);
        $gsc = $this->resource('search_console', 'sc-domain:atlasdis.com', 'atlasdis.com');
        $ads = $this->resource('google_ads', '1112223333', 'Atlas Reklam');
        $binding = $this->bind($site, $gsc);
        $wrong = $this->bind($site, $ads, 'google_ads');
        DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_ads', 'name' => 'Ads varlığı']);
        $site->delete();

        $problems = collect(app(OwnershipIntegrity::class)->problems())->groupBy('code');

        $this->assertTrue($problems->has('binding_on_deleted_asset'));
        $this->assertTrue($problems->has('asset_without_account'));
        $this->assertSame(2, $problems['binding_on_deleted_asset']->count());

        $this->assertSame(2, app(OwnershipIntegrity::class)->fix($this->admin));
        $this->assertSame('disabled', $binding->fresh()->status);
        $this->assertSame('disabled', $wrong->fresh()->status);
        $this->assertTrue(DigitalAsset::query()->where('type', 'google_ads')->exists(), 'unsafe cases are only listed');
        $this->assertFalse(collect(app(OwnershipIntegrity::class)->problems())->contains('code', 'binding_on_deleted_asset'));
    }

    public function test_grouping_proposal_approval_creates_brand_under_customer_and_sector_is_editable(): void
    {
        $gsc = $this->resource('search_console', 'sc-domain:yenidis.com', 'yenidis.com', ['site_url' => 'sc-domain:yenidis.com']);
        $gbp = $this->resource('google_business_profile', 'locations/9', 'Yeni Diş Kliniği', ['website_uri' => 'https://yenidis.com/']);
        $customer = Customer::factory()->create(['name' => 'Yeni Sağlık A.Ş.', 'status' => CustomerStatus::Active]);
        $dental = (int) DB::table('service_categories')->where('code', 'dental')->value('id');

        $page = Livewire::test(DiscoveredAssetsPage::class)
            ->assertSee('Keşfedilen varlıklar')->assertSee('Yeni Diş Kliniği olabilir')->assertSee('2 markasız');
        $page->set('customerFor.g'.substr(md5('host:yenidis.com'), 0, 12), (string) $customer->id)
            ->call('approve', 'host:yenidis.com')->assertSee('Yeni Diş Kliniği hazır');

        $brand = Brand::query()->where('name', 'Yeni Diş Kliniği')->sole();
        $this->assertSame($customer->id, (int) $brand->customer_id, 'one brand ↔ exactly one (chosen) customer');
        $website = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->sole();
        $this->assertSame($website->id, (int) CoreAssetBinding::query()->where('external_resource_id', $gsc->id)->where('status', 'active')->value('digital_asset_id'));
        $this->assertSame(1, CoreAssetBinding::query()->where('external_resource_id', $gbp->id)->where('status', 'active')->count());

        $page->call('setSector', 'resource:'.$gsc->id, (string) $dental)->assertSee('Sektör kaydedildi');
        $this->assertSame('manual', DB::table('asset_sectors')->where('subject_type', 'resource')->where('subject_id', $gsc->id)->value('method'));
        $page->set('sector', (string) $dental)->assertSee('yenidis.com');

        $team = User::factory()->create(['is_active' => true]);
        $team->assignRole(Roles::TEAM_MEMBER);
        Livewire::actingAs($team)->test(DiscoveredAssetsPage::class)->call('setSector', 'resource:'.$gsc->id, '')->assertForbidden();
    }

    public function test_queries_screen_filters_bulk_reassign_tabs_and_products(): void
    {
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Atlas Diş']);
        $dental = (int) DB::table('service_categories')->where('code', 'dental')->value('id');
        $brand->sectors()->attach($dental);
        $site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'domain' => 'atlasdis.com', 'primary_url' => 'https://atlasdis.com/']);
        $gsc = $this->resource('search_console', 'sc-domain:atlasdis.com', 'atlasdis.com', ['site_url' => 'sc-domain:atlasdis.com']);
        $this->bind($site, $gsc);
        $implant = app(ServiceCatalogService::class)->resolveOrCreate('İmplant Tedavisi', 'dental', actor: $this->admin)['service'];
        $whitening = app(ServiceCatalogService::class)->resolveOrCreate('Diş Beyazlatma', 'dental', actor: $this->admin)['service'];
        app(ServiceKeywordService::class)->append($implant, ['implant']);
        DB::table('search_demand_competitors')->insert(['uuid' => (string) Str::uuid(), 'brand_id' => $brand->id, 'display_name' => 'Rakip Klinik', 'normalized_domain' => 'rakipklinik.com',
            'normalized_domain_hash' => hash('sha256', 'rakipklinik.com'), 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('query_exclusion_rules')->insert(['label' => 'bedava', 'normalized' => 'bedava', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['çankaya implant fiyatı' => 100, 'diş sararması' => 40, 'rakip klinik implant' => 30, 'bedava diş muayenesi' => 10] as $text => $impressions) {
            $this->insertFacts('gsc_query_daily', [
                'digital_asset_id' => $site->id, 'external_resource_id' => $gsc->id, 'site_url' => 'sc-domain:atlasdis.com', 'reporting_date' => now()->subDays(3)->toDateString(),
                'query' => $text, 'clicks' => 1, 'impressions' => $impressions, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
                'record_fingerprint' => Str::random(20), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        app(QueryPipeline::class)->daily();
        $sararma = SearchQueryLibraryItem::query()->where('canonical_text', 'diş sararması')->sole();

        $page = Livewire::test(SearchQueryLibraryPage::class)
            ->assertSee('implant fiyatı')->assertSee('İmplant Tedavisi')->assertDontSee('rakip klinik implant')->assertDontSee('bedava diş muayenesi')
            ->set('service', 'none')->assertSee('diş sararması')->assertDontSee('implant fiyatı')
            ->set('selected', [(string) $sararma->id])->set('targetService', (string) $whitening->id)->call('assignSelected')->assertSee('1 sorgu taşındı');
        $this->assertSame([$whitening->id], $sararma->services()->pluck('service_catalog_items.id')->all());
        $page->set('service', (string) $whitening->id)->assertSee('diş sararması')
            ->set('service', '')->set('source', 'search_console')->call('showVariants', SearchQueryLibraryItem::query()->where('canonical_text', 'implant fiyatı')->value('id'))
            ->assertSee('çankaya implant fiyatı');

        $page->set('source', '')->set('tab', 'competitor')->assertSee('rakip klinik implant');
        $page->set('tab', 'irrelevant')->assertSee('bedava diş muayenesi');
        $this->assertSame('competitor', QueryVariant::query()->where('raw_text', 'rakip klinik implant')->value('kind'));

        $page->set('tab', 'products')->set('productSector', (string) $dental)->assertSet('productList', fn (string $list): bool => str_contains($list, 'Straumann'))
            ->set('productList', "Straumann\nMegagen")->call('saveProducts')->assertSee('2 ürün markası');
        $this->assertSame(2, DB::table('sector_product_brands')->where('service_category_id', $dental)->count());

        $this->get(route('operator.library.search-queries'))->assertOk()->assertSee('Sorgular')->assertSee('Rakip marka');
        $this->get(route('operator.integrations.discovered'))->assertOk()->assertSee('Keşfedilen varlıklar');
    }

    /** @param  array<string, mixed>  $meta */
    private function resource(string $type, string $externalId, string $name, array $meta = []): CoreExternalResource
    {
        return CoreExternalResource::factory()->create([
            'integration_id' => $this->google->id, 'provider' => 'google', 'resource_type' => $type, 'external_id' => $externalId,
            'display_name' => $name, 'metadata' => $meta + ['selectable' => true], 'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
    }

    private function bind(DigitalAsset $asset, CoreExternalResource $resource, ?string $capability = null): CoreAssetBinding
    {
        return CoreAssetBinding::query()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id,
            'capability' => $capability ?? $resource->resource_type, 'status' => 'active', 'configuration' => []]);
    }
}
