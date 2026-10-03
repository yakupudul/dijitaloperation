<?php

namespace Tests\Feature\Portfolio;

use App\Enums\CustomerStatus;
use App\Enums\DigitalAssetStatus;
use App\Livewire\Demo\Portfolio\AssetsIndex;
use App\Livewire\Demo\Portfolio\BrandsIndex;
use App\Livewire\Demo\Portfolio\CustomerDetail;
use App\Livewire\Demo\Portfolio\CustomersIndex;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\DataStatus\DataStatusReader;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Services\Operator\PortfolioSignalsReader;
use App\Support\Demo\DemoMenu;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Müşteriler / Markalar / Dijital varlıklar list signals: open work = actionable suggestions (same as the brand Özet),
 * data status = the worst DataStatus source (same as the asset card), attention = data problem / reconnect / critical
 * suggestion with its reason; grouped queries, URL-bound asset filters, the active filter and the passive-switch guard.
 */
final class PortfolioSignalsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CoreIntegration $google;

    private Customer $alfa;

    private Brand $late;

    private Brand $calm;

    private DigitalAsset $site;

    private DigitalAsset $ads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-27 12:00:00'));
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true, 'name' => 'Ayla Yönetici']);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);

        $this->google = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $this->alfa = Customer::factory()->create(['name' => 'Alfa Sağlık', 'status' => CustomerStatus::Active]);

        // "Geciken Klinik": Search Console current, GA4 20 days late, Google Ads current.
        $this->late = Brand::factory()->create(['customer_id' => $this->alfa->id, 'name' => 'Geciken Klinik']);
        $this->site = $this->asset($this->late, 'website', 'Geciken Site', ['domain' => 'geciken.test']);
        $gsc = $this->bind($this->site, 'search_console', 'sc-domain:geciken.test');
        $ga4 = $this->bind($this->site, 'ga4', 'properties/1');
        $this->fact('gsc_property_daily', $gsc, '2026-09-25', ['site_url' => 'sc-domain:geciken.test', 'clicks' => 5, 'impressions' => 50, 'search_type' => 'web', 'metadata' => '{}']);
        $this->fact('ga4_property_daily', $ga4, '2026-09-07', ['property_id' => '1', 'sessions' => 4, 'engagedSessions' => 2]);
        $this->ads = $this->asset($this->late, 'google_ads', 'Geciken Ads');
        $adsResource = $this->bind($this->ads, 'google_ads', '1112223333');
        $this->adsFact($adsResource, '2026-09-26');

        // "Sakin Klinik": one current Google Ads account and only non-critical work.
        $this->calm = Brand::factory()->create(['customer_id' => Customer::factory()->create(['name' => 'Beta Grup'])->id, 'name' => 'Sakin Klinik']);
        $calmAds = $this->asset($this->calm, 'google_ads', 'Sakin Ads');
        $this->adsFact($this->bind($calmAds, 'google_ads', '4445556666'), '2026-09-26');
    }

    public function test_open_work_counts_actionable_suggestions_per_brand_channel_and_asset(): void
    {
        $page = Page::query()->create(['website_asset_id' => $this->site->id, 'url' => 'https://geciken.test/implant/', 'url_hash' => hash('sha256', 'implant'),
            'path' => '/implant/', 'title' => 'İmplant', 'category' => 'service', 'language' => 'tr', 'is_indexable' => true]);
        $this->suggestion($this->late, 'search', 's1', ['page_id' => $page->id]);
        $this->suggestion($this->late, 'search', 's2', ['status' => Suggestion::SNOOZED, 'snoozed_until' => now()->subDay()]);
        $this->suggestion($this->late, 'google_ads', 'a1', ['target_type' => 'google_ads', 'target_id' => $this->ads->id, 'priority' => 1]);
        $this->suggestion($this->late, 'google_ads', 'gone', ['status' => Suggestion::DISMISSED]);
        $this->suggestion($this->late, 'search', 'later', ['status' => Suggestion::SNOOZED, 'snoozed_until' => now()->addDay()]);

        $signals = app(PortfolioSignalsReader::class)->forBrands(Brand::query()->get());
        $brand = $signals['brands'][$this->late->id];

        $this->assertSame(3, $brand['open_work'], 'open + snoozed-past only; dismissed and still-snoozed are not open work');
        $this->assertEquals(['Arama' => 2, 'Google Ads' => 1], $brand['open_by_channel']);
        $this->assertSame(1, $brand['critical']);
        $this->assertSame(2, $signals['assets'][$this->site->id]['open_work'], 'page suggestion → its website; channel-only → first website');
        $this->assertSame(1, $signals['assets'][$this->ads->id]['open_work']);
        $this->assertSame(0, $signals['brands'][$this->calm->id]['open_work']);
    }

    public function test_attention_is_the_worst_source_like_the_asset_card_with_a_reason(): void
    {
        $signals = app(PortfolioSignalsReader::class)->forBrands(Brand::query()->get());

        $late = $signals['brands'][$this->late->id];
        $this->assertTrue($late['needs_attention']);
        $this->assertSame(1, $late['data_issues']);
        $this->assertStringContainsString('Google Analytics: Gecikmiş · 20 gün', (string) $late['reason']);
        $this->assertSame('warn', $late['channels']['ga4']['tone']);
        $this->assertSame('ok', $late['channels']['search_console']['tone']);
        $this->assertSame('none', $late['channels']['meta_ads']['tone'], 'no Meta asset → empty dot');

        $site = $signals['assets'][$this->site->id];
        $this->assertSame('warn', $site['worst_tone'], 'one late source makes the website late although Search Console is current');
        $this->assertSame('Google Analytics: Gecikmiş · 20 gün', $site['data_label']);

        $this->assertFalse($signals['brands'][$this->calm->id]['needs_attention']);
        $this->suggestion($this->calm, 'google_ads', 'crit', ['priority' => 1]);
        $calm = app(PortfolioSignalsReader::class)->forBrands(Brand::query()->whereKey($this->calm->id)->get())['brands'][$this->calm->id];
        $this->assertTrue($calm['needs_attention']);
        $this->assertSame('1 kritik öneri', $calm['reason']);
    }

    public function test_revoked_account_needs_a_reconnect(): void
    {
        $meta = CoreIntegration::factory()->meta()->create(['status' => CoreIntegration::STATUS_ACTIVE, 'config' => ['auth_status' => 'revoked']]);
        $account = $this->asset($this->calm, 'meta_ads', '2143683742659017');
        $resource = CoreExternalResource::factory()->create(['integration_id' => $meta->id, 'provider' => 'meta', 'resource_type' => 'meta_ads', 'external_id' => 'act_2143683742659017', 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $account->id, 'external_resource_id' => $resource->id, 'capability' => 'meta_ads', 'status' => CoreAssetBinding::STATUS_ACTIVE]);

        $brand = app(PortfolioSignalsReader::class)->forBrands(Brand::query()->whereKey($this->calm->id)->get())['brands'][$this->calm->id];

        $this->assertSame(1, $brand['reconnect']);
        $this->assertSame('Yeniden bağlanmalı: Meta Ads', $brand['reason']);
        $this->assertSame('bad', $brand['channels']['meta_ads']['tone']);
        $this->assertSame('Sakin Klinik · Meta reklam hesabı …9017', OperatorPortfolioPresenter::displayName($account->fresh('brand')));
        $this->assertSame('Sakin Ads', OperatorPortfolioPresenter::displayName($this->asset($this->calm, 'google_ads', 'Sakin Ads')));
        $this->assertSame('2143683742659017', $account->fresh()->name, 'the stored name is not changed');
    }

    public function test_customers_list_shows_open_work_and_attention_and_filters_on_it(): void
    {
        $this->suggestion($this->late, 'search', 's1');
        $this->suggestion($this->late, 'maps', 's2');

        Livewire::test(CustomersIndex::class)
            ->assertSee('Alfa Sağlık')
            ->assertSee('Google Analytics: Gecikmiş · 20 gün')
            ->assertSeeHtml('data-customers-summary>2 müşteri · 1 müşteri dikkat istiyor')
            ->assertDontSee('Açık bulgular')
            ->set('attention', 'needs_attention')
            ->assertSee('Alfa Sağlık')
            ->assertDontSee('Beta Grup')
            ->set('attention', 'clear')
            ->assertSee('Beta Grup')
            ->assertDontSee('Alfa Sağlık');

        $row = OperatorPortfolioPresenter::customer($this->alfa->fresh(), app(PortfolioSignalsReader::class)->forCustomers(Customer::query()->whereKey($this->alfa->id)->get())['customers'][$this->alfa->id]);
        $this->assertSame(2, $row['open_work']);
        $this->assertTrue($row['needs_attention']);
    }

    public function test_customer_sector_comes_from_the_brands_and_the_stored_value_is_kept(): void
    {
        $customer = Customer::factory()->create(['name' => 'Gama', 'industry' => 'healthcare']);
        $this->assertSame(['healthcare'], OperatorPortfolioPresenter::customer($customer)['sector_codes'], 'no sectored brand: the stored industry is the fallback');

        $this->get(route('operator.customer.edit', ['customerId' => $customer->id]))->assertOk()->assertSee('Markalardan gelir')->assertDontSee('wire:model.live="industry"', false);
        $this->assertSame('healthcare', $customer->fresh()->industry);
    }

    public function test_only_admin_or_a_responsible_user_can_switch_a_customer_passive(): void
    {
        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);

        Livewire::test(CustomersIndex::class)
            ->assertDontSeeHtml('data-toggle-active')
            ->call('toggleActive', (string) $this->alfa->id)
            ->assertForbidden();
        $this->assertSame(CustomerStatus::Active, $this->alfa->fresh()->status);

        $this->alfa->responsibleUsers()->attach($member->id);
        Livewire::test(CustomersIndex::class)
            ->assertSeeHtml('data-toggle-active')
            ->call('toggleActive', (string) $this->alfa->id);
        $this->assertSame(CustomerStatus::Inactive, $this->alfa->fresh()->status);
    }

    public function test_brands_list_shows_domain_channel_dots_open_work_and_reason(): void
    {
        $this->suggestion($this->late, 'search', 's1');

        Livewire::test(BrandsIndex::class)
            ->assertSee('geciken.test')
            ->assertSeeHtml('data-channel="ga4" data-tone="warn"')
            ->assertSee('Arama 1')
            ->assertSee('Google Analytics: Gecikmiş · 20 gün')
            ->assertSee(route('operator.customer', ['customerId' => $this->alfa->id]), false)
            ->set('search', 'geciken.test')
            ->assertSee('Geciken Klinik')
            ->assertDontSee('Sakin Klinik')
            ->assertSee('Arama: geciken.test')
            ->set('search', '')
            ->set('attention', 'clear')
            ->assertSee('Sakin Klinik')
            ->assertDontSee('Geciken Klinik');
    }

    public function test_assets_menu_url_filters_active_filter_and_inherited_owner(): void
    {
        $this->assertContains('operator.assets', collect(DemoMenu::groups())->flatMap(fn (array $g): array => $g['items'])->pluck('route')->all());
        $owner = User::factory()->create(['is_active' => true, 'name' => 'Deniz Sorumlu']);
        $this->late->responsibleUsers()->attach($owner->id);
        $this->asset($this->late, 'meta_ads', 'Pasif Meta', ['status' => DigitalAssetStatus::Inactive]);

        Livewire::withQueryParams(['brand' => (string) $this->late->id])->test(AssetsIndex::class)
            ->assertSet('filterBrand', (string) $this->late->id)
            ->assertSee('Geciken Site')
            ->assertDontSee('Sakin Ads')
            ->assertSee('Deniz Sorumlu')
            ->assertSee('Veri kaynakları')
            ->assertSee('Google Analytics: Gecikmiş · 20 gün')
            ->set('filterOperational', 'active')
            ->assertSee('Geciken Ads')
            ->assertDontSee('Pasif Meta')
            ->set('filterOperational', 'inactive')
            ->assertSee('Pasif Meta')
            ->assertDontSee('Inactive')
            ->assertDontSee('Geciken Ads');

        Livewire::test(AssetsIndex::class)->set('filterResponsible', (string) $owner->id)->assertSee('Geciken Ads')->assertDontSee('Sakin Ads');
        $this->get(route('operator.assets', ['brand' => $this->calm->id, 'quick' => 'data_issues']))->assertOk()->assertDontSee('Geciken Site');
    }

    public function test_customer_detail_rows_carry_channel_health_and_no_single_tab_strip(): void
    {
        $this->suggestion($this->late, 'google_ads', 'g1');
        $this->alfa->responsibleUsers()->attach($this->admin->id);

        Livewire::test(CustomerDetail::class, ['customerId' => (string) $this->alfa->id])
            ->assertDontSeeHtml('role="tablist"')
            ->assertSeeHtml('data-customer-brand="'.$this->late->id.'"')
            ->assertSeeHtml('data-channel="ga4" data-tone="warn"')
            ->assertSee('Google Ads 1')
            ->assertSee('1 marka dikkat istiyor')
            ->assertSee('Hesap sorumlusu')
            ->assertDontSee('bulgu · ');
    }

    public function test_lists_run_a_fixed_number_of_queries(): void
    {
        // The account activity lookup of DataStatusReader is read per bound source (its own interface); it is stubbed
        // here so the test measures the list code itself.
        $this->app->instance(DataStatusReader::class, new class extends DataStatusReader
        {
            public function activityFor(DigitalAsset $asset, string $capability, ?int $externalResourceId): ?string
            {
                return null;
            }
        });
        $count = function (string $component): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test($component);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        foreach ([CustomersIndex::class, BrandsIndex::class, AssetsIndex::class] as $warmUp) {
            $count($warmUp); // one-time lookups (permissions cache, schema checks, settings row) are not per-row
        }
        $before = ['customers' => $count(CustomersIndex::class), 'brands' => $count(BrandsIndex::class), 'assets' => $count(AssetsIndex::class)];

        foreach (range(1, 6) as $i) {
            $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Ek Marka '.$i]);
            $this->adsFact($this->bind($this->asset($brand, 'google_ads', 'Ek Ads '.$i), 'google_ads', 'ext-'.$i), '2026-09-26');
            $this->suggestion($brand, 'google_ads', 'x'.$i);
        }

        $this->assertSame($before['customers'], $count(CustomersIndex::class), 'customers list: no per-row queries');
        $this->assertSame($before['brands'], $count(BrandsIndex::class), 'brands list: no per-row queries');
        $this->assertSame($before['assets'], $count(AssetsIndex::class), 'assets list: no per-row queries');
    }

    /** @param array<string, mixed> $extra */
    private function asset(Brand $brand, string $type, string $name, array $extra = []): DigitalAsset
    {
        return DigitalAsset::factory()->create($extra + ['brand_id' => $brand->id, 'type' => $type, 'name' => $name, 'status' => DigitalAssetStatus::Active]);
    }

    private function bind(DigitalAsset $asset, string $capability, string $externalId): CoreExternalResource
    {
        $resource = CoreExternalResource::factory()->create(['integration_id' => $this->google->id, 'provider' => 'google', 'resource_type' => $capability,
            'external_id' => $externalId, 'display_name' => $externalId, 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => $capability, 'status' => CoreAssetBinding::STATUS_ACTIVE]);

        return $resource;
    }

    /** @param array<string, mixed> $values */
    private function fact(string $table, CoreExternalResource $resource, string $day, array $values): void
    {
        DB::table($table)->insert($values + [
            'external_resource_id' => $resource->id, 'reporting_date' => $day, 'digital_asset_id' => null, 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'source_timezone' => 'Europe/Istanbul',
            'record_fingerprint' => hash('sha256', $table.$resource->id.$day), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function adsFact(CoreExternalResource $resource, string $day): void
    {
        DB::table('google_ads_account_daily')->insert([
            'digital_asset_id' => null, 'external_resource_id' => $resource->id, 'customer_id' => '1112223333', 'reporting_date' => $day,
            'impressions' => 1000, 'clicks' => 50, 'cost_micros' => 1_000_000, 'cost_amount' => 1, 'conversions' => 1,
            'currency' => 'TRY', 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', $resource->id.$day), 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function suggestion(Brand $brand, string $channel, string $key, array $extra = []): Suggestion
    {
        return Suggestion::query()->create($extra + [
            'brand_id' => $brand->id, 'channel' => $channel, 'decision_key' => $key, 'fingerprint' => hash('sha256', $brand->id.$key),
            'material_hash' => 'm', 'title' => 'Öneri '.$key, 'reason' => 'r', 'priority' => 2, 'evidence' => [], 'action_type' => 'content',
            'status' => Suggestion::OPEN, 'first_seen_at' => now(), 'last_seen_at' => now(), 'action' => [],
        ]);
    }
}
