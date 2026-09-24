<?php

namespace Tests\Feature\GoogleAds;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\GoogleAds\AuctionInsightsPanel;
use App\Livewire\Operator\Market\CompetitorWatchPage;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\GoogleAdsAuctionInsight;
use App\Models\Intel\BrandIntelSetting;
use App\Models\User;
use App\Services\GoogleAds\AuctionInsightsImporter;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faz 14g: Auction Insights CSV upload (Google Ads API has no auction insights).
 */
final class AuctionInsightsUploadTest extends TestCase
{
    use RefreshDatabase;

    private const TR_CSV = "Açık artırma analizi raporu\n\"1 Eylül 2026 - 30 Eylül 2026\"\nGörünen URL alanı;Gösterim payı;Çakışma oranı;Üst konum oranı;Sayfanın üst kısmında gösterim oranı;Sayfanın en üst kısmında gösterim oranı;Geride bırakma payı\nSiz;%45,20;--;--;%60,10;%22,00;--\nrakipklinik.com;%38,50;%40,00;%35,50;%70,00;%30,00;%12,00\nbaska.com;< %10;%12,00;%8,00;%50,00;%10,00;%5,00\nToplam;;;;;;\n";

    private const EN_CSV = "Display URL domain,Impression share,Overlap rate,Position above rate,Top of page rate,Abs. Top of page rate,Outranking share\nYou,50.00%,--,--,62.00%,25.00%,--\nrakipklinik.com,30.00%,41.00%,30.00%,65.00%,28.00%,14.00%\nyeni-rakip.com,15.00%,10.00%,9.00%,40.00%,12.00%,7.00%\n";

    private DigitalAsset $asset;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $this->brand = Brand::factory()->create(['name' => 'Atlas Dental', 'customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        $this->asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'name' => 'Atlas Ads', 'status' => 'active']);
    }

    public function test_parses_turkish_semicolon_and_english_utf16_exports(): void
    {
        $importer = app(AuctionInsightsImporter::class);
        $tr = collect($importer->parse(self::TR_CSV))->keyBy('domain');
        $this->assertCount(3, $tr);
        $this->assertTrue($tr['siz']['is_own']);
        $this->assertEqualsWithDelta(0.452, $tr['siz']['impression_share'], 0.0001);
        $this->assertNull($tr['siz']['overlap_rate']);
        $this->assertEqualsWithDelta(0.3, $tr['rakipklinik.com']['abs_top_rate'], 0.0001);
        $this->assertEqualsWithDelta(0.7, $tr['rakipklinik.com']['top_of_page_rate'], 0.0001);
        $this->assertEqualsWithDelta(0.355, $tr['rakipklinik.com']['position_above_rate'], 0.0001);
        $this->assertEqualsWithDelta(0.12, $tr['rakipklinik.com']['outranking_share'], 0.0001);
        $this->assertSame(['impression_share'], $tr['baska.com']['below_threshold']);

        $utf16 = "\xFF\xFE".mb_convert_encoding(str_replace(',', "\t", self::EN_CSV), 'UTF-16LE', 'UTF-8');
        $en = collect($importer->parse($utf16))->keyBy('domain');
        $this->assertTrue($en['you']['is_own']);
        $this->assertEqualsWithDelta(0.25, $en['you']['abs_top_rate'], 0.0001);
        $this->assertEqualsWithDelta(0.65, $en['rakipklinik.com']['top_of_page_rate'], 0.0001);
    }

    public function test_rejects_a_file_without_the_header(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(AuctionInsightsImporter::class)->parse("Kampanya,Tıklama\nA,10\n");
    }

    public function test_upload_replaces_same_period_and_shows_change_against_previous_upload(): void
    {
        $importer = app(AuctionInsightsImporter::class);
        $importer->import($this->asset, self::EN_CSV, '2026-08-01', '2026-08-31', null);
        $importer->import($this->asset, self::EN_CSV, '2026-08-01', '2026-08-31', null);
        $this->assertSame(3, GoogleAdsAuctionInsight::query()->count());

        $this->travel(1)->minutes();
        Livewire::test(AuctionInsightsPanel::class, ['assetId' => (string) $this->asset->id])
            ->set('report', UploadedFile::fake()->createWithContent('auction.csv', self::TR_CSV))
            ->set('periodStart', '2026-09-01')->set('periodEnd', '2026-09-30')
            ->call('upload')
            ->assertSet('error', '')
            ->assertSee('3 satır yüklendi.')
            ->assertSee('rakipklinik.com')
            ->assertSee('<%10')
            ->assertSee('+8,5');

        $latest = $importer->latest($this->asset);
        $this->assertSame('2026-09-01', $latest['upload']['period_start']);
        $this->assertTrue(collect($latest['rows'])->firstWhere('domain', 'baska.com')['is_new']);

        Livewire::test(AuctionInsightsPanel::class, ['assetId' => (string) $this->asset->id])
            ->set('report', UploadedFile::fake()->createWithContent('bad.csv', "a,b\n1,2\n"))
            ->call('upload')->assertSee('başlığı bulunamadı');
    }

    public function test_tab_and_competitor_screen_show_the_upload(): void
    {
        app(AuctionInsightsImporter::class)->import($this->asset, self::EN_CSV, null, null, null);
        BrandIntelSetting::for($this->brand)->save();

        $this->get(route('operator.google-ads.overview', ['assetId' => $this->asset->id, 'tab' => 'auction_insights']))
            ->assertOk()->assertSee('Açık artırma analizi')->assertSee('yeni-rakip.com');
        Livewire::test(CompetitorWatchPage::class, ['brand' => $this->brand->id, 'tab' => 'auction'])
            ->assertSee('Atlas Ads')->assertSee('yeni-rakip.com')->assertSee('Rapor yükle');
    }
}
