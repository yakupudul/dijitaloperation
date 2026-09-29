<?php

namespace Tests\Feature\Integrations;

use App\Enums\CustomerStatus;
use App\Jobs\DiscoverProviderResourcesJob;
use App\Livewire\Operator\Portfolio\BrandShow;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Integrations\BrandAccountCandidates;
use App\Services\Integrations\Google\GoogleIntegrationReadModel;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Services\Measurement\BrandMeasurementScope;
use App\Services\Portfolio\CustomerCommercialSummary;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A brand with several ad accounts in one MCC / Meta Business: every account is one asset, every account is in the
 * brand's totals (with a per-account split), money is never added across currencies, and the brand's unbound accounts
 * are listed for one-click binding and raised in the Komuta merkezi.
 */
final class MultiAccountBrandTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private Brand $brand;

    private CoreIntegration $google;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->customer = Customer::factory()->create(['status' => CustomerStatus::Active, 'name' => 'Atlas Sağlık']);
        $this->brand = Brand::factory()->create(['customer_id' => $this->customer->id, 'name' => 'Atlas Dental']);
        $this->google = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
    }

    public function test_every_account_of_the_brand_is_counted_once_even_when_only_one_has_central_rows(): void
    {
        [$assetA, $resourceA] = $this->adsAccount('1110000001', 'Atlas Implant');
        [$assetB, $resourceB] = $this->adsAccount('1110000002', 'Atlas Ortodonti');
        // Account A: central row plus a legacy per-asset copy of the same day (must not double count).
        $this->adsRow(null, $resourceA->id, '2026-09-10', 300, 'TRY');
        $this->adsRow($assetA->id, null, '2026-09-10', 300, 'TRY');
        // Account B: only per-asset rows (older writer). The old brand-wide "central wins" rule dropped these.
        $this->adsRow($assetB->id, $resourceB->id, '2026-09-11', 200, 'TRY');

        $scope = BrandMeasurementScope::for($this->brand);
        $from = CarbonImmutable::parse('2026-09-01');
        $to = CarbonImmutable::parse('2026-09-30');
        $this->assertSame(500.0, (float) $scope->rows('google_ads_campaign_daily', $from, $to)->sum('cost_amount'));
        $this->assertSame(['TRY'], $scope->currencies('google_ads_campaign_daily', $from, $to));
        $this->assertEqualsCanonicalizing(['Atlas Implant' => 300.0, 'Atlas Ortodonti' => 200.0],
            collect($scope->perAccount('google_ads_campaign_daily', $from, $to, ['spend' => 'cost_amount']))->pluck('spend', 'name')->all());
    }

    public function test_spend_in_different_currencies_is_never_added_up(): void
    {
        [$assetA, $resourceA] = $this->adsAccount('1110000001', 'Atlas TR');
        [, $resourceB] = $this->adsAccount('1110000002', 'Atlas DE');
        $this->adsRow(null, $resourceA->id, '2026-09-10', 300, 'TRY');
        $this->adsRow(null, $resourceB->id, '2026-09-10', 50, 'EUR');

        $scope = BrandMeasurementScope::for($this->brand);
        $this->assertEqualsCanonicalizing(['TRY', 'EUR'], $scope->currencies('google_ads_campaign_daily', CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30')));

        $this->travelTo(Carbon::parse('2026-09-20 10:00:00'));
        $channel = collect(app(CustomerCommercialSummary::class)->for($this->customer->fresh('brands'), CarbonImmutable::parse('2026-09-20'))['channels'])->firstWhere('key', 'google');
        $this->assertSame('mixed_currency', $channel['state']);
        $this->assertNull($channel['spent']);
        $this->assertCount(2, $channel['accounts']);
    }

    public function test_budget_pacing_sums_every_account_of_every_brand(): void
    {
        [, $resourceA] = $this->adsAccount('1110000001', 'Atlas Implant');
        [, $resourceB] = $this->adsAccount('1110000002', 'Atlas Ortodonti');
        $this->adsRow(null, $resourceA->id, '2026-09-05', 1000, 'TRY');
        $this->adsRow(null, $resourceB->id, '2026-09-06', 500, 'TRY');
        $this->customer->update(['ad_budget_google' => 3000]);

        $channel = collect(app(CustomerCommercialSummary::class)->for($this->customer->fresh('brands'), CarbonImmutable::parse('2026-09-11'))['channels'])->firstWhere('key', 'google');
        $this->assertSame(1500.0, $channel['spent']);
        $this->assertSame('TRY', $channel['currency']);
        $this->assertSame(['Atlas Implant' => 1000.0, 'Atlas Ortodonti' => 500.0], collect($channel['accounts'])->pluck('spent', 'name')->all());
    }

    public function test_meta_accounts_of_the_brands_business_are_candidates(): void
    {
        $meta = CoreIntegration::factory()->meta()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $bound = $this->metaAccount($meta, 'act_1', 'Hesap 1', 'biz_atlas');
        $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'meta_ads', 'status' => 'active']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $bound->id, 'capability' => 'meta_ads', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $sibling = $this->metaAccount($meta, 'act_2', 'Hesap 2', 'biz_atlas');

        $candidate = collect(app(BrandAccountCandidates::class)->forBrand($this->brand))->firstWhere('resource_id', $sibling->id);
        $this->assertNotNull($candidate);
        $this->assertTrue($candidate['strong']);
        $this->assertSame('Business Atlas BM', $candidate['container_label']);
    }

    public function test_hesap_ekle_binds_a_listed_account_as_a_new_asset_and_refuses_others(): void
    {
        $this->manager('9990000001', 'Atlas MCC');
        $this->adsAccount('1110000001', 'Atlas Implant', manager: '9990000001');
        $sibling = $this->resource('1110000002', 'Atlas Ortodonti', manager: '9990000001');
        $foreign = $this->resource('4440000001', 'Alakasız Hesap', manager: null);

        $page = Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->call('setTab', 'assets')
            ->assertSee('Hesap ekle')
            ->assertSee('Atlas Ortodonti')
            ->assertDontSee('Alakasız Hesap');

        $page->call('addAccount', $foreign->id);
        $this->assertFalse(CoreAssetBinding::query()->where('external_resource_id', $foreign->id)->exists(), 'only listed accounts');

        $page->call('addAccount', $sibling->id);
        $binding = CoreAssetBinding::query()->where('external_resource_id', $sibling->id)->where('status', CoreAssetBinding::STATUS_ACTIVE)->sole();
        $this->assertSame((int) $this->brand->id, (int) $binding->digitalAsset->brand_id);
        $this->assertSame('google_ads', (string) $binding->digitalAsset->getRawOriginal('type'));
        $this->assertSame(2, DigitalAsset::query()->where('brand_id', $this->brand->id)->where('type', 'google_ads')->count(), 'one account = one asset');

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($viewer);
        Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])->call('addAccount', $foreign->id)->assertForbidden();
    }

    public function test_setup_status_walks_each_channel_to_its_next_step(): void
    {
        DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'status' => 'active', 'domain' => 'atlasdental.test', 'primary_url' => 'https://atlasdental.test/']);
        [$asset] = $this->adsAccount('1110000001', 'Atlas Implant');

        $page = Livewire::withQueryParams(['tab' => 'overview'])->test(BrandShow::class, ['brand' => (string) $this->brand->id])->assertSee('Kurulum durumu');
        $setup = $page->viewData('setup');
        $channels = collect($setup['channels'])->keyBy('key');

        $website = collect($channels['website']['steps'])->keyBy('key');
        $this->assertSame('done', $website['site']['state']);
        $this->assertSame('next', $website['google']['state'], 'Google is not connected yet');
        $this->assertSame('waiting', $website['search_console']['state']);

        $ads = collect($channels['google_ads']['steps'])->keyBy('key');
        $this->assertSame('done', $ads['bind']['state']);
        $this->assertStringContainsString('1 hesap bağlı', $ads['bind']['detail']);
        $this->assertFalse($channels['google_ads']['unused']);
        $this->assertTrue($channels['meta_ads']['unused'], 'no Meta account, no candidate → not counted');
        $this->assertSame(2, $setup['total']);

    }

    public function test_google_page_lists_every_discovered_account_with_its_mcc(): void
    {
        $this->manager('9990000001', 'Atlas MCC');
        foreach (range(1, 60) as $i) {
            $this->resource('11100'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'Hesap '.$i, manager: '9990000001');
        }

        $rows = collect(app(GoogleIntegrationReadModel::class)->detail()['unbound_resources'])->where('resource_type', 'google_ads');
        $this->assertCount(60, $rows, 'no cut at 50 accounts');
        $this->assertSame('Atlas MCC', $rows->first()['manager']);
    }

    public function test_connecting_google_starts_account_discovery_right_away(): void
    {
        Queue::fake();
        $this->mock(GoogleOAuthService::class, fn ($mock) => $mock->shouldReceive('handleCallback')->andReturn(['ok' => true]));

        $this->get(route('integrations.google.callback', ['code' => 'synthetic', 'state' => 'synthetic']))
            ->assertRedirect(route('operator.integrations.google'));

        Queue::assertPushed(DiscoverProviderResourcesJob::class, fn (DiscoverProviderResourcesJob $job): bool => $job->provider === ProviderRegistry::GOOGLE);
    }

    /** @return array{0: DigitalAsset, 1: CoreExternalResource} */
    private function adsAccount(string $customerId, string $name, ?string $manager = null, ?Brand $brand = null): array
    {
        $resource = $this->resource($customerId, $name, $manager);
        $asset = DigitalAsset::factory()->create(['brand_id' => ($brand ?? $this->brand)->id, 'type' => 'google_ads', 'name' => $name, 'status' => 'active']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => 'google_ads', 'status' => CoreAssetBinding::STATUS_ACTIVE]);

        return [$asset, $resource];
    }

    private function resource(string $customerId, string $name, ?string $manager): CoreExternalResource
    {
        return CoreExternalResource::factory()->create([
            'integration_id' => $this->google->id, 'provider' => ProviderRegistry::GOOGLE, 'resource_type' => 'google_ads',
            'external_id' => $customerId, 'display_name' => $name, 'parent_external_id' => $manager,
            'metadata' => array_filter(['customer_id' => $customerId, 'is_manager' => false, 'selectable' => true, 'currency_code' => 'TRY',
                'manager_customer_id' => $manager, 'login_customer_id' => $manager ?? $customerId], fn ($v): bool => $v !== null),
        ]);
    }

    private function manager(string $customerId, string $name): CoreExternalResource
    {
        return CoreExternalResource::factory()->create([
            'integration_id' => $this->google->id, 'provider' => ProviderRegistry::GOOGLE, 'resource_type' => 'google_ads',
            'external_id' => $customerId, 'display_name' => $name,
            'metadata' => ['customer_id' => $customerId, 'is_manager' => true, 'selectable' => false],
        ]);
    }

    private function metaAccount(CoreIntegration $meta, string $id, string $name, string $business): CoreExternalResource
    {
        return CoreExternalResource::factory()->create([
            'integration_id' => $meta->id, 'provider' => ProviderRegistry::META, 'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => $id, 'display_name' => $name, 'parent_external_id' => $business,
            'metadata' => ['currency' => 'TRY', 'business_id' => $business, 'business_name' => 'Atlas BM', 'selectable' => true, 'bindable' => true,
                'access_contexts' => [['business_id' => $business, 'business_name' => 'Atlas BM', 'edge' => 'owned_ad_accounts', 'access_lost' => false]]],
        ]);
    }

    private function adsRow(?int $assetId, ?int $resourceId, string $date, float $cost, string $currency): void
    {
        DB::table('google_ads_campaign_daily')->insert([
            'digital_asset_id' => $assetId, 'external_resource_id' => $resourceId, 'customer_id' => (string) ($resourceId ?? $assetId), 'reporting_date' => $date,
            'campaign_id' => 'c'.($resourceId ?? $assetId), 'impressions' => 1000, 'clicks' => 50, 'cost_micros' => (int) ($cost * 1_000_000), 'cost_amount' => $cost,
            'conversions' => 5, 'currency' => $currency, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', $date.$assetId.$resourceId.$cost), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
