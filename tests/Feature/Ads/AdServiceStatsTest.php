<?php

namespace Tests\Feature\Ads;

use App\Jobs\Ads\RefreshAdServiceStatsJob;
use App\Livewire\Demo\Meta\OverviewPage;
use App\Livewire\Operator\Meta\DeskPage;
use App\Livewire\Operator\Workspace\BrandScorecardTab;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\DigitalAsset;
use App\Services\Ads\AdServiceStats;
use App\Services\Ads\BrandServiceScorecard;
use App\Services\Meta\MetaCampaignBoard;
use App\Services\Meta\MetaCampaignServices;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\SeedsMetaAccount;
use Tests\TestCase;

/**
 * Hizmet ortalaması: the daily 30-day numbers per brand service and Meta campaign, the median of the other brands
 * (city first), the "Hizmet ort." column, Meta masası and the brand's Hizmet karnesi.
 */
class AdServiceStatsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMetaAccount;

    private BrandOffering $implant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        Http::preventStrayRequests();
        $this->seedMetaAccount();
        $services = app(MetaCampaignServices::class);
        $services->sync($this->asset->load('brand'));
        $id = collect($services->offerings($this->brand))->firstWhere('name', 'Diş İmplantı')['id'];
        $this->implant = BrandOffering::query()->findOrFail($id);
    }

    /** Three other dental brands advertising implants on Meta: form costs 40, 60 and 100. */
    private function otherBrands(string $city = 'Ankara'): void
    {
        foreach ([['Atlas', 400, 10], ['Beta', 600, 10], ['Gama', 1000, 10]] as [$name, $spend, $results]) {
            $brand = Brand::factory()->create(['customer_id' => $this->brand->customer_id, 'name' => $name, 'sector_id' => $this->brand->sector_id]);
            $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'meta_ads', 'name' => $name.' Meta']);
            DB::table('ad_service_stats')->insert(['channel' => 'meta', 'digital_asset_id' => $asset->id, 'brand_id' => $brand->id, 'brand_offering_id' => 999,
                'service_id' => $this->implant->service_catalog_item_id, 'sector_id' => $brand->sector_id, 'city' => $city, 'result_type' => 'leads',
                'spend' => $spend, 'results' => $results, 'period_end' => '2026-10-29', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_refresh_stores_campaign_rows_and_service_numbers_by_result_type(): void
    {
        $this->assertSame(1, app(AdServiceStats::class)->refresh($this->asset), '30 days: 2 more days at 4 leads a day');

        $service = DB::table('ad_service_stats')->sole();
        $this->assertSame(['meta', $this->implant->id, 'leads', 'Ankara', 3000.0, 64.0],
            [$service->channel, (int) $service->brand_offering_id, $service->result_type, $service->city, (float) $service->spend, (float) $service->results]);
        $campaigns = DB::table('ad_campaign_stats')->orderBy('campaign_id')->get();
        $this->assertSame(['c1', 'c2'], $campaigns->pluck('campaign_id')->all());
        $this->assertSame(['leads', 46.88, 'suggested'], [$campaigns[0]->result_type, (float) $campaigns[0]->cpr, $campaigns[0]->service_state]);
        $this->assertSame((int) $this->implant->service_catalog_item_id, json_decode($campaigns[0]->services, true)[0]['service_id']);

        app(AdServiceStats::class)->refresh($this->asset);
        $this->assertSame(1, DB::table('ad_service_stats')->count(), 'a refresh replaces the account rows');
    }

    public function test_average_uses_the_other_brands_in_the_city_first(): void
    {
        $this->otherBrands();
        $costs = AdServiceStats::brandCosts('meta');
        $serviceId = (int) $this->implant->service_catalog_item_id;

        $average = AdServiceStats::average($costs, $serviceId, 'leads', $this->brand->id, 'Ankara');
        $this->assertSame([60.0, 3, 'Ankara', 40.0], [$average['median'], $average['brands'], $average['scope'], $average['leader']['cost']]);
        $this->assertSame('tüm şehirler', AdServiceStats::average($costs, $serviceId, 'leads', $this->brand->id, 'İzmir')['scope']);
        $this->assertNull(AdServiceStats::average($costs, $serviceId, 'messages', $this->brand->id, 'Ankara'), 'types are never mixed');
        $this->assertSame(['better', 'around', 'worse'], [AdServiceStats::verdict(50, 60), AdServiceStats::verdict(65, 60), AdServiceStats::verdict(80, 60)]);
    }

    public function test_campaigns_tab_shows_the_service_average(): void
    {
        $this->otherBrands();
        $rows = array_column(app(MetaCampaignBoard::class)->board($this->asset->load('brand'), 28)['rows'], null, 'id');

        $this->assertSame([60.0, 'better'], [$rows['c1']['average']['median'], $rows['c1']['average']['verdict']]);
        $this->assertNull($rows['c2']['average'], 'no service, no average');
        Livewire::actingAs($this->admin)->test(OverviewPage::class, ['assetId' => (string) $this->asset->id])->assertSee('Hizmet ort.')->assertSee('60,00 TRY');
    }

    public function test_meta_desk_lists_every_brand_with_averages_and_alerts(): void
    {
        $this->otherBrands();
        app(AdServiceStats::class)->refresh($this->asset);

        $this->actingAs($this->admin)->get(route('operator.meta-desk'))->assertOk()->assertSee('Meta masası')->assertSee('Meta reklamları')
            ->assertSee('Diş İmplantı Lead Ankara')->assertSee('Hizmet ortalamaları · tüm markalar')->assertSee('Atlas');
        Livewire::actingAs($this->admin)->test(DeskPage::class)
            ->assertSeeHtml('desk-'.$this->asset->id.'-c1"')
            ->set('service', 'none')->assertSeeHtml('desk-'.$this->asset->id.'-c2"')->assertDontSeeHtml('desk-'.$this->asset->id.'-c1"')
            ->set('service', '')->set('alert', 'disapproved')->assertSee('Genel Trafik')
            ->set('alert', 'bogus')->assertSet('alert', '');
    }

    public function test_brand_scorecard_colours_each_service_and_writes_notes(): void
    {
        $this->otherBrands();
        app(AdServiceStats::class)->refresh($this->asset);

        $card = app(BrandServiceScorecard::class)->scorecard($this->brand);
        $rows = array_column($card['rows'], null, 'name');
        $this->assertSame(['better', 46.88, ['rank' => 2, 'of' => 4]], [$rows['Diş İmplantı']['meta']['state'], $rows['Diş İmplantı']['meta']['cost'], $rows['Diş İmplantı']['rank']]);
        $this->assertNull($rows['Zirkonyum Kaplama']['meta']);
        $this->assertSame('none', $rows['Diş İmplantı']['web']['state']);
        $this->assertSame('na', $rows['Diş İmplantı']['gbp']['state']);
        $this->assertSame(['Fırsat', 'Sayfa yok'], array_column($card['notes'], 'kind'));

        Livewire::actingAs($this->admin)->test(BrandScorecardTab::class, ['brandId' => $this->brand->id])
            ->assertSee('Hizmet başına dört kanal')->assertSee('form başı 47 TRY')->assertSee('2')->assertSee('/ 4 marka')->assertSee('Reklam yok');
        $this->actingAs($this->admin)->get(route('operator.brand', ['brand' => $this->brand->id, 'tab' => 'karne']))->assertOk()->assertSee('Hizmet karnesi');
    }

    public function test_daily_command_queues_every_ad_account(): void
    {
        Queue::fake();
        BrandServiceArea::query()->update(['city_name' => 'Ankara']);
        Artisan::call('moxdop:ads:service-stats');

        Queue::assertPushed(RefreshAdServiceStatsJob::class, fn ($job): bool => $job->assetId === $this->asset->id);
    }
}
