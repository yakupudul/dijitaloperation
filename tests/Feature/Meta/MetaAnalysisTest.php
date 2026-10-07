<?php

namespace Tests\Feature\Meta;

use App\Livewire\Demo\Meta\OverviewPage;
use App\Services\Meta\MetaAnalysis;
use App\Services\Meta\MetaCampaignServices;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\SeedsMetaAccount;
use Tests\TestCase;

/**
 * Analiz: scope (account / service / campaign), previous period or last year, weekly series, cost per result type,
 * age × gender cost with the cheapest and dearest slice, day × hour results, placement and device shares, interests.
 */
class MetaAnalysisTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMetaAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        Http::preventStrayRequests();
        $this->seedMetaAccount();
        // 2026-10-26 is a Monday.
        foreach ([
            ['age_gender', '25-34', 'female', 'c1', 300, 6], ['age_gender', '35-44', 'female', 'c1', 400, 4], ['age_gender', '18-24', 'male', 'c1', 200, 0],
            ['age_gender', '45-54', 'male', 'c1', 600, 3], ['age_gender', '25-34', 'male', 'c2', 500, 0],
            ['hour', '10', '', 'c1', 300, 5], ['hour', '21', '', 'c1', 200, 2],
            ['placement', 'facebook', 'feed', 'c1', 500, 9], ['placement', 'instagram', 'instagram_reels', 'c1', 300, 1],
            ['device', 'mobile_app', '', 'c1', 700, 8], ['device', 'desktop', '', 'c1', 100, 2],
        ] as $i => [$dimension, $key1, $key2, $campaign, $spend, $leads]) {
            DB::table('meta_breakdown_results_daily')->insert(['digital_asset_id' => $this->asset->id, 'account_id' => '777', 'reporting_date' => '2026-10-26', 'dimension' => $dimension,
                'ad_id' => $campaign === 'c1' ? 'ad1' : 'ad2', 'adset_id' => $campaign === 'c1' ? 'as1' : 'as2', 'campaign_id' => $campaign, 'key1' => $key1, 'key2' => $key2,
                'spend' => $spend, 'leads' => $leads, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function analysis(string $focus = '', string $compare = 'prev', string $type = ''): array
    {
        return app(MetaAnalysis::class)->analysis($this->asset->load('brand'), 28, $focus, $compare, $type);
    }

    public function test_account_analysis_reads_every_breakdown(): void
    {
        $a = $this->analysis();

        $this->assertSame('Tüm hesap', $a['focus_label']);
        $this->assertSame('leads', $a['type']);
        $this->assertSame([56.0, -50.0, 75.0], [(float) $a['kpis']['leads']['value'], $a['kpis']['leads']['change'], $a['kpis']['leads']['cost']]);
        $this->assertSame(4200.0, array_sum(array_column($a['weekly'], 'spend')));
        $this->assertSame('2026-09-28', $a['weekly'][0]['week'], 'weeks start on Monday');
        $this->assertTrue($a['has_breakdowns']);

        $ag = $a['age_gender'];
        $this->assertSame(50.0, $ag['cells']['25-34']['female']['cost']);
        $this->assertNull($ag['cells']['18-24']['male']['cost']);
        $this->assertSame(['25-34', 'Kadın', 50.0], [$ag['best']['age'], $ag['best']['gender'], $ag['best']['cost']]);
        $this->assertSame(['45-54', 'Erkek', 200.0], [$ag['worst']['age'], $ag['worst']['gender'], $ag['worst']['cost']]);

        $this->assertSame([5.0, 2.0], [$a['hours']['grid'][1][2], $a['hours']['grid'][1][5]], 'Monday 08–12 and 20–24');
        $this->assertSame(['Facebook akış', 90.0], [$a['placements'][0]['label'], $a['placements'][0]['share']]);
        $this->assertSame('Instagram reels', $a['placements'][1]['label']);
        $this->assertSame(['Mobil uygulama', 80.0], [$a['devices'][0]['label'], $a['devices'][0]['share']]);
        $this->assertSame(['İmplant Ankara 35+', 'leads'], [$a['interests'][0]['set'], $a['interests'][0]['type']]);
    }

    public function test_focus_on_a_service_or_campaign_and_compare_with_last_year(): void
    {
        $services = app(MetaCampaignServices::class);
        $services->sync($this->asset->load('brand'));
        $implant = collect($services->offerings($this->brand))->firstWhere('name', 'Diş İmplantı');

        $a = $this->analysis('service:'.$implant['id']);
        $this->assertSame('Diş İmplantı', $a['focus_label']);
        $this->assertSame(['Diş İmplantı'], array_column($a['options']['services'], 'name'));
        $this->assertSame(2800.0, $a['kpis']['spend']['value']);
        $this->assertNull($a['age_gender']['cells']['25-34']['male']['cost']);
        $this->assertSame(0.0, $a['age_gender']['cells']['25-34']['male']['spend'], 'the other campaign is left out');

        $c2 = $this->analysis('campaign:c2');
        $this->assertSame(['Genel Trafik', 1400.0], [$c2['focus_label'], $c2['kpis']['spend']['value']]);
        $this->assertSame('Tüm hesap', $this->analysis('campaign:yok')['focus_label']);

        $year = $this->analysis('', 'year');
        $this->assertSame(['2025-10-02', '2025-10-29'], [$year['window']['cmp_from'], $year['window']['cmp_to']]);
        $this->assertNull($year['kpis']['leads']['change'], 'nothing collected a year ago');
    }

    public function test_analysis_tab_renders_and_switches(): void
    {
        Livewire::actingAs($this->admin)->test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => 'analysis'])
            ->assertSee('Yaş × cinsiyet · form başı')->assertSee('En iyi dilim:')->assertSee('Gün × saat')->assertSee('Facebook akış')->assertSee('Mobil uygulama')
            ->assertSee('Kırılım verisini çek', false)
            ->set('focus', 'campaign:c2')->assertSee('Genel Trafik · ')
            ->set('compare', 'year')->assertSet('compare', 'year')->assertSee('karşılaştırma yok')
            ->set('compare', 'nope')->assertSet('compare', 'prev')
            ->set('analysisType', 'messages')->assertSee('Yaş × cinsiyet · mesaj başı')->assertSee('Bu türde yaş / cinsiyet verisi yok.');
    }
}
