<?php

namespace Tests\Feature\Portfolio;

use App\Jobs\Async\PublicDiscoveryJob;
use App\Jobs\BuildBrandSetupProposalJob;
use App\Livewire\Operator\Portfolio\DiscoverAndGroupPage;
use App\Models\Brand;
use App\Models\BrandSetupProposal;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Portfolio\PortfolioDiscoveryGrouper;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

final class DiscoverAndGroupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CoreIntegration $google;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret', 'moxdop.google.developer_token' => 'dev']);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->google = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $this->google->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'developer_token' => 'dev']]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $this->google->id, 'encrypted_payload' => ['access_token' => 'atok', 'refresh_token' => 'rtok'], 'expires_at' => now()->addHour()]);
        Http::preventStrayRequests();
        Bus::fake();
    }

    public function test_unbound_accounts_are_grouped_by_domain_then_by_name(): void
    {
        $this->resources();

        $groups = collect(app(PortfolioDiscoveryGrouper::class)->groups())->keyBy('key');

        $atlas = $groups['host:atlasdental.com'];
        $this->assertSame('Atlas Dental Kliniği', $atlas['suggested_brand'], 'Business Profile title names the brand');
        $this->assertEqualsCanonicalizing(
            ['search_console', 'ga4', 'google_business_profile', 'google_ads'],
            array_column($atlas['resources'], 'type'),
            'GA4 joins by web stream, Ads by name',
        );
        $this->assertArrayHasKey('name:yildizoptik', $groups->all(), 'accounts without a web address form name groups');
        $this->assertFalse($groups->flatMap(fn (array $g) => array_column($g['resources'], 'external_id'))->contains('999'), 'manager accounts are never offered');
        $this->assertFalse($groups->flatMap(fn (array $g) => array_column($g['resources'], 'external_id'))->contains('sc-domain:bagli.com'), 'bound accounts are not offered');
    }

    public function test_bulk_creates_only_groups_with_a_customer_and_adds_their_cities(): void
    {
        $this->resources();
        $customersBefore = Customer::query()->count();
        $page = Livewire::test(DiscoverAndGroupPage::class);
        $forms = collect($page->get('forms'));
        $this->assertTrue($forms->every(fn (array $form): bool => $form['customer_name'] === ''), 'customer names start blank');
        $atlas = $forms->search(fn (array $form): bool => $form['brand_name'] === 'Atlas Dental Kliniği');

        $page->set("forms.$atlas.customer_name", 'Atlas Sağlık A.Ş.')->set("forms.$atlas.cities", 'Manisa, İzmir')
            ->call('createAll')->assertHasNoErrors()->assertSee('1 grup oluşturuldu');

        $this->assertSame($customersBefore + 1, Customer::query()->count(), 'blank groups are skipped');
        $brand = Brand::query()->where('name', 'Atlas Dental Kliniği')->firstOrFail();
        $this->assertEqualsCanonicalizing(['Manisa', 'İzmir'], $brand->serviceAreas()->pluck('city_name')->all());
    }

    public function test_finished_site_crawl_queues_the_service_proposal_once(): void
    {
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $website = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'primary_url' => 'https://ornek.com.tr/', 'domain' => 'ornek.com.tr']);
        BrandSetupProposal::query()->create(['brand_id' => $brand->id, 'status' => 'applied', 'services_status' => 'waiting_for_site', 'items' => [], 'services' => []]);
        $propose = new \ReflectionMethod(PublicDiscoveryJob::class, 'proposeServices');

        $propose->invoke(new PublicDiscoveryJob(1), $website, $this->admin);
        $propose->invoke(new PublicDiscoveryJob(1), $website, $this->admin);

        $this->assertSame(2, BrandSetupProposal::query()->where('brand_id', $brand->id)->count());
        Bus::assertDispatched(BuildBrandSetupProposalJob::class, 1);
    }

    public function test_one_click_creates_customer_brand_assets_and_bindings(): void
    {
        $this->resources();
        $page = Livewire::test(DiscoverAndGroupPage::class)->assertSee('Atlas Dental Kliniği');
        $key = collect($page->get('forms'))->search(fn (array $form): bool => $form['brand_name'] === 'Atlas Dental Kliniği');

        $page->set("forms.$key.customer_name", 'Atlas Sağlık A.Ş.')->call('create', $key)->assertHasNoErrors();

        $customer = Customer::query()->where('name', 'Atlas Sağlık A.Ş.')->firstOrFail();
        $brand = Brand::query()->where('customer_id', $customer->id)->where('name', 'Atlas Dental Kliniği')->firstOrFail();
        $website = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->firstOrFail();
        $this->assertSame('https://atlasdental.com/', $website->primary_url);
        $this->assertEqualsCanonicalizing(['ga4', 'search_console'], CoreAssetBinding::query()->where('digital_asset_id', $website->id)->pluck('capability')->all());
        $this->assertTrue(DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'google_business_profile')->exists());
        $this->assertTrue(DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'google_ads')->exists());

        $page->assertSee('Atlas Dental Kliniği oluşturuldu');
        $this->assertNotContains('host:atlasdental.com', array_column(app(PortfolioDiscoveryGrouper::class)->groups(), 'key'), 'created group leaves the list');
    }

    public function test_group_matching_an_existing_website_binds_to_that_brand(): void
    {
        $this->resources();
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Atlas']);
        DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'primary_url' => 'https://www.atlasdental.com/', 'domain' => 'atlasdental.com']);

        $group = collect(app(PortfolioDiscoveryGrouper::class)->groups())->firstWhere('key', 'host:atlasdental.com');
        $this->assertSame($brand->id, $group['existing_brand_id']);

        $brandsBefore = Brand::query()->count();
        $page = Livewire::test(DiscoverAndGroupPage::class)->assertSee('Mevcut marka: Atlas');
        $key = collect($page->get('forms'))->search(fn (array $form): bool => $form['brand_name'] === 'Atlas');
        $page->call('create', $key)->assertHasNoErrors();

        $this->assertSame(1, DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->count(), 'no duplicate website');
        $this->assertSame($brandsBefore, Brand::query()->count(), 'no new brand');
        $this->assertTrue(CoreAssetBinding::query()->where('capability', 'search_console')->whereHas('digitalAsset', fn ($q) => $q->where('brand_id', $brand->id))->exists());
    }

    public function test_page_is_admin_only(): void
    {
        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);

        Livewire::test(DiscoverAndGroupPage::class)->assertForbidden();
    }

    private function resources(): void
    {
        $make = fn (string $type, string $externalId, string $name, array $meta = []) => CoreExternalResource::factory()->create([
            'integration_id' => $this->google->id, 'provider' => 'google', 'resource_type' => $type, 'external_id' => $externalId,
            'display_name' => $name, 'metadata' => $meta + ['selectable' => true], 'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $make('search_console', 'sc-domain:atlasdental.com', 'atlasdental.com', ['site_url' => 'sc-domain:atlasdental.com']);
        $make('ga4', 'properties/111', 'Web', ['property_id' => '111', 'web_stream_uris' => ['https://www.atlasdental.com'], 'web_streams_checked_at' => now()->toIso8601String()]);
        $make('google_business_profile', 'locations/1', 'Atlas Dental Kliniği', ['website_uri' => 'https://atlasdental.com/']);
        $make('google_ads', '1234567890', 'Atlas Dental Reklam', ['descriptive_name' => 'Atlas Dental Reklam', 'is_manager' => false]);
        $make('google_ads', '999', 'Ajans MCC', ['descriptive_name' => 'Ajans MCC', 'is_manager' => true]);
        $make('google_ads', '555', 'Yıldız Optik', ['descriptive_name' => 'Yıldız Optik', 'is_manager' => false]);
        $bound = $make('search_console', 'sc-domain:bagli.com', 'bagli.com', ['site_url' => 'sc-domain:bagli.com']);
        CoreAssetBinding::factory()->create(['external_resource_id' => $bound->id, 'capability' => 'search_console']);
    }
}
