<?php

namespace Tests\Feature\GoogleAds;

use App\Enums\CustomerStatus;
use App\Enums\DigitalAssetStatus;
use App\Jobs\ApproveRepairRowsJob;
use App\Livewire\Operator\GoogleAds\Desk\AdsPage;
use App\Livewire\Operator\GoogleAds\Desk\ChangesPage;
use App\Livewire\Operator\GoogleAds\Desk\DeskPage;
use App\Livewire\Operator\GoogleAds\Desk\MeasurementPage;
use App\Livewire\Operator\GoogleAds\Desk\ServicesPage;
use App\Livewire\Operator\GoogleAds\Desk\TermsPage;
use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Alerts\AssetAlertScanner;
use App\Services\GoogleAds\Desk\GoogleAdsDesk;
use App\Services\GoogleAds\GoogleAdsChanges;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\GoogleAds\GoogleAdsSuggestions;
use App\Services\Repair\RepairDesk;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Google Ads menüsü (yakup 2026-10-19): every account of every operational brand on one Durum list with the red strip
 * (a spending account whose data stopped is caught even while collection runs), the cross-account tabs approving
 * through the Onarım masası path or the Editor file, and the account whose data stopped raising an alarm.
 */
final class GoogleAdsDeskTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CoreIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        Http::preventStrayRequests();
        config(['moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret', 'moxdop.google.developer_token' => 'devtoken']);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE, 'config' => ['granted_scopes' => [GoogleScopes::ADWORDS]]]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $this->integration->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'developer_token' => 'devtoken']]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $this->integration->id, 'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => GoogleScopes::ADWORDS], 'expires_at' => now()->addHour()]);
    }

    public function test_durum_lists_every_account_and_puts_a_stopped_one_on_the_red_strip(): void
    {
        [$fresh, $freshResource] = $this->account('Bornova Hurda', '1112223333');
        [$stopped, $stoppedResource] = $this->account('Panorama Ankara', '4445556666');
        [$unbound] = $this->account('Dentocare', null);
        for ($day = 1; $day <= 20; $day++) {
            $this->campaignRow($freshResource, '1112223333', $day, 100, 2);
        }
        for ($day = 6; $day <= 20; $day++) {
            $this->campaignRow($stoppedResource, '4445556666', $day, 300, 1);
        }

        $rows = collect(app(GoogleAdsDesk::class)->status(app(GoogleAdsDesk::class)->accounts()))->keyBy(fn (array $r): int => (int) $r['asset']->id);
        $this->assertSame('ok', $rows[$fresh->id]['state']);
        $this->assertSame('late', $rows[$stopped->id]['state']);
        $this->assertSame('unbound', $rows[$unbound->id]['state']);
        $this->assertEqualsWithDelta(2000.0, $rows[$fresh->id]['current']['cost'], 0.01);

        $problems = GoogleAdsDesk::problems($rows->values()->all());
        $this->assertSame(['Panorama Ankara', 'Dentocare'], array_column($problems, 'brand'));
        $this->assertSame('Veri / harcama gelmiyor', $problems[0]['title']);

        Livewire::actingAs($this->admin)->test(DeskPage::class)
            ->assertOk()->assertSee('Şimdi bakılacaklar')->assertSee('Bornova Hurda')->assertSee('Hesap bağlı değil')
            ->call('toggle', $fresh->id)->assertSee('Son 6 ay')->assertSee('MoxDOP’un hesapta yaptıkları');
        Livewire::actingAs($this->admin)->test(DeskPage::class, ['brand' => $fresh->brand_id])->assertDontSee('Panorama Ankara Ads');
        $this->actingAs($this->admin)->get(route('operator.google-ads-desk'))->assertOk()->assertSee('Google Ads');
    }

    public function test_an_account_that_was_spending_and_stopped_raises_a_critical_alarm(): void
    {
        [$asset, $resource] = $this->account('Panorama Ankara', '4445556666');
        for ($day = 6; $day <= 30; $day++) {
            DB::table('google_ads_account_daily')->insert(['digital_asset_id' => null, 'external_resource_id' => $resource->id, 'customer_id' => '4445556666',
                'reporting_date' => now()->subDays($day)->toDateString(), 'impressions' => 1000, 'clicks' => 50, 'cost_micros' => 300_000_000, 'cost_amount' => 300,
                'conversions' => 1, 'currency' => 'TRY', 'metadata' => '{}'] + $this->provenance());
        }

        app(AssetAlertScanner::class)->scan($asset);

        $alert = AssetAlert::query()->open()->where('digital_asset_id', $asset->id)->where('kind', 'ads_data_gap')->first();
        $this->assertNotNull($alert);
        $this->assertSame('critical', $alert->severity);
        $this->assertStringContainsString(now()->subDays(6)->format('d.m.Y'), $alert->message);

        // A row of the account that never spent does not ring.
        [$quiet, $quietResource] = $this->account('Dentocare', '7778889999');
        DB::table('google_ads_account_daily')->insert(['digital_asset_id' => null, 'external_resource_id' => $quietResource->id, 'customer_id' => '7778889999',
            'reporting_date' => now()->subDays(10)->toDateString(), 'impressions' => 10, 'clicks' => 1, 'cost_micros' => 1_000_000, 'cost_amount' => 1,
            'conversions' => 0, 'currency' => 'TRY', 'metadata' => '{}'] + $this->provenance());
        app(AssetAlertScanner::class)->scan($quiet);
        $this->assertFalse(AssetAlert::query()->where('digital_asset_id', $quiet->id)->where('kind', 'ads_data_gap')->exists());
    }

    public function test_tabs_approve_desk_writes_through_the_repair_path_and_drafts_into_the_editor_file(): void
    {
        Queue::fake();
        [$asset] = $this->account('Bornova Hurda', '1112223333');
        $shared = $this->suggestion($asset, 'ads_negative', 'Negatif: «bedava»', ['text' => 'bedava', 'match_type' => 'PHRASE', 'scope' => 'shared', 'campaign' => '', 'ad_group' => '', 'blocks' => [], 'cost' => 240]);
        $campaignNegative = $this->suggestion($asset, 'ads_negative', 'Negatif: «iş ilanı»', ['text' => 'iş ilanı', 'match_type' => 'PHRASE', 'scope' => 'campaign', 'campaign' => 'Hurda', 'ad_group' => '', 'blocks' => [], 'cost' => 80]);
        $change = $this->suggestion($asset, GoogleAdsChanges::TYPE, 'Arama ağı ortaklarını kapat', ['field' => 'search_partners', 'resource' => 'customers/1112223333/campaigns/77',
            'before' => true, 'after' => false, 'label' => 'Kapalı', 'target' => 'Kampanya 77', 'risk' => 'low']);
        $check = $this->suggestion($asset, 'ads_check', 'Dönüşüm izleme', ['check' => 'conversion_tracking', 'todo' => 'Form gönderimini dönüşüm yap.']);
        $rsa = $this->suggestion($asset, 'ads_rsa', 'Reklam metni: Hurda', ['campaign' => 'Hurda', 'ad_group' => 'Hurda', 'headlines' => ['A', 'B', 'C'], 'descriptions' => ['D', 'E'], 'final_url' => 'https://bornova.test/']);

        Livewire::actingAs($this->admin)->test(TermsPage::class)->assertOk()->assertSee('bedava')->assertSee('iş ilanı')->assertSee('Paylaşılan liste')
            ->set('selected', [$shared->id, $campaignNegative->id])->call('approveSelected')->assertSee('1 taslak onaylandı');
        $this->assertNotNull(data_get($shared->fresh()->action, RepairDesk::QUEUED_AT), 'shared negative goes through the desk approval');
        $this->assertSame(Suggestion::APPROVED, $campaignNegative->fresh()->status);
        Queue::assertPushed(ApproveRepairRowsJob::class);

        Livewire::actingAs($this->admin)->test(ChangesPage::class)->assertOk()->assertSee('Kampanya 77')->assertSee('Form gönderimini dönüşüm yap.')
            ->call('approve', $change->id)->call('approve', $check->id);
        $this->assertNotNull(data_get($change->fresh()->action, RepairDesk::QUEUED_AT));
        $this->assertSame(Suggestion::OPEN, $check->fresh()->status, 'a manual check is never approved');

        Livewire::actingAs($this->admin)->test(AdsPage::class)->assertOk()->assertSee('Reklam metni: Hurda')
            ->call('approve', $rsa->id)->assertSee('Editor dosyası bekleyen hesaplar');
        $this->assertSame(Suggestion::APPROVED, $rsa->fresh()->status);

        Livewire::actingAs($this->admin)->test(TermsPage::class)->call('dismiss', $campaignNegative->id);
        $this->assertSame(Suggestion::DISMISSED, $campaignNegative->fresh()->status);

        $operator = User::factory()->create(['is_active' => true]);
        $operator->assignRole(Roles::TEAM_MEMBER);
        Livewire::actingAs($operator)->test(ChangesPage::class)->call('approve', $change->id)->assertForbidden();
    }

    public function test_measurement_and_service_tabs_render(): void
    {
        [$asset, $resource] = $this->account('Bornova Hurda', '1112223333');
        DB::table('google_ads_conversion_action_snapshot')->insert(['digital_asset_id' => null, 'external_resource_id' => $resource->id, 'customer_id' => '1112223333',
            'source_timezone' => 'Europe/Istanbul', 'conversion_action_id' => '601',
            'metadata' => json_encode(['name' => 'Sayfa görüntüleme', 'status' => 'ENABLED', 'category' => 'PAGE_VIEW', 'primary_for_goal' => true])] + $this->provenance());

        Livewire::actingAs($this->admin)->test(MeasurementPage::class)->assertOk()->assertSee('Sayfa görüntüleme')
            ->assertSee('zayıf bir işlem ama birincil')->assertSee('Telefon araması dönüşümü yok');
        Livewire::actingAs($this->admin)->test(ServicesPage::class)->assertOk()->assertSee('Karşılaştırılacak hizmet yok');
        $this->actingAs($this->admin)->get(route('operator.google-ads-services'))->assertOk();
    }

    /** @return array{0: DigitalAsset, 1: ?CoreExternalResource} */
    private function account(string $brandName, ?string $customerId): array
    {
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => $brandName]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_ads', 'module_id' => 'google_ads', 'status' => DigitalAssetStatus::Active, 'name' => $brandName.' Ads']);
        if ($customerId === null) {
            return [$asset, null];
        }
        $resource = CoreExternalResource::factory()->create(['integration_id' => $this->integration->id, 'provider' => 'google', 'resource_type' => GoogleResourceType::GOOGLE_ADS_CUSTOMER,
            'external_id' => $customerId, 'status' => CoreExternalResource::STATUS_AVAILABLE, 'metadata' => ['timezone' => 'Europe/Istanbul', 'currency' => 'TRY']]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => GoogleAdsSpecialistBindingResolver::CAPABILITY, 'status' => CoreAssetBinding::STATUS_ACTIVE]);

        return [$asset, $resource];
    }

    private function campaignRow(CoreExternalResource $resource, string $customerId, int $daysAgo, float $cost, float $conversions): void
    {
        DB::table('google_ads_campaign_daily')->insert(['digital_asset_id' => null, 'external_resource_id' => $resource->id, 'customer_id' => $customerId, 'source_timezone' => 'Europe/Istanbul',
            'campaign_id' => '101', 'reporting_date' => now('Europe/Istanbul')->subDays($daysAgo)->toDateString(), 'impressions' => 500, 'clicks' => 20, 'cost_micros' => 0,
            'cost_amount' => $cost, 'conversions' => $conversions, 'currency' => 'TRY', 'metadata' => '{}'] + $this->provenance());
    }

    /** @param  array<string, mixed>  $action */
    private function suggestion(DigitalAsset $asset, string $type, string $title, array $action): Suggestion
    {
        return Suggestion::query()->create(['brand_id' => $asset->brand_id, 'channel' => GoogleAdsSuggestions::CHANNEL, 'target_type' => GoogleAdsSuggestions::TARGET,
            'target_id' => $asset->id, 'decision_key' => 'google_ads.'.$type, 'fingerprint' => hash('sha256', $asset->id.'|'.$type.'|'.$title), 'material_hash' => 'x', 'title' => $title, 'reason' => 'Gerekçe.', 'priority' => 2, 'status' => Suggestion::OPEN,
            'action_type' => $type, 'action' => $action, 'evidence' => []]);
    }

    /** @return array<string, mixed> */
    private function provenance(): array
    {
        return ['contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => bin2hex(random_bytes(32)), 'created_at' => now(), 'updated_at' => now()];
    }
}
