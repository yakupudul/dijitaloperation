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
            ->assertSee('İçindekiler')->assertSee('id="bolum-1"', false)->assertSee('<table>', false)->assertSee('Zamanlama');
        $this->assertStringNotContainsString('<script>alert(1)</script>', $page->getContent());

        Livewire::actingAs($this->admin)->test(StrategyPage::class, ['strategyId' => $strategy->id])
            ->set('tab', 'dayanak')->assertSee('240 bin TL harcama')
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
            ->set('tab', 'kapsam')->call('startEdit')->set('scope.budget_amount', '2500')->set('scope.budget_period', 'daily')->call('saveScope')->assertSee('Kapsam kaydedildi');
        $this->assertSame('2.500 TRY günlük', $strategy->refresh()->budgetLabel());
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
