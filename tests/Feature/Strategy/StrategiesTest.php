<?php

namespace Tests\Feature\Strategy;

use App\Livewire\Operator\Strategy\StrategiesPage;
use App\Livewire\Operator\Strategy\StrategyPage;
use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\ListStrategies;
use App\Mcp\Tools\SaveStrategy;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Strategy\StrategyAdsEditorExport;
use App\Services\Strategy\StrategyBook;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Stratejiler: Claude saves strategies over MCP (create, new version on a changed text, none on the same text), the
 * operator reads them in the panel (list with filters, text with table of contents, scope, evidence, versions) and
 * moves their status; an older version can be read and brought back without losing history.
 */
final class StrategiesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private string $body = "# OPC Klinik yurt dışı\n\n## Hedef\n\nİstanbul’a gelecek All-on-4 hastaları.\n\n## Negatif kelimeler\n\n| Kelime | Neden |\n|---|---|\n| free | ücretsiz arayan |\n\n### Zamanlama\n\nHafta içi.\n\n<script>alert(1)</script>";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'OPC Klinik']);
    }

    public function test_claude_saves_a_strategy_and_a_new_version_only_when_the_text_changes(): void
    {
        MoxdopServer::tool(SaveStrategy::class, [
            'title' => 'OPC · All-on-4/6 yurt dışı Google Ads', 'brand_id' => $this->brand->id, 'channel' => 'google_ads',
            'service' => 'All-on-4 / All-on-6', 'markets' => ['Birleşik Krallık', 'Almanya', 'Birleşik Krallık'], 'languages' => ['İngilizce'],
            'budget_amount' => 4000, 'budget_period' => 'daily', 'summary' => 'İngilizce ile başla.', 'body' => $this->body,
            'evidence' => [['label' => 'Google Ads', 'detail' => 'Son 90 gün, 13 hesap']],
        ])->assertOk()->assertSee('kaydedildi (v1)');

        $strategy = Strategy::query()->sole();
        $this->assertSame(['Birleşik Krallık', 'Almanya'], $strategy->markets);
        $this->assertSame('4.000 TRY günlük', $strategy->budgetLabel());
        $this->assertSame('draft', $strategy->status);

        MoxdopServer::tool(SaveStrategy::class, ['strategy_id' => $strategy->id, 'body' => $this->body])->assertOk()->assertSee('(v1)');
        MoxdopServer::tool(SaveStrategy::class, ['strategy_id' => $strategy->id, 'body' => $this->body."\n\nEk bölüm.", 'change_note' => 'Almanca eklendi'])
            ->assertOk()->assertSee('(v2)');

        $strategy->refresh();
        $this->assertSame(2, $strategy->current_version);
        $this->assertSame([['label' => 'Google Ads', 'detail' => 'Son 90 gün, 13 hesap']], $strategy->versions()->where('version', 2)->first()->evidence, 'evidence carries over to a new version');

        MoxdopServer::tool(ListStrategies::class, ['brand_id' => $this->brand->id])->assertOk()->assertSee('All-on-4/6 yurt dışı');
        MoxdopServer::tool(ListStrategies::class, ['strategy_id' => $strategy->id, 'version' => 1])->assertOk()->assertSee('"version":1')->assertSee('Almanca eklendi');
        MoxdopServer::tool(SaveStrategy::class, ['title' => 'Eksik'])->assertHasErrors();
        MoxdopServer::tool(SaveStrategy::class, ['strategy_id' => 999999, 'body' => $this->body])->assertHasErrors();
    }

    public function test_the_operator_reads_filters_and_moves_strategies(): void
    {
        $book = app(StrategyBook::class);
        $strategy = $book->save(['title' => 'OPC · All-on-4/6 yurt dışı', 'brand_id' => $this->brand->id, 'channel' => 'google_ads',
            'markets' => ['Birleşik Krallık'], 'body' => $this->body, 'evidence' => [['label' => 'Meta', 'detail' => '240 bin TL harcama']]]);
        $book->save(['title' => 'Eski GBP planı', 'channel' => 'gbp', 'status' => 'archived', 'body' => 'Arşivdeki eski plan metni burada.']);

        $this->actingAs($this->admin)->get(route('operator.strategies'))->assertOk()->assertSee('Stratejiler')
            ->assertSee('OPC · All-on-4/6 yurt dışı')->assertDontSee('Eski GBP planı')->assertSee('Birleşik Krallık');
        Livewire::actingAs($this->admin)->test(StrategiesPage::class)->set('status', 'archived')->assertSee('Eski GBP planı')->assertDontSee('OPC · All-on-4/6');
        Livewire::actingAs($this->admin)->test(StrategiesPage::class)->set('channel', 'meta')->assertSee('Bu filtrede strateji yok');

        $page = $this->actingAs($this->admin)->get(route('operator.strategy', ['strategyId' => $strategy->id]))->assertOk()
            ->assertSee('İçindekiler')->assertSee('id="bolum-1"', false)->assertSee('<div class="table-wrap"><table data-sortable="false">', false)->assertSee('Zamanlama')->assertSee('Dayanak')->assertSee('Sürümler (1)');
        $this->assertStringNotContainsString('<h1>OPC Klinik yurt dışı</h1>', $page->getContent(), 'the leading h1 is the page title, not shown twice');
        $this->assertStringNotContainsString('<script>alert(1)</script>', $page->getContent());

        Livewire::actingAs($this->admin)->test(StrategyPage::class, ['strategyId' => $strategy->id])
            ->assertSee('240 bin TL harcama')->assertSee('Dayanak')
            ->call('setStatus', 'approved')->assertSee('Uygulamaya başladım')
            ->call('startRevision')->set('draftBody', $this->body."\n\n## Yeni bölüm\n\nAlmanca kampanya.")->set('changeNote', 'Almanca')->call('saveRevision')
            ->assertSee('v2 kaydedildi')->assertSee('Yeni bölüm')
            ->call('restore', 1)->assertSee('v1 yeniden geçerli');

        $strategy->refresh();
        $this->assertSame('approved', $strategy->status);
        $this->assertSame(3, $strategy->current_version);
        $this->assertSame(3, $strategy->versions()->count());

        Livewire::actingAs($this->admin)->test(StrategyPage::class, ['strategyId' => $strategy->id])
            ->set('version', 2)->assertSee('Eski sürüm görüntüleniyor')->assertSee('Yeni bölüm')
            ->call('startEdit')->set('scope.budget_amount', '2500')->set('scope.budget_period', 'daily')->call('saveScope')->assertSee('Kapsam kaydedildi');
        $this->assertSame('2.500 TRY günlük', $strategy->refresh()->budgetLabel());
    }

    public function test_an_approved_google_ads_strategy_with_a_plan_downloads_as_an_ads_editor_file(): void
    {
        $plan = ['campaigns' => [[
            'name' => 'EN | Search | All-on-4/6 | UK-IE', 'daily_budget' => 3800, 'max_cpc' => 250, 'bid_strategy' => 'Maximize clicks',
            'languages' => ['English'], 'locations' => ['United Kingdom', 'Ireland', 'London'], 'excluded_locations' => ['Turkey'],
            'location_bids' => [['location' => 'London', 'modifier' => 15], ['location' => 'Belfast', 'modifier' => -10]],
            'ad_schedule' => 'İstanbul 10:00–02:00', 'negatives' => [['text' => 'near me', 'match' => 'phrase']],
            'ad_groups' => [[
                'name' => 'AG1 · All-on-4', 'final_url' => 'https://opc.test/en/all-on-four-implants-in-istanbul/',
                'keywords' => [['text' => 'all on 4 turkey', 'match' => 'exact'], ['text' => 'all on 4 abroad', 'match' => 'phrase']],
                'negatives' => [['text' => 'veneers', 'match' => 'broad']],
                'ads' => [['headlines' => ['All-on-4 Implants in Istanbul', 'Fixed Teeth in Turkey', 'Send Your X-Ray'], 'descriptions' => ['Send a panoramic X-ray and get a written plan.', 'Hotel and transfer included.'], 'path1' => 'all-on-4', 'path2' => 'istanbul']],
            ]],
        ]], 'shared_negative_lists' => [['name' => 'N1 · Genel EN', 'campaigns' => ['EN | Search | All-on-4/6 | UK-IE'], 'keywords' => [['text' => 'free', 'match' => 'broad']]]],
            'notes' => ['Konum seçeneği: Varlık.']];

        MoxdopServer::tool(SaveStrategy::class, ['title' => 'OPC · Google Ads', 'brand_id' => $this->brand->id, 'channel' => 'google_ads', 'body' => $this->body, 'plan' => $plan])
            ->assertOk();
        MoxdopServer::tool(SaveStrategy::class, ['title' => 'Bozuk', 'channel' => 'google_ads', 'body' => $this->body, 'plan' => ['campaigns' => [['daily_budget' => 5]]]])->assertHasErrors();
        $strategy = Strategy::query()->sole();
        $this->assertSame(['campaigns' => 1, 'ad_groups' => 1, 'keywords' => 2, 'negatives' => 3, 'ads' => 1], StrategyAdsEditorExport::counts($strategy));

        $this->actingAs($this->admin)->get(route('operator.strategy', ['strategyId' => $strategy->id]))->assertOk()
            ->assertSee('Google Ads Editor')->assertSee('onaylayınca')->assertDontSee('ads-editor.csv');
        $this->actingAs($this->admin)->get(route('operator.strategy.ads-editor', ['strategyId' => $strategy->id, 'format' => 'csv']))->assertNotFound();

        app(StrategyBook::class)->setStatus($strategy, 'approved');
        $this->actingAs($this->admin)->get(route('operator.strategy', ['strategyId' => $strategy->id]))->assertOk()->assertSee('ads-editor.csv');
        $csv = $this->actingAs($this->admin)->get(route('operator.strategy.ads-editor', ['strategyId' => $strategy->id, 'format' => 'csv']))
            ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
        $lines = array_map('str_getcsv', array_filter(explode("\n", trim(substr($csv, 3)))));
        $header = $lines[0];
        $rows = array_map(fn (array $l): array => array_combine($header, $l), array_slice($lines, 1));
        $this->assertSame(['EN | Search | All-on-4/6 | UK-IE', 'Search', 'Google search', '3800.00', 'Maximize clicks', 'English', 'Paused'],
            [$rows[0]['Campaign'], $rows[0]['Campaign Type'], $rows[0]['Networks'], $rows[0]['Campaign Daily Budget'], $rows[0]['Bid Strategy Type'], $rows[0]['Languages'], $rows[0]['Campaign Status']]);
        $this->assertStringContainsString('Hariç konum: Turkey', $rows[0]['Comment']);
        $this->assertSame(['London', '+15%'], [$rows[3]['Location'], $rows[3]['Bid Modifier']]);
        $this->assertSame(['Belfast', '-10%'], [$rows[4]['Location'], $rows[4]['Bid Modifier']]);
        $this->assertSame(['near me', 'Campaign Negative Phrase'], [$rows[5]['Keyword'], $rows[5]['Criterion Type']]);
        $this->assertSame(['free', 'Campaign Negative Broad', 'Liste: N1 · Genel EN'], [$rows[6]['Keyword'], $rows[6]['Criterion Type'], $rows[6]['Comment']]);
        $this->assertSame(['AG1 · All-on-4', 'Enabled', '250.00'], [$rows[7]['Ad Group'], $rows[7]['Ad Group Status'], $rows[7]['Max CPC']]);
        $this->assertSame(['all on 4 turkey', 'Exact', 'https://opc.test/en/all-on-four-implants-in-istanbul/'], [$rows[8]['Keyword'], $rows[8]['Criterion Type'], $rows[8]['Final URL']]);
        $this->assertSame(['veneers', 'Negative Broad'], [$rows[10]['Keyword'], $rows[10]['Criterion Type']]);
        $this->assertSame(['Responsive search ad', 'All-on-4 Implants in Istanbul', 'Hotel and transfer included.', 'istanbul', 'Enabled'],
            [$rows[11]['Ad type'], $rows[11]['Headline 1'], $rows[11]['Description 2'], $rows[11]['Path 2'], $rows[11]['Status']]);

        $xlsx = $this->actingAs($this->admin)->get(route('operator.strategy.ads-editor', ['strategyId' => $strategy->id, 'format' => 'xlsx']))
            ->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->getContent();
        $zip = new \ZipArchive;
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $xlsx);
        $this->assertTrue($zip->open($tmp));
        $this->assertStringContainsString('All-on-4 Implants in Istanbul', (string) $zip->getFromName('xl/worksheets/sheet1.xml'));
        $this->assertStringContainsString('Konum seçeneği: Varlık.', (string) $zip->getFromName('xl/worksheets/sheet2.xml'));
        $this->assertStringContainsString('Hariç konum: Turkey', (string) $zip->getFromName('xl/worksheets/sheet2.xml'));
        $zip->close();
        unlink($tmp);

        MoxdopServer::tool(ListStrategies::class, ['strategy_id' => $strategy->id])->assertOk()->assertSee('"has_plan":true');
    }

    public function test_a_pasted_strategy_is_stored_from_the_list(): void
    {
        Livewire::actingAs($this->admin)->test(StrategiesPage::class)->set('creating', true)
            ->set('form.title', 'Dentocare Meta planı')->set('form.channel', 'meta')->set('form.markets', 'İzmir, Manisa')
            ->set('form.body', 'kısa')->call('create')->assertHasErrors('form.body')
            ->set('form.body', "## Plan\n\nİzmir ve Manisa’da form kampanyası.")->call('create')->assertHasNoErrors();

        $strategy = Strategy::query()->sole();
        $this->assertSame(['İzmir', 'Manisa'], $strategy->markets);
        $this->assertSame($this->admin->name, $strategy->versions()->value('author'));
    }
}
