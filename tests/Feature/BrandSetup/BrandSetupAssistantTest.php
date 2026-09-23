<?php

namespace Tests\Feature\BrandSetup;

use App\Ai\Agents\BrandSetupAgent;
use App\Livewire\Operator\Portfolio\BrandSetupPage;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\BrandQueryPortfolioItem;
use App\Models\BrandServiceArea;
use App\Models\BrandSetupProposal;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\SearchQueryLibraryItem;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\BrandSetup\BrandSetupAssistant;
use App\Services\BrandSetup\BrandSetupMatcher;
use App\Services\SearchDemand\ServiceCatalogService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faz 1b: "Otomatik kur" — domain / GA4-stream / name matching, AI service proposal checked against the
 * catalog, and one-click application through the existing binding and offering services.
 */
final class BrandSetupAssistantTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private CoreIntegration $google;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->seed(RoleAndPermissionSeeder::class);
        config([
            'moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret', 'moxdop.google.developer_token' => 'dev',
            'moxdop-seo-tasks.llm.enabled' => false,
            'moxdop.anthropic.api_key' => 'sk-ant-test',
        ]);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);

        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Adadent']);
        $this->google = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $this->google->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'developer_token' => 'dev']]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $this->google->id, 'encrypted_payload' => ['access_token' => 'atok', 'refresh_token' => 'rtok'], 'expires_at' => now()->addHour()]);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);

        Http::preventStrayRequests();
        Http::fake([
            'analyticsadmin.googleapis.com/*properties/111/dataStreams*' => Http::response(['dataStreams' => [['webStreamData' => ['defaultUri' => 'https://www.adadent.com.tr']]]]),
            'analyticsadmin.googleapis.com/*' => Http::response(['dataStreams' => [['webStreamData' => ['defaultUri' => 'https://baska.com']]]]),
        ]);
    }

    public function test_matcher_proposes_domain_matches_ticked_and_name_matches_for_review(): void
    {
        $this->resources();

        $items = collect(app(BrandSetupMatcher::class)->propose($this->brand, 'https://www.adadent.com.tr/'))->keyBy('key');

        $this->assertSame('proposed', $items['asset:website']['status']);
        $gsc = $items->firstWhere('capability', 'search_console');
        $this->assertSame('sc-domain:adadent.com.tr', CoreExternalResource::query()->find($gsc['resource_id'])->external_id);
        $this->assertTrue($gsc['selected']);
        $ga4 = $items->firstWhere('capability', 'ga4');
        $this->assertSame('properties/111', CoreExternalResource::query()->find($ga4['resource_id'])->external_id, 'matched by web stream URL, not by name');
        $this->assertTrue($ga4['selected']);
        $this->assertSame(['https://www.adadent.com.tr'], CoreExternalResource::query()->find($ga4['resource_id'])->metadata['web_stream_uris'], 'stream lookup cached');
        $this->assertTrue($items->firstWhere('capability', 'google_business_profile')['selected']);

        $ads = $items->where('capability', 'google_ads');
        $this->assertCount(1, $ads, 'manager and unrelated accounts are not proposed');
        $this->assertTrue($ads->first()['selected'], 'account name contains the domain root');
        $this->assertSame(0, $items->where('capability', 'search_console')->where('selected', true)->count() - 1, 'only one Search Console property ticked');
    }

    public function test_full_flow_builds_proposal_and_one_click_approval_applies_it(): void
    {
        [$gscResource] = $this->resources();
        $category = ServiceCategory::query()->create(['code' => 'saglik', 'name' => 'Sağlık', 'normalized_key' => 'saglik']);
        app(ServiceCatalogService::class)->resolveOrCreate('İmplant Tedavisi', 'saglik', actor: $this->admin);
        DB::table('gsc_query_page_daily')->insert([
            'digital_asset_id' => null, 'external_resource_id' => $gscResource->id, 'site_url' => 'sc-domain:adadent.com.tr',
            'reporting_date' => now()->subDays(5)->toDateString(), 'query' => 'ankara implant', 'page' => 'https://www.adadent.com.tr/implant/',
            'clicks' => 3, 'impressions' => 400, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', 'x'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        BrandSetupAgent::fake([[
            'brand_summary' => 'Ankara Çankaya\'da implant ve gülüş tasarımı yapan diş kliniği.',
            'sector_code' => 'saglik',
            'services' => [
                ['name' => 'İmplant Tedavisi', 'catalog_name' => 'İmplant Tedavisi', 'sector_code' => 'saglik', 'aliases' => ['Diş İmplantı'], 'is_core' => true, 'evidence' => 'Sorgu: ankara implant'],
                ['name' => 'Gülüş Tasarımı', 'catalog_name' => null, 'sector_code' => 'saglik', 'aliases' => [], 'is_core' => false, 'evidence' => 'Sayfa başlığı'],
                ['name' => 'Uydurma', 'catalog_name' => 'Katalogda olmayan ad', 'sector_code' => 'yok', 'aliases' => [], 'is_core' => false, 'evidence' => '-'],
            ],
            'prompt_version' => BrandSetupAgent::PROMPT_VERSION,
        ]]);

        $page = Livewire::test(BrandSetupPage::class, ['brand' => (string) $this->brand->id])
            ->set('websiteUrl', 'adadent.com.tr')
            ->call('start');

        $proposal = BrandSetupProposal::query()->firstOrFail();
        $this->assertSame(BrandSetupProposal::STATUS_READY, $proposal->status, (string) $proposal->error_summary);
        $services = collect($proposal->services)->keyBy('name');
        $this->assertFalse($services['İmplant Tedavisi']['is_new']);
        $this->assertTrue($services['Gülüş Tasarımı']['is_new']);
        $this->assertTrue($services['Uydurma']['is_new'], 'unknown catalog name is not trusted');
        $this->assertFalse($services['Uydurma']['selected'], 'no valid sector → not ticked');

        $page->call('$refresh')->assertSee('Varlıklar ve hesap bağlantıları')->assertSee('Katalogda var')->call('approve');

        $proposal->refresh();
        $this->assertSame(BrandSetupProposal::STATUS_APPLIED, $proposal->status);
        $website = DigitalAsset::query()->where('brand_id', $this->brand->id)->where('type', 'website')->firstOrFail();
        $this->assertSame('https://adadent.com.tr/', $website->primary_url);
        $bound = CoreAssetBinding::query()->where('digital_asset_id', $website->id)->where('status', 'active')->pluck('capability')->sort()->values()->all();
        $this->assertSame(['ga4', 'search_console'], $bound);
        $this->assertTrue(DigitalAsset::query()->where('brand_id', $this->brand->id)->where('type', 'google_business_profile')->exists());
        $this->assertTrue(DigitalAsset::query()->where('brand_id', $this->brand->id)->where('type', 'google_ads')->exists());

        $offerings = BrandOffering::query()->with('primaryName')->where('brand_id', $this->brand->id)->get()->keyBy(fn ($o) => $o->primaryName?->raw_label);
        $this->assertTrue((bool) $offerings['İmplant Tedavisi']->is_priority);
        $this->assertArrayHasKey('Gülüş Tasarımı', $offerings->all());
        $this->assertSame('saglik', ServiceCatalogItem::query()->find($offerings['Gülüş Tasarımı']->service_catalog_item_id)->sector);
        $this->assertSame(1, ServiceCatalogItem::query()->whereHas('names', fn ($q) => $q->where('raw_label', 'İmplant Tedavisi'))->count(), 'catalog not duplicated');
        $this->assertSame([$category->id], $this->brand->sectors()->pluck('service_categories.id')->all());
        $this->assertTrue(collect($proposal->apply_result)->every(fn (array $r): bool => $r['ok']), json_encode($proposal->apply_result));
    }

    public function test_services_and_keywords_are_location_free_and_out_of_area_demand_is_reported(): void
    {
        [$gscResource] = $this->resources();
        ServiceCategory::query()->create(['code' => 'saglik', 'name' => 'Sağlık', 'normalized_key' => 'saglik']);
        BrandServiceArea::query()->create(['brand_id' => $this->brand->id, 'country_code' => 'TR', 'country_name' => 'Türkiye', 'city_name' => 'İstanbul', 'normalized_key' => 'tr|istanbul', 'status' => 'active', 'priority_rank' => 1]);
        foreach (['uyluk germe ankara' => 300, 'uyluk germe istanbul' => 120, 'uyluk germe fiyatları' => 80, 'adadent uyluk germe' => 40] as $query => $impressions) {
            DB::table('gsc_query_page_daily')->insert([
                'digital_asset_id' => null, 'external_resource_id' => $gscResource->id, 'site_url' => 'sc-domain:adadent.com.tr',
                'reporting_date' => now()->subDays(5)->toDateString(), 'query' => $query, 'page' => 'https://www.adadent.com.tr/uyluk-germe/',
                'clicks' => 1, 'impressions' => $impressions, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
                'record_fingerprint' => hash('sha256', $query), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        BrandSetupAgent::fake([[
            'brand_summary' => 'Estetik cerrahi kliniği.',
            'sector_code' => 'saglik',
            'services' => [
                ['name' => 'Uyluk Germe Ankara', 'catalog_name' => null, 'sector_code' => 'saglik', 'aliases' => ['uyluk germe ankara', 'thigh lift turkey'], 'matching_phrases' => ['uyluk germe', 'bacak germe', 'fiyat', 'thigh lift istanbul'], 'is_core' => true, 'evidence' => 'Sorgu: uyluk germe ankara'],
            ],
            'prompt_version' => BrandSetupAgent::PROMPT_VERSION,
        ]]);

        $proposal = app(BrandSetupAssistant::class)->queue($this->brand, 'adadent.com.tr', $this->admin)->fresh();

        $service = $proposal->services[0];
        $this->assertSame('Uyluk Germe', $service['name']);
        $this->assertSame(['thigh lift'], $service['aliases']);
        $this->assertSame(['uyluk germe', 'thigh lift', 'bacak germe'], $service['matching_phrases']);
        $this->assertSame(['uyluk germe', 'uyluk germe fiyatları'], array_column($service['keywords'], 'query'), 'location-free, merged, branded query dropped');
        $this->assertSame(420, $service['keywords'][0]['impressions']);
        $this->assertSame('Ankara', $proposal->summary['locations']['out_of_area'][0]['name']);
        $this->assertSame('İstanbul', $proposal->summary['locations']['in_area'][0]['name']);

        Livewire::test(BrandSetupPage::class, ['brand' => (string) $this->brand->id])
            ->assertSee('Hizmet bölgesi dışındaki aramalar')
            ->assertSee('2 anahtar kelime')
            ->call('approve');

        $item = ServiceCatalogItem::query()->whereHas('names', fn ($q) => $q->where('raw_label', 'Uyluk Germe'))->firstOrFail();
        $library = SearchQueryLibraryItem::query()->whereHas('services', fn ($q) => $q->whereKey($item->id))->pluck('canonical_text')->sort()->values()->all();
        $this->assertSame(['uyluk germe', 'uyluk germe fiyatları'], $library);
        $this->assertSame(2, BrandQueryPortfolioItem::query()->where('brand_id', $this->brand->id)->count());
        $this->assertSame(['bacak germe', 'thigh lift', 'uyluk germe'], $item->matchingKeywords()->orderBy('label')->pluck('label')->all(), 'eşleştirme ifadeleri: location-free, generic words dropped');
    }

    public function test_ai_failure_is_shown_to_the_operator_and_accounts_are_still_proposed(): void
    {
        $this->resources();
        DB::table('gsc_query_page_daily')->insert([
            'digital_asset_id' => null, 'external_resource_id' => CoreExternalResource::query()->where('resource_type', 'search_console')->value('id'), 'site_url' => 'sc-domain:adadent.com.tr',
            'reporting_date' => now()->subDays(5)->toDateString(), 'query' => 'ankara implant', 'page' => 'https://www.adadent.com.tr/implant/',
            'clicks' => 3, 'impressions' => 400, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', 'y'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        BrandSetupAgent::fake(fn () => throw new \RuntimeException('cURL error 28: Operation timed out'));

        $proposal = app(BrandSetupAssistant::class)->queue($this->brand, 'adadent.com.tr', $this->admin)->fresh();

        $this->assertSame(BrandSetupProposal::STATUS_READY, $proposal->status);
        $this->assertSame('ai_unavailable', $proposal->services_status);
        $this->assertSame('llm_error', $proposal->summary['ai_skipped_reason']);
        $this->assertNotEmpty($proposal->items);
        Livewire::test(BrandSetupPage::class, ['brand' => (string) $this->brand->id])
            ->assertSee('AI çağrısı hata verdi')
            ->assertSee('Operation timed out');
    }

    public function test_without_site_data_services_wait_and_nothing_is_applied_without_approval(): void
    {
        $proposal = app(BrandSetupAssistant::class)->queue($this->brand, 'yeni-marka.com', $this->admin)->fresh();

        $this->assertSame('waiting_for_site', $proposal->services_status);
        $this->assertSame([], $proposal->services);
        $this->assertSame(0, DigitalAsset::query()->count(), 'building a proposal writes nothing');
        $this->assertSame(0, CoreAssetBinding::query()->count());
    }

    /** @return list<CoreExternalResource> */
    private function resources(): array
    {
        $make = fn (string $type, string $externalId, string $name, array $meta = []) => CoreExternalResource::factory()->create([
            'integration_id' => $this->google->id, 'provider' => 'google', 'resource_type' => $type, 'external_id' => $externalId,
            'display_name' => $name, 'metadata' => $meta + ['selectable' => true], 'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $gsc = $make('search_console', 'sc-domain:adadent.com.tr', 'adadent.com.tr', ['site_url' => 'sc-domain:adadent.com.tr', 'property_form' => 'domain']);
        $make('search_console', 'https://baska.com/', 'baska.com', ['site_url' => 'https://baska.com/', 'property_form' => 'url_prefix']);
        $make('ga4', 'properties/222', 'Adadent eski mülk', ['property_id' => '222']);
        $make('ga4', 'properties/111', 'Web', ['property_id' => '111']);
        $make('google_business_profile', 'locations/1', 'Adadent Ağız ve Diş Sağlığı', ['website_uri' => 'https://adadent.com.tr/']);
        $make('google_ads', '1234567890', 'Adadent Diş Kliniği', ['descriptive_name' => 'Adadent Diş Kliniği', 'is_manager' => false]);
        $make('google_ads', '999', 'Moximu MCC', ['descriptive_name' => 'Adadent MCC', 'is_manager' => true]);
        $make('google_ads', '555', 'Başka Firma', ['descriptive_name' => 'Başka Firma', 'is_manager' => false]);

        return [$gsc];
    }
}
