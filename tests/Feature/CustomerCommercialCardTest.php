<?php

namespace Tests\Feature;

use App\Enums\CustomerStatus;
use App\Livewire\Demo\Portfolio\CustomerDetail;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Portfolio\CustomerCommercialSummary;
use App\Services\Portfolio\CustomerHealthScore;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Customer card: fee and ad budgets, spend vs budget with month-end projection, health history. */
final class CustomerCommercialCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_budgets_are_saved_and_spend_is_projected_against_them(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $customer = Customer::factory()->create(['status' => CustomerStatus::Active]);
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $ads = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_ads', 'status' => 'active']);
        $resource = CoreExternalResource::factory()->create(['resource_type' => 'google_ads', 'external_id' => '1112223333']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $ads->id, 'external_resource_id' => $resource->id, 'capability' => 'google_ads']);
        foreach (range(1, 9) as $day) {
            DB::table('google_ads_campaign_daily')->insert([
                'digital_asset_id' => null, 'external_resource_id' => $resource->id, 'customer_id' => '1112223333', 'campaign_id' => 'c1',
                'reporting_date' => sprintf('2026-09-%02d', $day), 'cost_amount' => 100, 'cost_micros' => 100_000_000, 'clicks' => 10, 'impressions' => 100,
                'conversions' => 1, 'currency' => 'TRY', 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
                'record_fingerprint' => str_repeat((string) $day, 64), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Livewire::test(CustomerDetail::class, ['customerId' => (string) $customer->id])
            ->call('editCommercial')->set('commercial.monthly_fee', '15.000')->set('commercial.ad_budget_google', '2000')
            ->call('saveCommercial')->assertHasNoErrors();
        $this->assertSame(2000.0, (float) $customer->fresh()->ad_budget_google);
        $this->assertSame(15000.0, (float) $customer->fresh()->monthly_fee, 'Turkish thousands separator');

        $summary = app(CustomerCommercialSummary::class)->for($customer->fresh()->load('brands'), CarbonImmutable::parse('2026-09-10', 'Europe/Istanbul'));
        $google = collect($summary['channels'])->firstWhere('key', 'google');
        $this->assertSame(900.0, $google['spent']);
        $this->assertSame(3000.0, $google['projected'], '900 over 9 days × 30');
        $this->assertSame('over', $google['state']);

        app(CustomerHealthScore::class)->store($customer);
        $this->assertSame(1, DB::table('customer_health_history')->where('customer_id', $customer->id)->count());
    }
}
