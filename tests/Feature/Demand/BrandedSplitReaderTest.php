<?php

namespace Tests\Feature\Demand;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Portfolio\BrandDemand;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Demand\BrandedQueryMatcher;
use App\Services\Demand\BrandedSplitReader;
use App\Support\Roles;
use Carbon\Carbon;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class BrandedSplitReaderTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private DigitalAsset $website;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-20 09:00:00', 'UTC'));
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Atlas Dental Kliniği']);
        $this->website = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'primary_url' => 'https://atlasdis.com/', 'domain' => 'atlasdis.com']);
    }

    public function test_matcher_uses_full_name_and_domain_root_only(): void
    {
        $matcher = BrandedQueryMatcher::for($this->brand);

        $this->assertTrue($matcher->isBranded('atlas dental kliniği yorumlar'));
        $this->assertTrue($matcher->isBranded('atlasdis randevu'));
        $this->assertFalse($matcher->isBranded('dental implant fiyatları'));
    }

    public function test_monthly_split_of_clicks_and_ads_cost(): void
    {
        $this->gsc('2026-09-05', 'atlasdis', 40);
        $this->gsc('2026-09-06', 'implant fiyatları', 60);
        $this->gsc('2026-07-10', 'implant fiyatları', 25);
        $this->gsc('2025-12-10', 'eski sorgu', 99); // outside six months
        $this->ads('2026-09-07', 'atlas dental kliniği', cost: 30, conversions: 2);
        $this->ads('2026-09-08', 'diş implantı', cost: 120, conversions: 3);

        $months = app(BrandedSplitReader::class)->monthly($this->brand);

        $this->assertCount(6, $months);
        $this->assertSame('2026-04', $months[0]['month']);
        $september = $months[5];
        $this->assertSame(['2026-09', 40, 60], [$september['month'], $september['gsc_branded'], $september['gsc_non_branded']]);
        $this->assertSame(30.0, $september['ads_cost_branded']);
        $this->assertSame(120.0, $september['ads_cost_non_branded']);
        $this->assertSame(3.0, $september['ads_conv_non_branded']);
        $this->assertSame(25, $months[3]['gsc_non_branded']);
    }

    public function test_split_is_shown_on_the_demand_section(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);
        $this->gsc('2026-09-06', 'implant fiyatları', 60);

        Livewire::test(BrandDemand::class, ['brandId' => $this->brand->id])
            ->assertSee('Markalı / markasız talep (aylık)')
            ->assertSee('%100');
    }

    private function gsc(string $date, string $query, int $clicks): void
    {
        DB::table('gsc_query_daily')->insert([
            'digital_asset_id' => $this->website->id, 'site_url' => 'sc-domain:atlasdis.com', 'reporting_date' => $date,
            'query' => $query, 'clicks' => $clicks, 'impressions' => $clicks * 10, 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
        ]);
    }

    private function ads(string $date, string $term, float $cost, float $conversions): void
    {
        DB::table('google_ads_search_term_daily')->insert([
            'digital_asset_id' => $this->website->id, 'customer_id' => '123', 'reporting_date' => $date,
            'search_term' => $term, 'impressions' => 50, 'clicks' => 5, 'cost_micros' => (int) ($cost * 1_000_000), 'conversions' => $conversions,
            'cost_amount' => $cost, 'currency' => 'TRY', 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => Str::random(20),
        ]);
    }
}
