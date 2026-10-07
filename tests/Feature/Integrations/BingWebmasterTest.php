<?php

namespace Tests\Feature\Integrations;

use App\Livewire\Operator\Integrations\BingIntegrationPage;
use App\Livewire\Operator\Website\V2\OverviewTab;
use App\Models\AgencySetting;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Integrations\Bing\BingWebmasterClient;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bing Webmaster (yakup, 2026-10-07): the agency key is checked against Bing before it is kept (encrypted), Bing sites
 * are matched to MoxDOP websites by host, each site's weekly searches are stored and shown on the website screen.
 */
final class BingWebmasterTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Adadent']);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'name' => 'adadent.com.tr', 'primary_url' => 'https://adadent.com.tr/', 'status' => 'active']);
        DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'name' => 'baska.com', 'primary_url' => 'https://baska.com/', 'status' => 'active']);
    }

    public function test_the_key_is_checked_sites_are_matched_and_their_searches_are_read(): void
    {
        Http::fake([
            'ssl.bing.com/webmaster/api.svc/json/GetUserSites*' => fn ($request) => str_contains($request->url(), 'apikey=yanlis')
                ? Http::response('', 401)
                : Http::response(['d' => [['__type' => 'Site', 'Url' => 'https://www.adadent.com.tr/', 'IsVerified' => true], ['Url' => 'https://yabanci.com/', 'IsVerified' => true]]]),
            'ssl.bing.com/webmaster/api.svc/json/GetQueryStats*' => Http::response(['d' => [
                ['Query' => 'implant fiyatları', 'Date' => '/Date('.(now()->subDays(3)->getTimestamp() * 1000).'-0700)/', 'Impressions' => 120, 'Clicks' => 6, 'AvgImpressionPosition' => 7.4],
                ['Query' => 'implant fiyatları', 'Date' => '/Date('.(now()->subDays(10)->getTimestamp() * 1000).')/', 'Impressions' => 80, 'Clicks' => 2, 'AvgImpressionPosition' => 8.6],
                ['Query' => 'diş beyazlatma', 'Date' => '/Date('.(now()->subDays(3)->getTimestamp() * 1000).')/', 'Impressions' => 30, 'Clicks' => 0, 'AvgImpressionPosition' => -1],
                ['Query' => '', 'Date' => 'bozuk'],
            ]]),
        ]);

        Livewire::test(BingIntegrationPage::class)->set('apiKey', 'yanlis')->call('save')->assertHasErrors('apiKey');
        $this->assertFalse(BingWebmasterClient::configured());

        Livewire::test(BingIntegrationPage::class)->set('apiKey', 'dogru-anahtar')->call('save')->assertHasNoErrors()
            ->assertSee('1 tanesi MoxDOP sitesiyle eşleşti')->assertSee('https://www.adadent.com.tr/');
        $this->assertSame('dogru-anahtar', BingWebmasterClient::apiKey());
        $this->assertNotSame('dogru-anahtar', DB::table('agency_settings')->value('bing_webmaster_api_key'), 'stored encrypted');
        $this->assertSame(1, DB::table('bing_sites')->count());
        $this->assertSame(3, DB::table('bing_query_stats')->where('digital_asset_id', $this->site->id)->count());
        $this->assertNull(DB::table('bing_query_stats')->where('query', 'diş beyazlatma')->value('position'), 'Bing gives -1 when it has no position');

        Livewire::test(OverviewTab::class, ['assetId' => $this->site->id])->assertSeeHtml('data-bing-queries')->assertSee('implant fiyatları')->assertSee('230 gösterim');

        $this->artisan('moxdop:bing:collect')->assertSuccessful();
        $this->assertSame(3, DB::table('bing_query_stats')->count(), 'reading again updates the same weeks');
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_without_a_key_nothing_is_read(): void
    {
        Http::fake();
        AgencySetting::query()->delete();

        $this->artisan('moxdop:bing:collect')->expectsOutputToContain('Bing anahtarı yok')->assertSuccessful();
        Http::assertNothingSent();
        Livewire::test(BingIntegrationPage::class)->assertSee('Bağlı değil');
    }
}
