<?php

namespace Tests\Feature\Analyst;

use App\Ai\Agents\Analyst\ChannelAnalystAgent;
use App\Enums\CustomerStatus;
use App\Jobs\DraftGoogleAdsAdCopyJob;
use App\Jobs\ExecuteExternalWriteJob;
use App\Livewire\Operator\Workspace\GoogleAdsTab;
use App\Models\AdvisorItem;
use App\Models\AnalystDecision;
use App\Models\AnalystRun;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\SearchQueryLibraryItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Advisor\AdvisorPlanRunner;
use App\Services\Analyst\AnalystDecisionStore;
use App\Services\Analyst\AnalystEngine;
use App\Services\Analyst\AnalystPack;
use App\Services\Analyst\AnalystWorkspace;
use App\Services\Analyst\GoogleAds\GoogleAdsAnalyst;
use App\Services\Analyst\GoogleAds\GoogleAdsFacts;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\SeoTasks\SeoText;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Roles;
use Carbon\Carbon;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** Brand workspace › Google Ads: per-account pack (two currencies), wasted spend, tracking first, cards and actions. */
final class GoogleAdsAnalystTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $try;

    private DigitalAsset $usd;

    private CoreExternalResource $tryResource;

    private CoreExternalResource $usdResource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-23 12:00:00', 'Europe/Istanbul'));
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.google.client_id' => 'test-client-id', 'moxdop.google.client_secret' => 'test-client-secret']);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Atlas Diş']);
        $this->brand->sectors()->attach(ServiceCategory::query()->where('code', 'dental')->value('id'));

        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE, 'config' => ['granted_scopes' => [GoogleScopes::ADWORDS]]]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => GoogleScopes::ADWORDS],
            'expires_at' => now()->addHour(),
        ]);
        [$this->try, $this->tryResource] = $this->account($integration, 'Atlas TR', '1112223333', 'TRY');
        [$this->usd, $this->usdResource] = $this->account($integration, 'Atlas Global', '4445556666', 'USD');
        $this->seedTry();
        $this->seedUsd();
    }

    public function test_pack_keeps_accounts_apart_and_durum_numbers_come_from_stored_data(): void
    {
        $pack = app(GoogleAdsAnalyst::class)->buildPack($this->brand);

        $this->assertTrue($pack->hasData());
        $this->assertSame(['TRY', 'USD'], $pack->context['currencies']);
        $this->assertFalse($pack->context['tracking_broken']);
        $stats = collect($pack->stats)->keyBy('id');
        $this->assertSame(['spend_28d', 'conversions_28d', 'cpa_28d', 'wasted_spend_28d', 'lost_impression_share', 'tracking'], $stats->keys()->all());
        $this->assertNull($stats['spend_28d']['value'], 'two currencies are never added');
        $this->assertSame('₺4.200 · $560', $stats['spend_28d']['display']);
        $this->assertSame([84.0, 200], [$stats['conversions_28d']['value'], $stats['conversions_28d']['delta_pct']]);
        $this->assertSame('hesap bazında', $stats['cpa_28d']['note']);
        $this->assertSame('₺600 · $80', $stats['wasted_spend_28d']['display'], 'competitor + banned + irrelevant non-converting terms');
        $this->assertSame(['%20 / %30', 20, 30], [$stats['lost_impression_share']['display'], $stats['lost_impression_share']['budget'], $stats['lost_impression_share']['rank']]);
        $this->assertSame(['OK', 0], [$stats['tracking']['display'], $stats['tracking']['value']]);

        $a = $this->try->id;
        $acct = $pack->fact('acct:'.$a);
        $this->assertSame(['TRY', 4200.0, 2800.0, 50, 56.0, 75.0, 600.0, 14], [$acct['currency'], $acct['cost'], $acct['cost_prev'], $acct['cost_delta_pct'], $acct['conversions'], $acct['cpa'], $acct['wasted'], $acct['wasted_share_pct']]);
        $this->assertSame(['USD', 560.0, 80.0], [$pack->fact('acct:'.$this->usd->id)['currency'], $pack->fact('acct:'.$this->usd->id)['cost'], $pack->fact('acct:'.$this->usd->id)['wasted']]);
        $campaign = $pack->fact('camp:'.$a.':c1');
        $this->assertSame(['Implant Arama', 'SEARCH', 'TARGET_CPA', 60.0, 50, 20, 30, true], [$campaign['name'], $campaign['type'], $campaign['bidding'], $campaign['target_cpa'], $campaign['impression_share'], $campaign['lost_is_budget'], $campaign['lost_is_rank'], $campaign['budget_limited']]);

        $competitor = $pack->fact(GoogleAdsFacts::termRef($a, 'rakipdent implant'));
        $this->assertSame(['rakip marka', 300.0, 0.0, ['Implant Arama']], [$competitor['list'], $competitor['cost'], $competitor['conversions'], $competitor['campaigns']]);
        $this->assertSame('yasaklı', $pack->fact(GoogleAdsFacts::termRef($a, 'diş hekimi iş ilanı'))['list']);
        $this->assertSame('alakasız', $pack->fact(GoogleAdsFacts::termRef($a, 'diş macunu önerisi'))['list']);
        $this->assertSame('hizmet', $pack->fact(GoogleAdsFacts::termRef($a, 'ankara implant'))['list'], 'relevant non-converting term is not waste');
        $this->assertSame(600.0, $pack->fact('acct:'.$a)['wasted']);
        $this->assertSame('rakip marka', $pack->fact(GoogleAdsFacts::termRef($this->usd->id, 'competitor dental'))['list']);
        $opportunity = collect($pack->sections()['keyword_opportunities'])->firstWhere('text', 'implant fiyatları');
        $this->assertSame(['Implant Arama', 'Implant Genel', 10.0], [$opportunity['campaign'], $opportunity['ad_group'], $opportunity['conversions']]);
        $this->assertSame('POOR', $pack->fact('ad:'.$a.':ag1')['ad_strength']);
        $this->assertNotNull($pack->fact('kw:'.$a.':ag1:k1'));
        $this->assertTrue($pack->has('conv:'.$a.':ca1'));
        $this->assertLessThan(AnalystPack::DEFAULT_TOKEN_BUDGET, $pack->tokens());
    }

    public function test_one_currency_sums_money_with_change_and_cpa(): void
    {
        CoreAssetBinding::query()->where('digital_asset_id', $this->usd->id)->update(['status' => 'inactive']);

        $stats = collect(app(GoogleAdsAnalyst::class)->buildPack($this->brand)->stats)->keyBy('id');

        $this->assertSame([4200.0, '₺4.200', 50], [$stats['spend_28d']['value'], $stats['spend_28d']['display'], $stats['spend_28d']['delta_pct']]);
        $this->assertSame([75.0, '₺75', -25], [$stats['cpa_28d']['value'], $stats['cpa_28d']['display'], $stats['cpa_28d']['delta_pct']]);
        $this->assertSame([600.0, '%14 harcamanın'], [$stats['wasted_spend_28d']['value'], $stats['wasted_spend_28d']['note']]);
    }

    public function test_broken_tracking_is_always_the_first_card(): void
    {
        // Conversions stop for the last 14 days while clicks continue.
        DB::table('google_ads_campaign_daily')->where('customer_id', '1112223333')->where('reporting_date', '>=', now('Europe/Istanbul')->subDays(14)->toDateString())->update(['conversions' => 0]);
        $analyst = app(GoogleAdsAnalyst::class);
        $pack = $analyst->buildPack($this->brand);
        $a = $this->try->id;

        $this->assertTrue($pack->context['tracking_broken']);
        $this->assertSame(['Sorun', true], [collect($pack->stats)->firstWhere('id', 'tracking')['display'], collect($pack->stats)->firstWhere('id', 'tracking')['broken']]);
        $this->assertSame('critical', $pack->fact('trk:'.$a.':silent_14d')['severity']);

        $negatives = ['key' => 'negatives:acct:'.$a, 'title_tr' => 'Rakip marka terimlerini negatife ekle', 'why_tr' => 'Rakip marka terimi 300 TL harcadı, dönüşüm yok.', 'priority' => 1, 'effort' => 'low',
            'impact' => ['estimate' => '₺300/ay', 'basis' => 'terim'], 'evidence_refs' => [GoogleAdsFacts::termRef($a, 'rakipdent implant')], 'action' => ['type' => 'add_negatives', 'params' => ['target' => 'acct:'.$a]]];
        $result = $analyst->validate([$negatives], $pack);

        $this->assertSame(['fix_tracking', 'add_negatives'], array_map(fn (array $d): string => $d['action']['type'], $result['kept']), 'rule code adds the tracking card first');
        $this->assertSame([1, 2], array_column($result['kept'], 'priority'));
        $this->assertSame('trk:'.$a.':silent_14d', $result['kept'][0]['action']['params']['target']);
        $this->assertLessThanOrEqual(160, mb_strlen($result['kept'][0]['why_tr']));
    }

    public function test_ai_cards_are_validated_stored_rendered_and_the_actions_work(): void
    {
        $runner = app(AdvisorPlanRunner::class);
        $runner->run($runner->queue($this->try, $this->admin)->id);
        $weak = AdvisorItem::query()->where('rule_id', 'weak-ad-strength')->firstOrFail();
        $this->enableAi();
        $a = $this->try->id;
        $competitor = GoogleAdsFacts::termRef($a, 'rakipdent implant');
        $banned = GoogleAdsFacts::termRef($a, 'diş hekimi iş ilanı');
        $kwo = collect(app(GoogleAdsAnalyst::class)->buildPack($this->brand)->sections()['keyword_opportunities'])->keys()->first();
        $card = fn (string $key, string $title, string $why, string $type, string $target, array $refs, int $priority = 2): array => ['key' => $key, 'title_tr' => $title, 'why_tr' => $why,
            'priority' => $priority, 'effort' => 'low', 'impact' => ['estimate' => '', 'basis' => ''], 'evidence_refs' => $refs, 'action' => ['type' => $type, 'params' => ['target' => $target]]];
        ChannelAnalystAgent::fake([['decisions' => [
            $card('negatives:acct:'.$a, 'Rakip ve yasaklı terimleri negatife ekle', 'Rakip terim 28 günde 300 TL harcadı ve hiç dönüşüm getirmedi.', 'add_negatives', 'acct:'.$a, [$competitor, $banned], 1),
            $card('editor:acct:'.$a, 'Dönüşen terimi anahtar kelime yap, RSA ekle', 'Terim 10 dönüşüm getirdi ama anahtar kelime değil.', 'export_editor', 'acct:'.$a, [$kwo, $competitor, 'ad:'.$a.':ag1']),
            $card('copy:ad:'.$a.':ag1', 'Implant Genel reklam metnini güçlendir', 'Reklam gücü POOR, reklam grubu 28 günde harcıyor.', 'draft_ad_copy', 'ad:'.$a.':ag1', ['ad:'.$a.':ag1']),
            $card('budget:camp:'.$a.':c1', 'Implant Arama bütçe kaybını incele', 'Kampanya gösterimlerin %20 kadarını bütçe yüzünden kaçırıyor.', 'open_campaign', 'camp:'.$a.':c1', ['camp:'.$a.':c1']),
            $card('wrong-account', 'Yanlış hesap', 'Rakip terim 300 TL harcadı.', 'add_negatives', 'acct:'.$this->usd->id, [$competitor]),
            $card('pause', 'Kampanyayı durdur', 'Kampanya 4200 harcadı.', 'pause_campaign', 'camp:'.$a.':c1', ['camp:'.$a.':c1']),
            $card('invented', 'Uydurma', 'Terim 98765 TL harcadı.', 'open_campaign', 'camp:'.$a.':c1', ['camp:'.$a.':c1']),
        ]]]);

        $run = app(AnalystEngine::class)->queue($this->brand, 'google_ads', $this->admin);

        $this->assertSame(AnalystRun::DONE, $run->fresh()->status);
        $this->assertSame([7, 4], [$run->fresh()->decisions_received, $run->fresh()->decisions_kept]);
        $reasons = array_column($run->fresh()->dropped, 'reason', 'key');
        $this->assertSame('negatif aday kanıtı yok', $reasons['wrong-account']);
        $this->assertStringContainsString('izin verilmeyen aksiyon', $reasons['pause']);
        $this->assertStringContainsString('98765', $reasons['invented']);

        $negCard = AnalystDecision::query()->where('action_type', 'add_negatives')->sole();
        $page = $this->actingAs($this->admin)->get(route('operator.brand', ['brand' => $this->brand->id, 'tab' => 'google_ads']))->assertOk()
            ->assertSee('data-workspace-tab="google_ads"', false)->assertSee('Harcama (28g)')->assertSee('Boşa harcama (28g)')->assertSee('Dönüşüm takibi')
            ->assertSee('Rakip ve yasaklı terimleri negatife ekle')->assertSee('Rakip terim 28 günde 300 TL harcadı ve hiç dönüşüm getirmedi.')->assertDontSee('Uydurma');
        $page->assertSee(e(route('operator.brand', ['brand' => $this->brand->id, 'tab' => 'google_ads', 'neg' => $negCard->id])), false);
        $page->assertSee(e(route('operator.google-ads.overview', ['assetId' => $a, 'tab' => 'campaigns', 'campaign' => 'c1'])), false);

        // add_negatives: the Admin reviews the list and approves → one queued, undoable ADR-064 write.
        Queue::fake();
        $operator = User::factory()->create(['is_active' => true]);
        $operator->assignRole(Roles::TEAM_MEMBER);
        Livewire::actingAs($operator)->withQueryParams(['neg' => (string) $negCard->id])->test(GoogleAdsTab::class, ['brandId' => $this->brand->id])
            ->assertSee('Admin onayı gerekli')->call('sendNegatives')->assertSet('noticeTone', 'error');
        $this->assertSame(0, ExternalWriteAction::query()->count(), 'only an Admin approves external writes');
        $tab = Livewire::actingAs($this->admin)->withQueryParams(['neg' => (string) $negCard->id])->test(GoogleAdsTab::class, ['brandId' => $this->brand->id])
            ->assertSet('negDecisionId', $negCard->id)->assertSee('data-negative-review', false);
        $this->assertSame("[rakipdent implant]\n[diş hekimi iş ilanı]", $tab->get('negLines'));
        $tab->call('sendNegatives')->assertSet('noticeTone', 'success')->assertSet('negDecisionId', null);
        $write = ExternalWriteAction::query()->sole();
        $this->assertSame(['google_ads', 'negative_list_add', 'queued', $a, $negCard->id], [$write->channel, $write->action, $write->status, (int) $write->digital_asset_id, $write->request_payload['analyst_decision_id']]);
        $this->assertSame([['text' => 'rakipdent implant', 'match_type' => 'EXACT'], ['text' => 'diş hekimi iş ilanı', 'match_type' => 'EXACT']], $write->request_payload['keywords']);
        Queue::assertPushed(ExecuteExternalWriteJob::class);
        $write->forceFill(['status' => 'succeeded', 'result' => ['added' => [['text' => 'rakipdent implant', 'match_type' => 'EXACT', 'resource_name' => 'customers/1/sharedCriteria/1~2']]]])->save();
        $tab->call('undoNegativeWrite', $write->id)->assertSet('noticeTone', 'success');
        $this->assertSame('undoing', $write->fresh()->status);

        // draft_ad_copy: the advisor's AI copy draft of that ad group (1 AI call, queued).
        $copyCard = AnalystDecision::query()->where('action_type', 'draft_ad_copy')->sole();
        $this->assertSame($weak->id, $copyCard->action_params['_target']['advisor_item_id']);
        Livewire::actingAs($this->admin)->test(GoogleAdsTab::class, ['brandId' => $this->brand->id])->call('runDecisionAction', $copyCard->id)->assertSet('noticeTone', 'success');
        Queue::assertPushed(DraftGoogleAdsAdCopyJob::class);
        $this->assertSame('queued', $weak->fresh()->draft_status);

        // export_editor: the card's items as a Google Ads Editor file (negative, new keyword, the ready RSA draft).
        $weak->forceFill(['draft_status' => 'ready', 'draft' => ['headlines' => ['Atlas Diş İmplant', 'Garantili implant', 'Randevu alın'], 'descriptions' => ['Uzman kadro ile implant tedavisi.'], 'path1' => 'implant', 'path2' => '']])->save();
        $editor = AnalystDecision::query()->where('action_type', 'export_editor')->sole();
        $response = $this->get(route('operator.analyst.google-ads.editor', ['brand' => $this->brand->id, 'decision' => $editor->id]))->assertOk();
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        $file = mb_convert_encoding(substr((string) $response->getContent(), 2), 'UTF-8', 'UTF-16LE');
        $this->assertStringContainsString("Implant Arama\t\t\t\trakipdent implant\tCampaign Negative Exact", $file);
        $this->assertStringContainsString("Implant Arama\t\t\tImplant Genel\timplant fiyatları\tExact", $file);
        $this->assertStringContainsString('Responsive search ad', $file);
        $this->assertStringContainsString('Atlas Diş İmplant', $file);
        $this->assertStringContainsString('https://atlas.test/implant/', $file);
        $this->assertStringNotContainsString('Garantili', $file, 'sector rule lines are left out');
    }

    public function test_brand_without_ads_account_gets_one_line_and_no_ai_call(): void
    {
        $this->enableAi();
        ChannelAnalystAgent::fake()->preventStrayPrompts();
        $other = Brand::factory()->create(['customer_id' => $this->brand->customer_id, 'name' => 'Boş Klinik']);

        $run = app(AnalystEngine::class)->queue($other, 'google_ads', $this->admin);

        $this->assertSame([AnalystRun::SKIPPED, 'Veri yok: markaya bağlı Google Ads hesabı yok.'], [$run->fresh()->status, $run->fresh()->error]);
        ChannelAnalystAgent::assertNeverPrompted();
        Livewire::actingAs($this->admin)->test(GoogleAdsTab::class, ['brandId' => $other->id])->assertSee('Veri yok: markaya bağlı Google Ads hesabı yok.');

        CoreAssetBinding::query()->update(['status' => 'inactive']);
        $this->assertSame('Veri yok: Google Ads hesabı bağlı değil.', app(GoogleAdsFacts::class)->missing($this->brand));
    }

    public function test_persisted_card_keeps_its_evidence_for_the_actions(): void
    {
        $pack = app(GoogleAdsAnalyst::class)->buildPack($this->brand);
        $a = $this->try->id;
        $competitor = GoogleAdsFacts::termRef($a, 'rakipdent implant');
        $run = AnalystRun::query()->create(['brand_id' => $this->brand->id, 'channel' => 'google_ads', 'status' => 'running']);
        app(AnalystDecisionStore::class)->persist($run, [[
            'key' => 'neg', 'title_tr' => 'Negatif ekle', 'why_tr' => '300 TL boşa gitti.', 'priority' => 1, 'effort' => 'low', 'impact' => ['estimate' => '', 'basis' => ''],
            'evidence_refs' => [$competitor], 'action' => ['type' => 'add_negatives', 'params' => ['target' => 'acct:'.$a]],
        ]], $pack);
        $decision = AnalystDecision::query()->sole();

        $this->assertSame(['asset_id' => $a, 'lines' => '[rakipdent implant]', 'count' => 1], app(GoogleAdsAnalyst::class)->negativeLines($decision));
        $this->assertEquals(['rakip marka', 300], [$decision->evidence[0]['list'], $decision->evidence[0]['cost']]);
        $presented = app(AnalystWorkspace::class)->present($decision);
        $this->assertSame(['text', 'cost', 'conversions', 'clicks'], array_keys($presented['evidence']['columns']));
    }

    private function enableAi(): void
    {
        config(['moxdop.anthropic.api_key' => implode('-', ['sk', 'ant', 'fake', 'gads'])]);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
    }

    /** @return array{0: DigitalAsset, 1: CoreExternalResource} */
    private function account(CoreIntegration $integration, string $name, string $customerId, string $currency): array
    {
        $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'module_id' => 'google_ads', 'status' => 'active', 'name' => $name]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => GoogleResourceType::GOOGLE_ADS_CUSTOMER,
            'external_id' => $customerId, 'display_name' => $name, 'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['timezone' => 'Europe/Istanbul', 'currency' => $currency],
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id,
            'capability' => GoogleAdsSpecialistBindingResolver::CAPABILITY, 'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        return [$asset, $resource];
    }

    private function seedTry(): void
    {
        $r = $this->tryResource;
        $this->row($r, 'google_ads_account_snapshot', ['metadata' => json_encode(['descriptive_name' => 'Atlas TR', 'currency_code' => 'TRY', 'auto_tagging_enabled' => true])]);
        $this->row($r, 'google_ads_campaign_snapshot', ['campaign_id' => 'c1', 'metadata' => json_encode(['name' => 'Implant Arama', 'status' => 'ENABLED', 'advertising_channel_type' => 'SEARCH', 'budget_id' => 'b1', 'bidding_strategy_type' => 'TARGET_CPA', 'target_cpa_micros' => 60_000_000])]);
        $this->row($r, 'google_ads_campaign_budget_snapshot', ['budget_id' => 'b1', 'metadata' => json_encode(['amount' => '150'])]);
        for ($day = 1; $day <= 56; $day++) {
            $current = $day <= 28;
            $cost = $current ? 150 : 100;
            $this->row($r, 'google_ads_campaign_daily', ['reporting_date' => now('Europe/Istanbul')->subDays($day)->toDateString(), 'campaign_id' => 'c1', 'impressions' => 1000, 'clicks' => 30,
                'cost_micros' => $cost * 1_000_000, 'cost_amount' => $cost, 'conversions' => $current ? 2 : 1, 'currency' => 'TRY', 'search_impression_share' => '0.5',
                'metadata' => json_encode(['search_budget_lost_impression_share' => '0.2', 'search_rank_lost_impression_share' => '0.3'])]);
        }
        $term = fn (string $text, float $cost, int $clicks, int $conv) => $this->row($r, 'google_ads_search_term_daily', [
            'reporting_date' => now('Europe/Istanbul')->subDays(3)->toDateString(), 'search_term' => $text, 'impressions' => $clicks * 10, 'clicks' => $clicks,
            'cost_micros' => (int) ($cost * 1_000_000), 'cost_amount' => $cost, 'conversions' => $conv, 'currency' => 'TRY',
            'metadata' => json_encode(['source_view' => 'search_term_view', 'contexts' => [['campaign_id' => 'c1', 'ad_group_id' => 'ag1', 'status' => 'NONE', 'advertising_channel_type' => 'SEARCH']]]),
        ]);
        $term('rakipdent implant', 300, 20, 0);
        $term('diş hekimi iş ilanı', 200, 15, 0);
        $term('diş macunu önerisi', 100, 8, 0);
        $term('ankara implant', 150, 10, 0);
        $term('implant fiyatları', 900, 60, 10);
        $this->variant($r, 'rakipdent implant', 'competitor');
        $this->variant($r, 'diş hekimi iş ilanı', 'banned');
        $this->variant($r, 'diş macunu önerisi', 'core', $this->libraryItem('diş macunu önerisi', 'irrelevant'));
        $this->variant($r, 'ankara implant', 'core', $this->libraryItem('implant', 'matched'));

        $this->row($r, 'google_ads_keyword_snapshot', ['ad_group_id' => 'ag1', 'criterion_id' => 'k1', 'metadata' => json_encode(['keyword_text' => 'implant fiyat', 'match_type' => 'PHRASE', 'status' => 'ENABLED', 'campaign_id' => 'c1', 'quality_score' => 4, 'landing_page_experience' => 'BELOW_AVERAGE'])]);
        $this->row($r, 'google_ads_keyword_daily', ['reporting_date' => now('Europe/Istanbul')->subDays(2)->toDateString(), 'ad_group_id' => 'ag1', 'criterion_id' => 'k1', 'impressions' => 900, 'clicks' => 60, 'cost_micros' => 400_000_000, 'cost_amount' => 400, 'conversions' => 1, 'currency' => 'TRY', 'metadata' => json_encode(['keyword_text' => 'implant fiyat'])]);
        $this->row($r, 'google_ads_ad_group_snapshot', ['ad_group_id' => 'ag1', 'metadata' => json_encode(['name' => 'Implant Genel', 'campaign_id' => 'c1'])]);
        $this->row($r, 'google_ads_ad_snapshot', ['ad_id' => 'a1', 'metadata' => json_encode(['type' => 'RESPONSIVE_SEARCH_AD', 'status' => 'ENABLED', 'ad_strength' => 'POOR', 'final_urls' => ['https://atlas.test/implant/'], 'ad_group_id' => 'ag1', 'campaign_id' => 'c1'])]);
        $this->row($r, 'google_ads_conversion_action_snapshot', ['conversion_action_id' => 'ca1', 'metadata' => json_encode(['name' => 'Form', 'status' => 'ENABLED', 'category' => 'SUBMIT_LEAD_FORM', 'primary_for_goal' => true, 'counting_type' => 'ONE_PER_CLICK'])]);
    }

    private function seedUsd(): void
    {
        $r = $this->usdResource;
        $this->row($r, 'google_ads_campaign_snapshot', ['campaign_id' => 'd1', 'metadata' => json_encode(['name' => 'Dental Tourism', 'status' => 'ENABLED', 'advertising_channel_type' => 'SEARCH'])]);
        for ($day = 1; $day <= 28; $day++) {
            $this->row($r, 'google_ads_campaign_daily', ['reporting_date' => now('Europe/Istanbul')->subDays($day)->toDateString(), 'campaign_id' => 'd1', 'impressions' => 200, 'clicks' => 5,
                'cost_micros' => 20_000_000, 'cost_amount' => 20, 'conversions' => 1, 'currency' => 'USD', 'metadata' => '{}']);
        }
        $this->row($r, 'google_ads_search_term_daily', ['reporting_date' => now('Europe/Istanbul')->subDays(4)->toDateString(), 'search_term' => 'competitor dental', 'impressions' => 90, 'clicks' => 9,
            'cost_micros' => 80_000_000, 'cost_amount' => 80, 'conversions' => 0, 'currency' => 'USD',
            'metadata' => json_encode(['source_view' => 'search_term_view', 'contexts' => [['campaign_id' => 'd1', 'ad_group_id' => 'dg1', 'status' => 'NONE', 'advertising_channel_type' => 'SEARCH']]])]);
        $this->variant($r, 'competitor dental', 'competitor');
    }

    /** @param array<string, mixed> $values */
    private function row(CoreExternalResource $resource, string $table, array $values): void
    {
        DB::table($table)->insert($values + [
            'digital_asset_id' => null, 'external_resource_id' => $resource->id, 'customer_id' => $resource->external_id, 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'source_timezone' => 'Europe/Istanbul',
            'record_fingerprint' => hash('sha256', $table.$resource->id.json_encode($values)), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function variant(CoreExternalResource $resource, string $text, string $kind, ?SearchQueryLibraryItem $item = null): void
    {
        DB::table('query_variants')->insert([
            'source' => 'google_ads', 'external_resource_id' => $resource->id, 'source_key' => 'google_ads:r'.$resource->id, 'text_hash' => hash('sha256', $text), 'raw_text' => $text,
            'search_query_library_item_id' => $item?->id, 'kind' => $kind, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function libraryItem(string $text, string $status): SearchQueryLibraryItem
    {
        $item = SearchQueryLibraryItem::query()->create([
            'uuid' => (string) Str::uuid(), 'identity_hash' => hash('sha256', 'gads-test|'.$text), 'canonical_text' => $text, 'folded_text' => SeoText::fold($text),
            'sector' => 'dental', 'status' => 'active', 'is_branded' => false, 'normalization_version' => 'library_location_free_v2', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        DB::table('search_query_library_sectors')->insert(['search_query_library_item_id' => $item->id, 'service_category_id' => ServiceCategory::query()->where('code', 'dental')->value('id'),
            'match_status' => $status, 'created_at' => now(), 'updated_at' => now()]);

        return $item;
    }
}
