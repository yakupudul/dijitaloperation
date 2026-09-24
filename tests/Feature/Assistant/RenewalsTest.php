<?php

namespace Tests\Feature\Assistant;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Assistant\RenewalsPage;
use App\Models\AgencySetting;
use App\Models\AssetAlert;
use App\Models\AssetRenewal;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Alerts\AssetAlertScanner;
use App\Services\Assistant\RenewalService;
use App\Support\Roles;
use Carbon\Carbon;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class RenewalsTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private DigitalAsset $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-01 08:00:00', 'UTC'));
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Atlas Dental']);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'name' => 'Atlas Site', 'primary_url' => 'https://www.atlasdis.com.tr/', 'domain' => 'www.atlasdis.com.tr']);
        AgencySetting::query()->create(['agency_name' => 'Moximu', 'push_ntfy_url' => 'https://ntfy.sh/t', 'push_min_severity' => 'medium']);
        Http::fake([
            'rdap.org/domain/atlasdis.com.tr' => Http::response(['events' => [['eventAction' => 'registration', 'eventDate' => '2019-10-10T00:00:00Z'], ['eventAction' => 'expiration', 'eventDate' => '2026-10-10T00:00:00Z']],
                'entities' => [['roles' => ['registrar'], 'vcardArray' => ['vcard', [['version', [], 'text', '4.0'], ['fn', [], 'text', 'Örnek Registrar A.Ş.']]]]]]),
            'ntfy.sh/*' => Http::response('{}'),
        ]);
        DB::table('website_infra_snapshot')->insert([
            'digital_asset_id' => $this->site->id, 'asset_id' => (string) $this->site->id, 'observed_at' => now(),
            'metadata' => json_encode(['tls' => ['valid_to' => '2026-12-20T00:00:00Z', 'issuer_common_name' => 'R11']]),
            'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
        ]);
    }

    public function test_domain_and_ssl_dates_fill_themselves_and_due_renewal_alerts_and_pushes(): void
    {
        $stats = app(RenewalService::class)->daily();

        $domain = AssetRenewal::query()->where('kind', 'domain')->sole();
        $this->assertSame('atlasdis.com.tr', $domain->label);
        $this->assertSame('2026-10-10', $domain->expires_on->toDateString());
        $this->assertSame('Örnek Registrar A.Ş.', $domain->provider);
        $ssl = AssetRenewal::query()->where('kind', 'ssl')->sole();
        $this->assertSame('2026-12-20', $ssl->expires_on->toDateString());
        $this->assertTrue($ssl->auto_renew, "Let's Encrypt style issuer renews itself");
        $this->assertSame(1, $stats['pushed'], 'domain expires in 9 days → the 14-day reminder');

        app(RenewalService::class)->daily();
        Http::assertSentCount(2, 'RDAP is not asked again within 7 days; reminder not repeated');

        app(AssetAlertScanner::class)->scan($this->site);
        $alert = AssetAlert::query()->open()->where('kind', 'renewal_due_domain')->sole();
        $this->assertSame('medium', $alert->severity);
        $this->assertStringContainsString('9 gün kaldı', $alert->message);
    }

    public function test_manual_date_wins_and_page_manages_collection(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);

        Livewire::test(RenewalsPage::class)
            ->call('create')
            ->set('form.brand_id', (string) $this->brand->id)->set('form.kind', 'hosting')->set('form.label', 'atlasdis.com.tr hosting')
            ->set('form.expires_on', '2026-10-20')->set('form.cost_amount', '1500')->set('form.charge_amount', '3000')
            ->call('save')
            ->assertSee('atlasdis.com.tr hosting')
            ->assertSee('30 gün içinde tahsil edilecek')->assertSee('3.000 TRY');
        $hosting = AssetRenewal::query()->where('kind', 'hosting')->sole();
        $this->assertSame('manual', $hosting->expires_source);

        Livewire::test(RenewalsPage::class)->call('setCollection', $hosting->id, 'paid')->call('renewed', $hosting->id)->assertSee('bir yıl uzatıldı');
        $this->assertSame('2027-10-20', $hosting->fresh()->expires_on->toDateString());
        $this->assertSame('not_billed', $hosting->fresh()->collection_status, 'next year is not billed yet');

        AssetRenewal::query()->create(['brand_id' => $this->brand->id, 'digital_asset_id' => $this->site->id, 'kind' => 'domain', 'label' => 'atlasdis.com.tr', 'expires_on' => '2027-01-01', 'expires_source' => 'manual']);
        app(RenewalService::class)->syncDomain($this->site);
        $this->assertSame('2027-01-01', AssetRenewal::query()->where('kind', 'domain')->value('expires_on') ? AssetRenewal::query()->where('kind', 'domain')->first()->expires_on->toDateString() : null);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'rdap.org'));
    }
}
