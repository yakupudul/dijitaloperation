<?php

namespace Tests\Feature\GoogleAds;

use App\Enums\DigitalAssetStatus;
use App\Livewire\Operator\GoogleAds\OverviewPage;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\GoogleAds\GoogleAdsCampaignAnalyticsReadService;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Campaign period comparison, lost impression share weighting, monthly pacing and CSV exports — all from the local pool. */
final class GoogleAdsCampaignAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $asset;

    private CoreExternalResource $resource;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config(['moxdop.google.client_id' => 'test-client-id', 'moxdop.google.client_secret' => 'test-client-secret']);

        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $this->asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_ads', 'module_id' => 'google_ads', 'status' => DigitalAssetStatus::Active]);
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE, 'config' => ['granted_scopes' => [GoogleScopes::ADWORDS]]]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => GoogleScopes::ADWORDS],
            'expires_at' => now()->addHour(),
        ]);
        $this->resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => GoogleResourceType::GOOGLE_ADS_CUSTOMER,
            'external_id' => '1112223333', 'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['timezone' => 'Europe/Istanbul', 'currency' => 'TRY'],
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id, 'external_resource_id' => $this->resource->id,
            'capability' => GoogleAdsSpecialistBindingResolver::CAPABILITY, 'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    public function test_campaign_deltas_compare_with_previous_period_of_equal_length(): void
    {
        // Previous period 2026-09-01..02, current 2026-09-03..04.
        $this->campaignDay('2026-09-01', 'c1', cost: 50, clicks: 10, conversions: 2);
        $this->campaignDay('2026-09-02', 'c1', cost: 50, clicks: 10, conversions: 2);
        $this->campaignDay('2026-09-03', 'c1', cost: 90, clicks: 15, conversions: 2);
        $this->campaignDay('2026-09-04', 'c1', cost: 60, clicks: 15, conversions: 1);
        $this->campaignDay('2026-09-04', 'c2', cost: 30, clicks: 3, conversions: 0);

        $result = $this->service()->campaignComparison((string) $this->asset->id, '2026-09-03', '2026-09-04');

        $this->assertTrue($result['available']);
        $this->assertSame('2026-09-01', $result['previous_start']);
        $this->assertSame('2026-09-02', $result['previous_end']);

        $c1 = $result['rows']['c1'];
        $this->assertEqualsWithDelta(150.0, $c1['cost'], 0.001);
        $this->assertEqualsWithDelta(50.0, $c1['delta_cost'], 0.001);
        $this->assertEqualsWithDelta(50.0, $c1['delta_clicks'], 0.001);
        $this->assertEqualsWithDelta(-25.0, $c1['delta_conversions'], 0.001);
        // CPA 25 → 50: +100%.
        $this->assertEqualsWithDelta(50.0, $c1['cpa'], 0.001);
        $this->assertEqualsWithDelta(100.0, $c1['delta_cpa'], 0.001);

        // No previous-period row: no deltas, never a fabricated 0%.
        $c2 = $result['rows']['c2'];
        $this->assertNull($c2['delta_cost']);
        $this->assertNull($c2['cpa']);
        $this->assertNull($c2['delta_cpa']);
    }

    public function test_lost_impression_share_is_weighted_by_impressions_and_ignores_missing_days(): void
    {
        $this->campaignDay('2026-09-03', 'c1', impressions: 100, meta: ['search_budget_lost_impression_share' => '0.10', 'search_rank_lost_impression_share' => '0.60']);
        $this->campaignDay('2026-09-04', 'c1', impressions: 900, meta: ['search_budget_lost_impression_share' => '0.50', 'search_rank_lost_impression_share' => '0.20']);
        $this->campaignDay('2026-09-05', 'c1', impressions: 5000, meta: []);

        $row = $this->service()->campaignComparison((string) $this->asset->id, '2026-09-03', '2026-09-05')['rows']['c1'];

        // (0.10*100 + 0.50*900) / 1000 = 46% — a plain average would say 30%.
        $this->assertEqualsWithDelta(46.0, $row['lost_is_budget'], 0.001);
        // (0.60*100 + 0.20*900) / 1000 = 24%.
        $this->assertEqualsWithDelta(24.0, $row['lost_is_rank'], 0.001);
    }

    public function test_monthly_pacing_projects_month_end_spend_in_account_timezone(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-10 12:00:00', 'Europe/Istanbul'));

        $this->campaignSnapshot('c1', 'Over Kampanya', 'b1');
        $this->campaignSnapshot('c2', 'Under Kampanya', 'b2');
        $this->campaignSnapshot('c3', 'Bütçesiz', null);
        $this->budgetSnapshot('b1', 10.0);
        $this->budgetSnapshot('b2', 100.0);

        // Previous month data must not count toward MTD.
        $this->accountDay('2026-08-31', 999);
        foreach (range(1, 10) as $day) {
            $date = sprintf('2026-09-%02d', $day);
            $this->accountDay($date, 60);
            $this->campaignDay($date, 'c1', cost: 20);
            $this->campaignDay($date, 'c2', cost: 40);
        }

        $pacing = $this->service()->monthlyPacing((string) $this->asset->id);

        $this->assertTrue($pacing['available']);
        $this->assertSame('Europe/Istanbul', $pacing['timezone']);
        $this->assertSame('TRY', $pacing['currency']);
        $this->assertSame(30, $pacing['days_in_month']);
        $this->assertSame(10, $pacing['elapsed_days']);

        // Account: 600 MTD → 600/10*30 = 1800 vs (10+100)*30 = 3300 → 54.5% → under.
        $account = $pacing['account'];
        $this->assertEqualsWithDelta(600.0, $account['mtd_spend'], 0.001);
        $this->assertEqualsWithDelta(110.0, $account['daily_budget'], 0.001);
        $this->assertEqualsWithDelta(3300.0, $account['monthly_budget'], 0.001);
        $this->assertEqualsWithDelta(1800.0, $account['projected_spend'], 0.001);
        $this->assertEqualsWithDelta(54.5, $account['pace_percent'], 0.001);
        $this->assertSame('under', $account['status']);

        $campaigns = collect($pacing['campaigns'])->keyBy('id');
        // c1: 200 → 600 projected vs 300 budget → 200% → over.
        $this->assertEqualsWithDelta(600.0, $campaigns['c1']['projected_spend'], 0.001);
        $this->assertSame('over', $campaigns['c1']['status']);
        // c2: 400 → 1200 vs 3000 → 40% → under.
        $this->assertSame('under', $campaigns['c2']['status']);
        // c3: enabled but no budget snapshot → honest "no budget".
        $this->assertSame('no_budget', $campaigns['c3']['status']);
        $this->assertNull($campaigns['c3']['monthly_budget']);
    }

    public function test_pacing_row_band_is_plus_minus_ten_percent(): void
    {
        $service = $this->service();

        $this->assertSame('on_track', $service->pacingRow(109.0, 10.0, 10, 100)['status']);
        $this->assertSame('on_track', $service->pacingRow(91.0, 10.0, 10, 100)['status']);
        $this->assertSame('over', $service->pacingRow(111.0, 10.0, 10, 100)['status']);
        $this->assertSame('under', $service->pacingRow(89.0, 10.0, 10, 100)['status']);
        $this->assertSame('no_budget', $service->pacingRow(89.0, null, 10, 100)['status']);
    }

    public function test_pacing_reports_missing_budget_honestly_on_budget_tab(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-10 12:00:00', 'Europe/Istanbul'));
        $this->campaignSnapshot('c1', 'Implant Arama', null);
        $this->accountDay('2026-09-05', 100);

        $pacing = $this->service()->monthlyPacing((string) $this->asset->id);
        $this->assertSame('no_budget', $pacing['reason']);
        $this->assertSame('no_budget', $pacing['account']['status']);

        app()->setLocale('tr');
        $this->actingAsAdmin();
        Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => 'budget_bidding'])
            ->assertOk()
            ->assertSee('Aylık bütçe temposu')
            ->assertSee('Bütçe verisi yok');
    }

    public function test_campaigns_tab_shows_comparison_columns_and_sorts_by_cpa(): void
    {
        $this->campaignSnapshot('c1', 'Pahalı Kampanya', null);
        $this->campaignSnapshot('c2', 'Ucuz Kampanya', null);
        $this->campaignDay('2026-09-03', 'c1', cost: 300, clicks: 30, conversions: 3);
        $this->campaignDay('2026-09-03', 'c2', cost: 100, clicks: 10, conversions: 5);

        app()->setLocale('tr');
        $this->actingAsAdmin();
        Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => 'campaigns'])
            ->set('period', 'custom')
            ->set('periodStart', '2026-09-03')
            ->set('periodEnd', '2026-09-03')
            ->assertOk()
            ->assertSee('Kayıp IS (bütçe)')
            ->assertSee('Kayıp IS (sıralama)')
            ->call('sortCampaigns', 'cpa')
            ->assertSet('campaign_sort', 'cpa')
            ->assertSet('campaign_sort_dir', 'asc')
            ->assertSeeHtmlInOrder(['>Ucuz Kampanya</p>', '>Pahalı Kampanya</p>'])
            ->call('sortCampaigns', 'cpa')
            ->assertSet('campaign_sort_dir', 'desc')
            ->assertSeeHtmlInOrder(['>Pahalı Kampanya</p>', '>Ucuz Kampanya</p>'])
            ->call('sortCampaigns', 'bogus')
            ->assertSet('campaign_sort', 'cpa');
    }

    public function test_campaign_csv_export_has_bom_semicolons_and_comparison_columns(): void
    {
        $this->campaignSnapshot('c1', '=Formül Kampanya', null);
        $this->campaignDay('2026-09-01', 'c1', cost: 100, clicks: 10, conversions: 4);
        $this->campaignDay('2026-09-02', 'c1', cost: 150, clicks: 20, conversions: 3, meta: ['search_budget_lost_impression_share' => '0.125']);

        app()->setLocale('tr');
        $this->actingAsAdmin();
        $component = Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => 'campaigns'])
            ->set('period', 'custom')
            ->set('periodStart', '2026-09-02')
            ->set('periodEnd', '2026-09-02')
            ->call('exportCampaignsCsv');

        $csv = $this->downloadedContent($component, 'google-ads-kampanyalar-2026-09-02_2026-09-02.csv');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = preg_split('/\r?\n/', trim(substr($csv, 3)));
        $this->assertStringContainsString('Kampanya;ID;Durum;Tür;"Günlük bütçe";Harcama;"Harcama Δ%"', $lines[0]);
        $this->assertStringContainsString('"Bütçe nedeniyle kaybedilen gösterim payı";"Sıralama nedeniyle kaybedilen gösterim payı"', $lines[0]);
        // Formula-like names are neutralised; spend 150 vs 100 = +50%, CPA 50 vs 25 = +100%.
        $this->assertSame("\"'=Formül Kampanya\";c1;ENABLED;Search;;150,00;50,0;20;100,0;3,00;-25,0;50,00;100,0;12,5;", $lines[1]);
    }

    public function test_search_terms_csv_export_includes_all_filtered_terms(): void
    {
        $this->searchTermDay('2026-09-02', 'implant fiyatları', 120, 12, 2);
        $this->searchTermDay('2026-09-02', 'diş beyazlatma', 40, 4, 0);

        app()->setLocale('tr');
        $this->actingAsAdmin();
        $component = Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => 'search_demand'])
            ->set('period', 'custom')
            ->set('periodStart', '2026-09-02')
            ->set('periodEnd', '2026-09-02')
            ->set('search_query', 'implant')
            ->call('exportSearchTermsCsv');

        $csv = $this->downloadedContent($component, 'google-ads-arama-terimleri-2026-09-02_2026-09-02.csv');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('"Arama terimi";Kaynak;Kampanya', $csv);
        $this->assertStringContainsString('implant fiyatları', $csv);
        $this->assertStringContainsString('120,00', $csv);
        $this->assertStringNotContainsString('diş beyazlatma', $csv);
    }

    private function downloadedContent(mixed $component, string $filename): string
    {
        $component->assertFileDownloaded($filename);
        $download = data_get($component->effects, 'download');
        $this->assertIsArray($download);

        return base64_decode((string) $download['content']);
    }

    private function service(): GoogleAdsCampaignAnalyticsReadService
    {
        return app(GoogleAdsCampaignAnalyticsReadService::class);
    }

    private function actingAsAdmin(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);
    }

    /** @param array<string, mixed> $meta */
    private function campaignDay(string $date, string $campaignId, float $cost = 0, int $clicks = 0, float $conversions = 0, int $impressions = 1000, array $meta = []): void
    {
        DB::table('google_ads_campaign_daily')->insert($this->pooled([
            'reporting_date' => $date,
            'campaign_id' => $campaignId,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'cost_micros' => (int) ($cost * 1_000_000),
            'cost_amount' => $cost,
            'conversions' => $conversions,
            'currency' => 'TRY',
            'metadata' => json_encode($meta),
        ], 'campaign'.$date.$campaignId));
    }

    private function accountDay(string $date, float $cost): void
    {
        DB::table('google_ads_account_daily')->insert($this->pooled([
            'reporting_date' => $date,
            'impressions' => 1000,
            'clicks' => 50,
            'cost_micros' => (int) ($cost * 1_000_000),
            'cost_amount' => $cost,
            'conversions' => 0,
            'currency' => 'TRY',
        ], 'account'.$date));
    }

    private function campaignSnapshot(string $campaignId, string $name, ?string $budgetId): void
    {
        DB::table('google_ads_campaign_snapshot')->insert($this->pooled([
            'campaign_id' => $campaignId,
            'metadata' => json_encode(['name' => $name, 'status' => 'ENABLED', 'advertising_channel_type' => 'SEARCH', 'budget_id' => $budgetId]),
        ], 'snapshot'.$campaignId));
    }

    private function budgetSnapshot(string $budgetId, float $amount): void
    {
        DB::table('google_ads_campaign_budget_snapshot')->insert($this->pooled([
            'budget_id' => $budgetId,
            'metadata' => json_encode(['amount' => $amount, 'amount_micros' => (string) ($amount * 1_000_000), 'explicitly_shared' => false]),
        ], 'budget'.$budgetId));
    }

    private function searchTermDay(string $date, string $term, float $cost, int $clicks, float $conversions): void
    {
        DB::table('google_ads_search_term_daily')->insert($this->pooled([
            'reporting_date' => $date,
            'search_term' => $term,
            'impressions' => 500,
            'clicks' => $clicks,
            'cost_micros' => (int) ($cost * 1_000_000),
            'cost_amount' => $cost,
            'conversions' => $conversions,
            'currency' => 'TRY',
            'metadata' => json_encode(['source_view' => 'search_term_view']),
        ], 'term'.$date.$term));
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function pooled(array $values, string $fingerprint): array
    {
        return $values + [
            'digital_asset_id' => null,
            'external_resource_id' => $this->resource->id,
            'customer_id' => '1112223333',
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'Europe/Istanbul',
            'record_fingerprint' => hash('sha256', $fingerprint),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
