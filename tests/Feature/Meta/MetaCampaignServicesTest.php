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
use App\Models\MetaLeadForm;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Meta\MetaAnalysis;
use App\Services\Meta\MetaCampaignBoard;
use App\Services\Meta\MetaCampaignServices;
use App\Services\Meta\MetaScreen;
use App\Services\Site\Analysis\SiteRange;
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

    public function test_older_meta_steps_read_the_stored_services(): void
    {
        $asset = $this->asset->load('brand');
        $screen = app(MetaScreen::class);
        $entities = fn (): array => $screen->entities($screen->account($asset));
        $this->assertSame(['c1' => 'Diş İmplantı'], $screen->campaignServices($this->brand, $entities()), 'name match before anything is stored');

        $this->services()->confirm($asset, 'c2', $this->offering('Ortodonti')->id, $this->admin);
        $this->assertSame('Ortodonti', $screen->campaignServices($this->brand, $entities())['c2']);
        $this->services()->exclude($asset, 'c2', $this->admin);
        $this->services()->confirm($asset, 'c1', $this->offering('Zirkonyum Kaplama')->id, $this->admin, replace: true);
        $this->assertSame(['c1' => 'Zirkonyum Kaplama'], $screen->campaignServices($this->brand, $entities()), 'the operator wins; hizmet dışı has none');
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
        $this->assertSame(['amount' => 150.0, 'level' => 'campaign'], ['amount' => (float) $rows['c1']['budget']['amount'], 'level' => $rows['c1']['budget']['level']], 'the collector stores major units; no second division');
        $alerts = fn (string $id): array => array_column($rows[$id]['alerts'], 'key');
        $this->assertContains('cost_up', $alerts('c1'));
        $this->assertContains('fatigue', $alerts('c1'));
        $this->assertContains('disapproved', $alerts('c2'));
        $this->assertContains('no_service', $alerts('c2'));
        $this->assertNotContains('no_service', $alerts('c1'));
        $implant = collect($board['by_service'])->firstWhere('name', 'Diş İmplantı');
        $this->assertSame([1, 'leads', 56.0, 50.0], [$implant['campaigns'], $implant['type'], (float) $implant['results'], (float) $implant['cpr']], 'services carry their campaigns and main result');
    }

    public function test_the_date_picker_range_drives_the_board_the_analysis_and_the_campaign_page(): void
    {
        $asset = $this->asset->load('brand');
        $custom = SiteRange::from(28, '2026-10-20', '2026-10-29');
        $board = app(MetaCampaignBoard::class)->board($asset, $custom);
        $this->assertSame(['from' => '2026-10-20', 'to' => '2026-10-29', 'prev_from' => '2026-10-10', 'prev_to' => '2026-10-19'], $board['window']);
        $this->assertSame(1500.0, (float) $board['kpis']['spend']['value'], '10 days × 150');

        $year = app(MetaCampaignBoard::class)->board($asset, SiteRange::from(7, null, null, SiteRange::COMPARE_YEAR));
        $this->assertSame(['2026-10-23', '2026-10-29', '2025-10-23', '2025-10-29'], array_values($year['window']), 'last 7 days up to the last collected day, against a year earlier');
        $this->assertSame('2025-10-23', app(MetaAnalysis::class)->analysis($asset, SiteRange::from(7, null, null, SiteRange::COMPARE_YEAR), '', 'year')['window']['cmp_from']);

        $page = Livewire::actingAs($this->admin)->test(OverviewPage::class, ['assetId' => (string) $this->asset->id])->assertSeeHtml('data-date-picker')
            ->call('setRange', 28, '2026-10-20', '2026-10-29')->assertSet('days', 10)->assertSet('start', '2026-10-20')->assertSee('20 Eki – 29 Eki 2026')
            ->assertSeeHtml('bas=2026-10-20');
        $page->call('setTab', 'analysis')->assertSeeHtml('data-date-picker')->call('setTab', 'todo')->assertDontSeeHtml('data-date-picker');

        Livewire::withQueryParams(['bas' => '2026-10-20', 'bit' => '2026-10-29'])->actingAs($this->admin)
            ->test(CampaignPage::class, ['assetId' => (string) $this->asset->id, 'campaignId' => 'c1'])
            ->assertSet('days', 10)->assertSee('20 Eki – 29 Eki 2026')
            ->call('setRange', 7, '', '', 'year')->assertSet('start', '')->assertSet('compare', 'year')->assertSee('Son 7 gün');
    }

    public function test_campaigns_tab_filters_and_confirms_a_suggestion(): void
    {
        $this->services()->sync($this->asset->load('brand'));
        $implant = $this->offering('Diş İmplantı');
        $page = Livewire::actingAs($this->admin)->test(OverviewPage::class, ['assetId' => (string) $this->asset->id]);

        $page->assertSet('tab', 'campaigns')->assertSet('status', 'all')->assertSee('Hizmetlere göre')->assertSee('Diş İmplantı Lead Ankara')->assertSee('Genel Trafik')->assertSee('+ Hizmet ata')
            ->set('service', 'none')->assertDontSee('Diş İmplantı Lead Ankara')->assertSee('Genel Trafik')
            ->set('service', '')->set('search', 'implant')->assertSee('Diş İmplantı Lead Ankara')->assertDontSee('Genel Trafik')
            ->call('confirmService', 'c1', $implant->id);
        $this->assertSame('confirmed', $this->services()->map($this->asset)['c1']['state']);
    }

    public function test_short_ad_set_names_find_the_service_and_lead_ad_sets_show_the_form_and_their_budget(): void
    {
        $service = app(ServiceCatalogService::class)->resolveOrCreate('Diş Beyazlatma Uygulaması', 'dental', actor: $this->admin)['service'];
        $whitening = BrandOffering::query()->create(['brand_id' => $this->brand->id, 'service_catalog_item_id' => $service->id, 'status' => 'active', 'priority' => 'secondary', 'locked' => true]);
        $this->snapshot('meta_campaign_snapshot', ['campaign_id' => 'c3'], ['name' => 'Q MEDIA - GURBETÇİ KİTLE ADS 2', 'objective' => 'OUTCOME_LEADS', 'effective_status' => 'ACTIVE']);
        $this->snapshot('meta_adset_snapshot', ['adset_id' => 'as3'], ['name' => 'Q MEDIA - GURBETÇİ DİŞ BEYAZLATMA RS', 'campaign_id' => 'c3', 'optimization_goal' => 'LEAD_GENERATION',
            'destination_type' => 'ON_AD', 'effective_status' => 'ACTIVE', 'daily_budget' => '1250.000000']);
        $this->snapshot('meta_creative_snapshot', ['creative_id' => 'cr3'], ['name' => 'Beyazlatma', 'title' => '', 'body' => '']);
        $this->professional('meta_ad_snapshot', ['ad_id' => 'ad3', 'ad_name' => 'GURBETÇİ R1 N', 'campaign_id' => 'c3', 'adset_id' => 'as3', 'creative_id' => 'cr3', 'effective_status' => 'ACTIVE']);
        $this->daily('ad3', 'c3', 'as3', '2026-10-20', 300, 1000, 10, 800);

        $this->services()->sync($this->asset->load('brand'));
        $entry = $this->services()->map($this->asset)['c3'];
        $this->assertSame([$whitening->id], array_column($entry['services'], 'id'));
        $this->assertStringContainsString('dis beyazlatma', $entry['services'][0]['reason']);

        $rows = array_column(app(MetaCampaignBoard::class)->board($this->asset, 28)['rows'], null, 'id');
        $this->assertSame(1250.0, (float) $rows['c3']['budget']['amount'], 'ad set budgets summed in lira, as in Ads Manager');
        $this->actingAs($this->admin)->get(route('operator.meta.campaign', ['assetId' => $this->asset->id, 'campaignId' => 'c3']))->assertOk()
            ->assertSee('Anında form')->assertDontSee('Bağlantı yok')->assertSee('1.250,00 TRY');
    }

    public function test_ad_page_shows_its_texts_the_form_questions_the_whatsapp_number_and_its_numbers(): void
    {
        $this->snapshot('meta_campaign_snapshot', ['campaign_id' => 'c3'], ['name' => 'Q MEDIA - GURBETÇİ KİTLE ADS 2', 'objective' => 'OUTCOME_LEADS', 'effective_status' => 'ACTIVE']);
        $this->snapshot('meta_adset_snapshot', ['adset_id' => 'as3'], ['name' => 'GURBETÇİ İMPLANT RS', 'campaign_id' => 'c3', 'optimization_goal' => 'LEAD_GENERATION',
            'destination_type' => 'ON_AD', 'effective_status' => 'ACTIVE', 'daily_budget' => '1250.000000']);
        $this->snapshot('meta_adset_snapshot', ['adset_id' => 'as4'], ['name' => 'GURBETÇİ WHATSAPP RS', 'campaign_id' => 'c3', 'optimization_goal' => 'CONVERSATIONS',
            'destination_type' => 'WHATSAPP', 'effective_status' => 'ACTIVE']);
        $this->snapshot('meta_creative_snapshot', ['creative_id' => 'cr3'], ['name' => 'Form', 'title' => 'Ücretsiz muayene', 'body' => 'Gurbetçilere özel implant.',
            'description' => 'Tatilde tedavi', 'call_to_action_type' => 'SIGN_UP', 'lead_gen_form_id' => '5001', 'post_id' => '111_222',
            'variants' => ['bodies' => ['Gurbetçilere özel implant.', 'Almanya’dan gelene ücretsiz muayene.'], 'titles' => [], 'descriptions' => []]]);
        $this->snapshot('meta_creative_snapshot', ['creative_id' => 'cr4'], ['name' => 'WA', 'body' => 'Yazın, hemen dönelim.', 'call_to_action_type' => 'WHATSAPP_MESSAGE',
            'whatsapp_number' => '+90 555 000 00 00', 'welcome_message' => 'Merhaba, size nasıl yardımcı olabiliriz?']);
        $this->professional('meta_ad_snapshot', ['ad_id' => 'ad3', 'ad_name' => 'GURBETÇİ İMPLANT R1', 'campaign_id' => 'c3', 'adset_id' => 'as3', 'creative_id' => 'cr3', 'effective_status' => 'ACTIVE']);
        $this->professional('meta_ad_snapshot', ['ad_id' => 'ad5', 'ad_name' => 'GURBETÇİ İMPLANT R2', 'campaign_id' => 'c3', 'adset_id' => 'as3', 'creative_id' => 'cr3', 'effective_status' => 'PAUSED']);
        $this->professional('meta_ad_snapshot', ['ad_id' => 'ad4', 'ad_name' => 'GURBETÇİ WHATSAPP R1', 'campaign_id' => 'c3', 'adset_id' => 'as4', 'creative_id' => 'cr4', 'effective_status' => 'ACTIVE']);
        $this->daily('ad3', 'c3', 'as3', '2026-10-20', 300, 1000, 20, 800);
        MetaLeadForm::query()->create(['account_id' => '777', 'form_id' => '5001', 'name' => 'Gurbetçi implant formu', 'locale' => 'tr_TR',
            'questions' => [['type' => 'FULL_NAME', 'label' => 'Ad soyad', 'options' => []], ['type' => 'CUSTOM', 'label' => 'Ne zaman Türkiye’desiniz?', 'options' => ['Bu ay', 'Yazın']]],
            'thank_you' => ['title' => 'Teşekkürler', 'body' => 'Sizi arayacağız.'], 'fetched_at' => now()]);

        $this->actingAs($this->admin)->get(route('operator.meta.campaign', ['assetId' => $this->asset->id, 'campaignId' => 'c3']))->assertOk()
            ->assertSee(route('operator.meta.campaign-ad', ['assetId' => $this->asset->id, 'campaignId' => 'c3', 'adId' => 'ad3', 'gun' => 28]), false)->assertSee('WhatsApp');

        $this->actingAs($this->admin)->get(route('operator.meta.campaign-ad', ['assetId' => $this->asset->id, 'campaignId' => 'c3', 'adId' => 'ad3']))->assertOk()
            ->assertSee('Gurbetçilere özel implant.')->assertSee('Almanya’dan gelene ücretsiz muayene.')->assertSee('Kaydol')->assertSee('Tatilde tedavi')
            ->assertSee('Gurbetçi implant formu')->assertSee('Ne zaman Türkiye’desiniz?')->assertSee('Yazın')->assertSee('Teşekkür ekranı')
            ->assertSee('Formu dolduranların bilgileri Moximu’ya alınmaz')->assertSee('https://www.facebook.com/111_222')
            ->assertSee('GURBETÇİ İMPLANT R2')->assertSee('300,00 TRY');
        $this->actingAs($this->admin)->get(route('operator.meta.campaign-ad', ['assetId' => $this->asset->id, 'campaignId' => 'c3', 'adId' => 'ad4']))->assertOk()
            ->assertSee('+90 555 000 00 00')->assertSee('https://wa.me/905550000000', false)->assertSee('Merhaba, size nasıl yardımcı olabiliriz?')->assertDontSee('Anında form');
        $this->actingAs($this->admin)->get(route('operator.meta.campaign-ad', ['assetId' => $this->asset->id, 'campaignId' => 'c1', 'adId' => 'ad3']))->assertNotFound();
        $this->assertSame(0, ExternalWriteAction::query()->count());
    }

    public function test_campaign_page_shows_its_parts_and_takes_service_decisions(): void
    {
        $this->services()->sync($this->asset->load('brand'));
        $ortho = $this->offering('Ortodonti');

        $this->actingAs($this->admin)->get(route('operator.meta.campaign', ['assetId' => $this->asset->id, 'campaignId' => 'c1']))->assertOk()
            ->assertSee('Diş İmplantı Lead Ankara')->assertSee('İmplant Ankara 35+')->assertSee('İmplant video reklamı')->assertSee('Reklam metninde')
            ->assertSee('data-testid="meta-campaign-analysis"', false)->assertSee('Meta ilgi alanına göre sonuç vermez');
        $this->actingAs($this->admin)->get(route('operator.meta.campaign', ['assetId' => $this->asset->id, 'campaignId' => 'yok']))->assertNotFound();

        Livewire::actingAs($this->admin)->test(CampaignPage::class, ['assetId' => (string) $this->asset->id, 'campaignId' => 'c1'])
            ->assertSet('focus', 'campaign:c1')->set('compare', 'bogus')->assertSet('compare', 'prev')
            ->set('focus', '')->assertRedirect(route('operator.meta.overview', ['assetId' => $this->asset->id, 'tab' => 'analysis', 'odak' => '', 'days' => 28]));
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
