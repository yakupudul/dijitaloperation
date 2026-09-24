<?php

namespace Tests\Feature\Portfolio;

use App\Enums\CustomerStatus;
use App\Livewire\Demo\Portfolio\CustomersIndex;
use App\Models\AssetAlert;
use App\Models\AssetRenewal;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Services\Assistant\TodayReader;
use App\Services\Portfolio\CustomerHealthScore;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Support\Roles;
use Carbon\Carbon;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faz 10b: customer health score — results trend, contact silence, renewals and alerts, with reasons.
 */
final class CustomerHealthScoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_score_reasons_bands_and_where_it_shows(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        Http::fake();

        $risky = Customer::factory()->create(['name' => 'Riskli Klinik', 'status' => CustomerStatus::Active]);
        $healthy = Customer::factory()->create(['name' => 'Sağlıklı Klinik', 'status' => CustomerStatus::Active]);
        $passive = Customer::factory()->create(['name' => 'Pasif', 'status' => CustomerStatus::Inactive]);
        $brand = Brand::factory()->create(['customer_id' => $risky->id, 'name' => 'Riskli']);
        $site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website']);
        // Search clicks: 200 in the 28 days before, 80 in the last 28 days → −60 %.
        foreach (['2026-08-20' => 200, '2026-09-20' => 80] as $date => $clicks) {
            DB::table('gsc_property_daily')->insert(['digital_asset_id' => $site->id, 'site_url' => 'x', 'reporting_date' => $date, 'clicks' => $clicks, 'impressions' => 1000,
                'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', $date), 'created_at' => now(), 'updated_at' => now()]);
        }
        AssetRenewal::query()->create(['brand_id' => $brand->id, 'digital_asset_id' => $site->id, 'kind' => 'domain', 'label' => 'riskli.com', 'expires_on' => '2026-10-12', 'expires_source' => 'manual', 'auto_renew' => false]);
        AssetAlert::query()->create(['digital_asset_id' => $site->id, 'brand_id' => $brand->id, 'alert_key' => 'x', 'kind' => 'site_down', 'severity' => 'critical',
            'title' => 'Site kapalı', 'message' => 'm', 'first_detected_at' => now(), 'last_detected_at' => now()]);
        app(WhatsAppConnection::class)->save($admin, [
            'waba_id' => '111111111', 'phone_number_id' => '222222222', 'business_phone' => '905551112233', 'business_context' => 'Ajans',
            'access_token' => 'test-access-token', 'app_secret' => 'test-app-secret', 'verify_token' => 'test-verify-token-12345',
            'enabled' => true, 'automatic_suggestions' => false,
        ]);
        WhatsAppConversation::query()->create(['integration_id' => (int) app(WhatsAppConnection::class)->integration()->id, 'phone_number_id' => '222222222', 'contact_id' => '905321112233',
            'contact_name' => 'Riskli', 'last_message_at' => now()->subDays(40), 'customer_id' => $risky->id, 'link_source' => 'operator']);

        $service = app(CustomerHealthScore::class);
        $result = $service->compute($risky);
        $this->assertSame(50, $result['score'], json_encode($result['reasons'], JSON_UNESCAPED_UNICODE));
        $this->assertSame('risk', $result['band']);
        $this->assertSame([15, 15, 10, 10], array_column($result['reasons'], 'points'));
        $this->assertTrue(collect($result['reasons'])->contains(fn (array $r): bool => str_contains($r['text'], 'Google arama tıklaması %60 düştü')));
        $this->assertSame(['score' => 100, 'band' => 'good', 'reasons' => []], $service->compute($healthy));

        DB::table('customer_health')->insert(['customer_id' => $passive->id, 'score' => 10, 'band' => 'risk', 'reasons' => '[]', 'computed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(['computed' => 2], $service->recomputeAll());
        $this->assertFalse(DB::table('customer_health')->where('customer_id', $passive->id)->exists(), 'passive customers are not scored');

        $contact = collect(app(TodayReader::class)->read($admin)['contact']);
        $this->assertStringContainsString('Sağlık puanı 50', (string) $contact->firstWhere('customer_id', $risky->id)['reason']);

        Livewire::test(CustomersIndex::class)->assertSee('Riskli Klinik')->assertSeeHtml('bg-rose-50 text-rose-700');
    }
}
