<?php

namespace Tests\Feature\Meta;

use App\Ai\Agents\MetaCampaignServicesAgent;
use App\Jobs\Meta\MatchMetaCampaignServicesJob;
use App\Livewire\Demo\Meta\OverviewPage;
use App\Livewire\Operator\Meta\AssignPage;
use App\Livewire\Operator\Meta\CampaignPage;
use App\Models\AdCampaignService;
use App\Models\AiTask;
use App\Models\BrandOffering;
use App\Models\CoreIntegration;
use App\Models\ExternalWriteAction;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Meta\MetaCampaignBoard;
use App\Services\Meta\MetaCampaignServices;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\SeedsMetaAccount;
use Tests\TestCase;

/**
 * Kampanya → hizmet: rule suggestions (page → text → name), the operator's decisions that no later pass overrides,
 * AI for what no rule matched, the Kampanyalar board, the campaign page and Eşleşmeyenleri ata. Nothing goes to Meta.
 */
class MetaCampaignServicesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMetaAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        Http::preventStrayRequests();
        config(['moxdop.anthropic.api_key' => 'test-anthropic-value', 'moxdop-mcp.token' => '']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $this->seedMetaAccount();
    }

    private function offering(string $name): BrandOffering
    {
        $id = collect(app(MetaCampaignServices::class)->offerings($this->brand))->firstWhere('name', $name)['id'];

        return BrandOffering::query()->findOrFail($id);
    }

    private function services(): MetaCampaignServices
    {
        return app(MetaCampaignServices::class);
    }

    public function test_rules_suggest_from_the_page_first_then_the_ad_text_and_leave_the_rest_for_ai(): void
    {
        $implant = $this->offering('Diş İmplantı');
        $result = $this->services()->sync($this->asset->load('brand'));

        $this->assertSame(['campaigns' => 2, 'matched' => 1, 'unmatched' => ['c2']], $result);
        $map = $this->services()->map($this->asset);
        $this->assertSame('suggested', $map['c1']['state']);
        $this->assertSame([$implant->id], array_column($map['c1']['services'], 'id'));
        $this->assertSame('text', $map['c1']['services'][0]['source']);
        $this->assertArrayNotHasKey('c2', $map);

        // A page that carries the service wins over the text.
        $page = Page::query()->where('path', '/implant/')->sole();
        OfferingPage::query()->create(['brand_offering_id' => $implant->id, 'page_id' => $page->id, 'source' => 'manual', 'locked' => true]);
        $this->services()->sync($this->asset);
        $entry = $this->services()->map($this->asset)['c1'];
        $this->assertSame('page', $entry['services'][0]['source']);
        $this->assertStringContainsString('/implant/', $entry['services'][0]['reason']);
        $this->assertSame(1, AdCampaignService::query()->where('campaign_id', 'c1')->count());
    }

    public function test_operator_decisions_survive_the_next_rule_pass_and_undo_runs_the_rules_again(): void
    {
        $asset = $this->asset->load('brand');
        $implant = $this->offering('Diş İmplantı');
        $zirconia = $this->offering('Zirkonyum Kaplama');
        $this->services()->sync($asset);

        $this->services()->confirm($asset, 'c1', $zirconia->id, $this->admin);
        $this->services()->sync($asset);
        $entry = $this->services()->map($asset)['c1'];
        $this->assertSame('confirmed', $entry['state']);
        $this->assertSame([[$zirconia->id, 'confirmed'], [$implant->id, 'suggested']], array_map(fn (array $s): array => [$s['id'], $s['status']], $entry['services']));

        $this->services()->remove($asset, 'c1', $implant->id, $this->admin);
        $this->services()->sync($asset);
        $this->assertSame([$zirconia->id], array_column($this->services()->map($asset)['c1']['services'], 'id'), 'a removed service is not suggested again');

        $this->services()->confirm($asset, 'c2', $implant->id, $this->admin, replace: true);
        $this->services()->exclude($asset, 'c2', $this->admin);
        $this->services()->sync($asset);
        $this->assertSame(['state' => 'excluded', 'services' => []], $this->services()->map($asset)['c2']);

        $this->services()->reopen($asset, 'c1');
        $entry = $this->services()->map($asset)['c1'];
        $this->assertSame(['suggested', [$implant->id]], [$entry['state'], array_column($entry['services'], 'id')]);
        $this->assertSame(0, ExternalWriteAction::query()->count());
    }

    public function test_bulk_approval_confirms_only_undecided_suggestions(): void
    {
        $asset = $this->asset->load('brand');
        $this->services()->sync($asset);

        $this->assertSame(1, $this->services()->confirmSuggestions($asset, ['c1', 'c2'], $this->admin));
        $this->assertSame('confirmed', $this->services()->map($asset)['c1']['state']);
        $this->assertSame(0, $this->services()->confirmSuggestions($asset, ['c1'], $this->admin));
    }

    public function test_ai_suggests_only_known_services_for_campaigns_no_rule_matched(): void
    {
        $asset = $this->asset->load('brand');
        $ortho = $this->offering('Ortodonti');
        $this->services()->sync($asset);
        MetaCampaignServicesAgent::fake([['matches' => [
            ['campaign_id' => 'c2', 'offering_ids' => [$ortho->id, 99999], 'reason' => 'Trafik kampanyası ortodonti sayfasına benziyor.'],
            ['campaign_id' => 'c1', 'offering_ids' => [$ortho->id], 'reason' => 'Sorulmayan kampanya.'],
            ['campaign_id' => 'yok', 'offering_ids' => [$ortho->id], 'reason' => 'Uydurma.'],
        ]]]);

        (new MatchMetaCampaignServicesJob($asset->id))->handle($this->services(), app(AiTaskQueue::class));

        MetaCampaignServicesAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, 'Genel Trafik') && ! str_contains((string) $prompt->prompt, 'Diş İmplantı Lead Ankara'));
        $entry = $this->services()->map($asset)['c2'];
        $this->assertSame([[$ortho->id, 'ai']], array_map(fn (array $s): array => [$s['id'], $s['source']], $entry['services']));
        $this->assertSame([$this->offering('Diş İmplantı')->id], array_column($this->services()->map($asset)['c1']['services'], 'id'));
        $this->assertSame('ready', Cache::get(MetaCampaignServices::stateKey($asset->id))['status']);

        $this->services()->sync($asset);
        $this->assertSame('ai', $this->services()->map($asset)['c2']['services'][0]['source'], 'a rule pass keeps an AI suggestion while no rule matches');
    }

    public function test_with_claude_on_the_job_waits_in_the_queue_instead_of_calling_a_provider(): void
    {
        config(['moxdop-mcp.token' => 'test-mcp-token']);
        $asset = $this->asset->load('brand');
        $this->services()->sync($asset);
        MetaCampaignServicesAgent::fake()->preventStrayPrompts();

        (new MatchMetaCampaignServicesJob($asset->id))->handle($this->services(), app(AiTaskQueue::class));

        $this->assertSame('running', Cache::get(MetaCampaignServices::stateKey($asset->id))['status']);
        $this->assertSame(1, AiTask::query()->where('operation', 'meta.campaign_services')->count());
    }

    public function test_board_lists_campaigns_with_budget_results_and_alerts(): void
    {
        $asset = $this->asset->load('brand');
        $this->services()->sync($asset);
        $board = app(MetaCampaignBoard::class)->board($asset, 28);

        $this->assertTrue($board['bound']);
        $this->assertSame(4200.0, (float) $board['kpis']['spend']['value']);
        $this->assertSame(56.0, (float) $board['kpis']['leads']['value']);
        $rows = array_column($board['rows'], null, 'id');
        $this->assertSame(['leads', 56.0, 50.0], [$rows['c1']['type'], (float) $rows['c1']['results'], (float) $rows['c1']['cpr']]);
        $this->assertSame(['amount' => 1.5, 'level' => 'campaign'], ['amount' => (float) $rows['c1']['budget']['amount'], 'level' => $rows['c1']['budget']['level']]);
        $alerts = fn (string $id): array => array_column($rows[$id]['alerts'], 'key');
        $this->assertContains('cost_up', $alerts('c1'));
        $this->assertContains('fatigue', $alerts('c1'));
        $this->assertContains('disapproved', $alerts('c2'));
        $this->assertContains('no_service', $alerts('c2'));
        $this->assertNotContains('no_service', $alerts('c1'));
    }

    public function test_campaigns_tab_filters_and_confirms_a_suggestion(): void
    {
        $this->services()->sync($this->asset->load('brand'));
        $implant = $this->offering('Diş İmplantı');
        $page = Livewire::actingAs($this->admin)->test(OverviewPage::class, ['assetId' => (string) $this->asset->id]);

        $page->assertSet('tab', 'campaigns')->assertSee('Diş İmplantı Lead Ankara')->assertSee('Genel Trafik')->assertSee('+ Hizmet ata')
            ->set('service', 'none')->assertDontSee('Diş İmplantı Lead Ankara')->assertSee('Genel Trafik')
            ->set('service', '')->set('search', 'implant')->assertSee('Diş İmplantı Lead Ankara')->assertDontSee('Genel Trafik')
            ->call('confirmService', 'c1', $implant->id);
        $this->assertSame('confirmed', $this->services()->map($this->asset)['c1']['state']);
    }

    public function test_campaign_page_shows_its_parts_and_takes_service_decisions(): void
    {
        $this->services()->sync($this->asset->load('brand'));
        $ortho = $this->offering('Ortodonti');

        $this->actingAs($this->admin)->get(route('operator.meta.campaign', ['assetId' => $this->asset->id, 'campaignId' => 'c1']))->assertOk()
            ->assertSee('Diş İmplantı Lead Ankara')->assertSee('İmplant Ankara 35+')->assertSee('İmplant video reklamı')->assertSee('Reklam metninde');
        $this->actingAs($this->admin)->get(route('operator.meta.campaign', ['assetId' => $this->asset->id, 'campaignId' => 'yok']))->assertNotFound();

        Livewire::actingAs($this->admin)->test(CampaignPage::class, ['assetId' => (string) $this->asset->id, 'campaignId' => 'c1'])
            ->set('addOffering', (string) $ortho->id)->call('addService')
            ->call('exclude')->assertSee('Hizmet dışı')
            ->call('reopen');
        $this->assertSame('suggested', $this->services()->map($this->asset)['c1']['state']);
        $this->assertSame(0, ExternalWriteAction::query()->count());
    }

    public function test_assign_page_approves_picks_excludes_undoes_and_asks_ai(): void
    {
        Queue::fake();
        $ortho = $this->offering('Ortodonti');
        $page = Livewire::actingAs($this->admin)->test(AssignPage::class, ['assetId' => (string) $this->asset->id]);

        $page->assertSet('selected', ['c1'])->assertSee('Genel Trafik')->assertSee('/implant/')->assertSee('Önerisi olmayanları AI ile eşleştir (1)')
            ->call('approveSelected')->assertSee('onaylandı');
        $this->assertSame('confirmed', $this->services()->map($this->asset)['c1']['state']);

        $page->set('pick.c2', (string) $ortho->id)->call('choose', 'c2');
        $this->assertSame([$ortho->id], array_column($this->services()->map($this->asset)['c2']['services'], 'id'));
        $page->call('undo', 'c2')->call('exclude', 'c2');
        $this->assertSame('excluded', $this->services()->map($this->asset)['c2']['state']);
        $page->call('undo', 'c2')->call('askAi');
        Queue::assertPushed(MatchMetaCampaignServicesJob::class);
        $this->assertSame('running', Cache::get(MetaCampaignServices::stateKey($this->asset->id))['status']);
    }
}
