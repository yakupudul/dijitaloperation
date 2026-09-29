<?php

namespace Tests\Feature\Analyst;

use App\Ai\Agents\Analyst\ChannelAnalystAgent;
use App\Enums\CustomerStatus;
use App\Jobs\WriteContentArticleJob;
use App\Livewire\Operator\Portfolio\BrandShow;
use App\Livewire\Operator\Workspace\SearchTab;
use App\Models\AiProduction;
use App\Models\AnalystDecision;
use App\Models\AnalystRun;
use App\Models\Brand;
use App\Models\BrandDemandQuery;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\IntelligenceCore\IntelligencePageIdentity;
use App\Models\IntelligenceProjection\WebsiteIntelligenceProjectionRun;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Models\ServiceCategory;
use App\Models\TopicCluster;
use App\Models\User;
use App\Models\WebsiteUrlVerdict;
use App\Services\Analyst\AnalystDecisionStore;
use App\Services\Analyst\AnalystEngine;
use App\Services\Analyst\AnalystPack;
use App\Services\Analyst\AnalystRegistry;
use App\Services\Analyst\Search\SearchAnalyst;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\SearchDemand\ServiceCatalogService;
use App\Services\SeoTasks\SeoText;
use App\Support\Ai\AiRouteKeys;
use App\Support\Ai\AiRouteRegistry;
use App\Support\OperatorMenu;
use App\Support\Roles;
use App\Support\ServiceScope;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Step 3: brand workspace, shared AI analyst engine, Arama tab and the lean menu. */
final class BrandWorkspaceAnalystTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $site;

    private BrandOffering $implant;

    private BrandOffering $aligner;

    private BrandServiceArea $cankaya;

    private BrandServiceArea $kecioren;

    private TopicCluster $nutrition;

    private TopicCluster $braces;

    private TopicCluster $strengthen;

    private int $libraryCluster;

    private string $verdictHash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Panorama Ankara']);
        $this->brand->sectors()->attach(ServiceCategory::query()->where('code', 'dental')->value('id'));
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'status' => 'active', 'module_id' => 'website',
            'name' => 'panorama.test', 'domain' => 'panorama.test', 'primary_url' => 'https://panorama.test/']);
        $offerings = app(BrandOfferingService::class);
        $this->implant = $offerings->create($this->brand, 'İmplant Tedavisi');
        $this->aligner = $offerings->create($this->brand, 'Şeffaf Plak');
        $catalogAligner = app(ServiceCatalogService::class)->resolveOrCreate('Şeffaf Plak', 'dental', actor: $this->admin)['service'];
        $this->aligner->forceFill(['service_catalog_item_id' => $catalogAligner->id])->save();
        $this->cankaya = $this->area('Çankaya', 1);
        $this->kecioren = $this->area('Keçiören', 2);

        $this->page('/', 'Panorama Ankara Diş', 'Panorama');
        $this->page('/implant-tedavisi/', 'İmplant Tedavisi | Panorama', 'İmplant Tedavisi');
        $this->page('/kecioren-seffaf-plak/', 'Keçiören Şeffaf Plak | Panorama', 'Keçiören Şeffaf Plak');

        // Core queries (brand view of the one store): 3 service queries, 1 branded, 1 irrelevant, 1 unclear.
        $this->hub('implant tedavisi', $this->implant, 1200, 3.0, $this->cankaya);
        $this->hub('implant fiyatı', $this->implant, 800, 14.0);
        $this->hub('şeffaf plak', $this->aligner, 450, 8.0);
        $this->hub('panorama ankara', null, 900, 1.0, branded: true);
        $this->hub('diş hekimi maaşı', null, 300, null, relevance: 'irrelevant');
        $this->hub('ağız kokusu', null, 100, null, relevance: 'unclear');

        // Search Console: 10 clicks/day for the last 28 days, 5/day for the 28 before (+%100).
        for ($day = 1; $day <= 56; $day++) {
            DB::table('gsc_property_daily')->insert([
                'digital_asset_id' => $this->site->id, 'external_resource_id' => null, 'site_url' => 'sc-domain:panorama.test',
                'reporting_date' => now()->subDays($day)->toDateString(), 'clicks' => $day <= 28 ? 10 : 5, 'impressions' => 400,
                'search_type' => 'web', 'metadata' => '{}', 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
                'record_fingerprint' => hash('sha256', 'g'.$day), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->nutrition = $this->topic('İmplant sonrası beslenme', $this->implant, 'new', 'uncovered', 600);
        $this->braces = $this->topic('Şeffaf plak mı tel mi', $this->aligner, 'new', 'uncovered', 90);
        $this->strengthen = $this->topic('İmplant tedavisi süreci', $this->implant, 'strengthen', 'weak', 700, 'https://panorama.test/implant-tedavisi/');
        $this->topic('Zaten iyi konu', $this->implant, 'none', 'covered', 50);

        $this->libraryCluster = (int) DB::table('library_query_clusters')->insertGetId([
            'service_id' => $catalogAligner->id, 'name' => 'Şeffaf plak fiyatları', 'name_key' => 'seffaf plak fiyatlari', 'status' => 'active', 'revision' => 1,
            'page_decision' => 'hizmet', 'decision_source' => 'serp', 'head_query' => 'şeffaf plak', 'serp_evidence' => json_encode(['counts' => ['hizmet' => 6, 'blog' => 2]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->verdictHash = $this->verdict('/implant-tedavisi/', 'fix', 42, 3.2, [
            ['source' => 'standard', 'id' => 'website:url:indexable', 'rule' => 'Sayfa dizine açık', 'state' => 'fail', 'severity' => 'high'],
            ['source' => 'standard', 'id' => 'website:url:title_length', 'rule' => 'Başlık uzunluğu', 'state' => 'fail', 'severity' => 'medium'],
        ]);
        $this->verdict('/kecioren-seffaf-plak/', 'fix', 3, 18.0, [
            ['source' => 'standard', 'id' => 'website:url:indexable', 'rule' => 'Sayfa dizine açık', 'state' => 'fail', 'severity' => 'high'],
        ]);
    }

    public function test_search_pack_and_durum_numbers_come_from_stored_data(): void
    {
        $pack = app(SearchAnalyst::class)->buildPack($this->brand);

        $this->assertTrue($pack->hasData());
        $stats = collect($pack->stats)->keyBy('id');
        $this->assertSame([280, 100], [$stats['organic_clicks']['value'], $stats['organic_clicks']['delta_pct']]);
        $this->assertSame([50, '2/4'], [$stats['service_area_coverage']['value'], $stats['service_area_coverage']['note']], 'implant × Çankaya ranks 3, Keçiören aligner page exists');
        $this->assertSame([67, 2, 3], [$stats['query_coverage']['value'], $stats['query_coverage']['ranking'], $stats['query_coverage']['total']], 'branded / irrelevant / unclear are not core queries');
        $this->assertSame(1, $stats['technical_blockers']['value'], 'one critical standard failing (on 2 URLs); medium ignored');
        $this->assertSame(3, $stats['content_opportunities']['value'], 'new + strengthen topics');

        foreach (['svc:'.$this->implant->id, 'area:'.$this->cankaya->id, 'cov:'.$this->implant->id.':'.$this->cankaya->id, 'tc:'.$this->nutrition->id,
            'lc:'.$this->libraryCluster, SearchAnalyst::urlRef($this->verdictHash), 'std:website:url:indexable', 'inv:site', 'stat:organic_clicks'] as $ref) {
            $this->assertTrue($pack->has($ref), $ref);
        }
        $this->assertSame('rank', $pack->fact('cov:'.$this->implant->id.':'.$this->cankaya->id)['status']);
        $this->assertSame('page', $pack->fact('cov:'.$this->aligner->id.':'.$this->kecioren->id)['status']);
        $this->assertSame(2, $pack->fact('std:website:url:indexable')['urls']);
        $queries = collect($pack->sections()['queries'])->pluck('text')->all();
        $this->assertEqualsCanonicalizing(['implant tedavisi', 'implant fiyatı', 'şeffaf plak'], $queries);
        $this->assertLessThan(AnalystPack::DEFAULT_TOKEN_BUDGET, $pack->tokens());
    }

    public function test_validation_drops_invented_numbers_unknown_refs_disallowed_actions_and_long_or_non_compliant_text(): void
    {
        $pack = app(SearchAnalyst::class)->buildPack($this->brand);
        $tc = 'tc:'.$this->nutrition->id;
        $valid = ['key' => 'content:'.$tc, 'title_tr' => 'Beslenme rehberi yaz', 'why_tr' => 'Konu 600 gösterim alıyor ama sayfası yok.', 'priority' => 1, 'effort' => 'medium',
            'impact' => ['estimate' => '+30 tık', 'basis' => 'gösterim'], 'evidence_refs' => [$tc], 'action' => ['type' => 'prepare_article', 'params' => ['target' => $tc]]];

        $result = app(SearchAnalyst::class)->validate([
            $valid,
            ['key' => 'a'] + array_replace($valid, ['why_tr' => 'Konu 9999 gösterim alıyor.']),
            ['key' => 'b'] + array_replace($valid, ['evidence_refs' => ['tc:999999']]),
            ['key' => 'c'] + array_replace($valid, ['action' => ['type' => 'pause_campaign', 'params' => ['target' => $tc]]]),
            ['key' => 'd'] + array_replace($valid, ['action' => ['type' => 'prepare_article', 'params' => ['target' => 'svc:'.$this->implant->id]]]),
            ['key' => 'e'] + array_replace($valid, ['why_tr' => 'Konu 600 gösterim alıyor. Sayfası da yok.']),
            ['key' => 'f'] + array_replace($valid, ['why_tr' => 'Konu 600 gösterim alıyor '.str_repeat('ve sayfası yok ', 12)]),
            ['key' => 'g'] + array_replace($valid, ['title_tr' => 'Garantili implant sayfası yaz']),
            ['key' => 'h'] + array_replace($valid, ['why_tr' => 'Konu çok arama alıyor.']),
            $valid,
        ], $pack);

        $this->assertSame(['content:'.$tc], array_column($result['kept'], 'key'));
        $reasons = array_column($result['dropped'], 'reason', 'key');
        $this->assertStringContainsString('9999', $reasons['a']);
        $this->assertStringContainsString('bilinmeyen kanıt', $reasons['b']);
        $this->assertStringContainsString('izin verilmeyen aksiyon', $reasons['c']);
        $this->assertSame('aksiyon hedefi bu aksiyona uymuyor', $reasons['d']);
        $this->assertSame('gerekçe tek cümle değil', $reasons['e']);
        $this->assertStringContainsString('160', $reasons['f']);
        $this->assertSame('sektör kuralına aykırı başlık', $reasons['g']);
        $this->assertSame('gerekçede sayı yok', $reasons['h']);
        $this->assertSame('tekrar eden karar', $result['dropped'][8]['reason']);
    }

    public function test_decisions_persist_by_fingerprint_and_closed_ones_do_not_come_back_unless_changed(): void
    {
        $pack = app(SearchAnalyst::class)->buildPack($this->brand);
        $store = app(AnalystDecisionStore::class);
        $decision = fn (string $key, string $target, string $type = 'prepare_article'): array => ['key' => $key, 'title_tr' => 'Yaz: '.$key, 'why_tr' => '600 gösterim var.', 'priority' => 2,
            'impact' => ['estimate' => '', 'basis' => ''], 'effort' => 'low', 'evidence_refs' => [$target], 'action' => ['type' => $type, 'params' => ['target' => $target]]];
        $run = fn (): AnalystRun => AnalystRun::query()->create(['brand_id' => $this->brand->id, 'channel' => 'search', 'status' => 'running']);
        $a = 'tc:'.$this->nutrition->id;
        $b = 'tc:'.$this->braces->id;

        $store->persist($run(), [$decision('k-a', $a), $decision('k-b', $b), $decision('k-c', 'tc:'.$this->strengthen->id, 'prepare_page_update')], $pack);
        $this->assertSame(3, AnalystDecision::query()->where('status', 'open')->count());
        $cardA = AnalystDecision::query()->where('decision_key', 'k-a')->sole();
        $this->assertSame($this->nutrition->label, $cardA->action_params['_target']['label']);
        $store->markDone($cardA, $this->admin);
        $store->dismiss(AnalystDecision::query()->where('decision_key', 'k-b')->sole(), $this->admin);
        $this->assertSame(280, $cardA->fresh()->baseline['metric']['organic_clicks_28d'], 'baseline stored for the outcome follow-up');

        // Same decisions again (k-c not proposed any more): closed stay closed, k-c expires.
        $store->persist($run(), [$decision('k-a', $a), $decision('k-b', $b)], $pack);
        $this->assertSame(['k-a' => 'done', 'k-b' => 'dismissed', 'k-c' => 'expired'], AnalystDecision::query()->orderBy('decision_key')->pluck('status', 'decision_key')->all());
        $this->assertSame(3, AnalystDecision::query()->count(), 'one row per fingerprint');

        // Material change (other action) reopens the dismissed one.
        $store->persist($run(), [$decision('k-a', $a), $decision('k-b', $b, 'open_content_studio_idea')], $pack);
        $this->assertSame('done', AnalystDecision::query()->where('decision_key', 'k-a')->value('status'));
        $this->assertSame('open', AnalystDecision::query()->where('decision_key', 'k-b')->value('status'));

        // Snoozed: hidden until the date.
        $card = AnalystDecision::query()->where('decision_key', 'k-b')->sole();
        $store->snooze($card, 7);
        $this->assertSame(0, AnalystDecision::query()->actionable()->count());
        $this->travel(8)->days();
        $this->assertSame(1, AnalystDecision::query()->actionable()->count());
    }

    public function test_ai_decisions_are_stored_rendered_as_cards_and_buttons_work(): void
    {
        $this->enableAi();
        $tcA = 'tc:'.$this->nutrition->id;
        $tcB = 'tc:'.$this->braces->id;
        $url = SearchAnalyst::urlRef($this->verdictHash);
        ChannelAnalystAgent::fake([['decisions' => [
            ['key' => 'article:'.$tcA, 'title_tr' => 'İmplant sonrası beslenme yazısı hazırla', 'why_tr' => 'Konu 600 gösterim alıyor ama sayfası yok.', 'priority' => 1, 'effort' => 'medium',
                'impact' => ['estimate' => '+40 tık/ay', 'basis' => 'gösterim × sıra'], 'evidence_refs' => [$tcA], 'action' => ['type' => 'prepare_article', 'params' => ['target' => $tcA]]],
            ['key' => 'studio:'.$tcB, 'title_tr' => 'Şeffaf plak mı tel mi karşılaştırması aç', 'why_tr' => '90 gösterimlik karşılaştırma sorusunun sayfası yok.', 'priority' => 2, 'effort' => 'low',
                'impact' => ['estimate' => '+10 tık/ay', 'basis' => 'gösterim'], 'evidence_refs' => [$tcB], 'action' => ['type' => 'open_content_studio_idea', 'params' => ['target' => $tcB]]],
            ['key' => 'fix:'.$url, 'title_tr' => 'İmplant sayfasının dizin sorununu düzelt', 'why_tr' => 'Sayfa 28 günde 42 tık getiriyor ve kritik standarttan kalıyor.', 'priority' => 1, 'effort' => 'low',
                'impact' => ['estimate' => 'tık kaybını önler', 'basis' => 'tık'], 'evidence_refs' => [$url, 'std:website:url:indexable'], 'action' => ['type' => 'open_fix', 'params' => ['target' => $url]]],
            ['key' => 'bad-number', 'title_tr' => 'Uydurma', 'why_tr' => 'Konu 12345 gösterim alıyor.', 'priority' => 1, 'effort' => 'low',
                'impact' => ['estimate' => '', 'basis' => ''], 'evidence_refs' => [$tcA], 'action' => ['type' => 'prepare_article', 'params' => ['target' => $tcA]]],
        ]]]);

        $run = app(AnalystEngine::class)->queue($this->brand, 'search', $this->admin);

        ChannelAnalystAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, 'INPUT_JSON') && str_contains((string) $prompt->prompt, $tcA));
        $this->assertSame(AnalystRun::DONE, $run->fresh()->status);
        $this->assertSame([4, 3], [$run->fresh()->decisions_received, $run->fresh()->decisions_kept]);
        $this->assertSame('bad-number', $run->fresh()->dropped[0]['key']);
        $this->assertSame(1, AiProduction::query()->where('kind', AiRouteKeys::ANALYST_SEARCH)->count(), 'archived like every AI production');

        $page = $this->actingAs($this->admin)->get(route('operator.brand', ['brand' => $this->brand->id]))->assertOk()
            ->assertSee('Bu hafta yapılacaklar')->assertSee('İmplant sonrası beslenme yazısı hazırla')->assertSee('Konu 600 gösterim alıyor ama sayfası yok.')
            ->assertSee('Organik tıklama (28g)')->assertSee('Hizmet × bölge')->assertDontSee('Uydurma');
        $studio = route('operator.website', ['assetId' => $this->site->id, 'tab' => 'studio', 'studio_cluster' => $this->braces->id]);
        $page->assertSee(e($studio), false);
        $page->assertSee(e(route('operator.website', ['assetId' => $this->site->id, 'tab' => 'scorecard', 'url_q' => '/implant-tedavisi/'])), false);
        foreach (AnalystDecision::query()->get() as $card) {
            $this->assertLessThanOrEqual(160, mb_strlen($card->why), 'no explanatory paragraphs');
            $this->assertSame(0, preg_match('/[.!?](\s+)\S/u', rtrim($card->why, '.')), 'one sentence');
        }

        // The studio deep link prepares the cluster's idea.
        $this->get($studio)->assertOk();

        // "Yazıyı hazırla" queues the article writer.
        Queue::fake();
        $article = AnalystDecision::query()->where('action_type', 'prepare_article')->sole();
        Livewire::actingAs($this->admin)->test(SearchTab::class, ['brandId' => $this->brand->id])
            ->call('runDecisionAction', $article->id)->assertSet('noticeTone', 'success')->assertSee('Yazı hazırlanıyor');
        Queue::assertPushed(WriteContentArticleJob::class);

        // Yapıldı / Gereksiz from the top list of the brand page.
        Livewire::actingAs($this->admin)->test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->call('markDecisionDone', $article->id)->call('dismissDecision', AnalystDecision::query()->where('action_type', 'open_fix')->value('id'));
        $this->assertSame(['open_content_studio_idea' => 'open', 'open_fix' => 'dismissed', 'prepare_article' => 'done'],
            AnalystDecision::query()->orderBy('action_type')->pluck('status', 'action_type')->all());
    }

    public function test_brands_without_search_console_are_skipped_with_one_line(): void
    {
        $this->enableAi();
        DB::table('gsc_property_daily')->delete();
        ChannelAnalystAgent::fake()->preventStrayPrompts();

        $run = app(AnalystEngine::class)->queue($this->brand, 'search', $this->admin);

        $this->assertSame([AnalystRun::SKIPPED, 'Veri yok: Search Console bağlı değil.'], [$run->fresh()->status, $run->fresh()->error]);
        ChannelAnalystAgent::assertNeverPrompted();
        Livewire::actingAs($this->admin)->test(SearchTab::class, ['brandId' => $this->brand->id])->assertSee('Veri yok: Search Console bağlı değil.');
    }

    public function test_weekly_schedule_and_operational_gating(): void
    {
        $events = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command);
        $this->assertTrue($events->contains(fn (string $c): bool => str_contains($c, 'moxdop:analyst:weekly')));
        $this->assertTrue(app(AiRouteRegistry::class)->has(AiRouteKeys::ANALYST_SEARCH));
        $live = app(AnalystRegistry::class)->liveChannels();
        foreach (['maps' => AiRouteKeys::ANALYST_MAPS, 'google_ads' => AiRouteKeys::ANALYST_GOOGLE_ADS, 'meta' => AiRouteKeys::ANALYST_META] as $channel => $route) {
            $this->assertSame(in_array($channel, $live, true), app(AiRouteRegistry::class)->has($route), 'route registered only when the analyst exists: '.$channel);
        }

        $passive = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Inactive])->id, 'name' => 'Pasif Klinik']);
        Queue::fake();
        $result = app(AnalystEngine::class)->queueWeekly();

        $this->assertSame(['queued' => count($live), 'channels' => $live], $result, 'only the operational brand, only live channels');
        $this->assertSame([$this->brand->id], AnalystRun::query()->distinct()->pluck('brand_id')->all());
        $this->assertSame('weekly', AnalystRun::query()->value('trigger'));
        try {
            app(AnalystEngine::class)->queue($passive, 'search', $this->admin);
            $this->fail('passive brand must be refused');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Hizmet kapsamı dışında', (string) collect($exception->errors())->flatten()->first());
        }
        $this->actingAs($this->admin)->get(route('operator.brand', ['brand' => $passive->id]))->assertOk()->assertSee(ServiceScope::NOT_SERVED);
    }

    public function test_channel_tabs_show_hazirlaniyor_until_their_component_exists_and_settings_keep_the_old_page(): void
    {
        $this->actingAs($this->admin);
        foreach (['harita', 'google_ads', 'meta'] as $tab) {
            $expected = class_exists(BrandShow::WORKSPACE_TABS[$tab][1]);
            $response = $this->get(route('operator.brand', ['brand' => $this->brand->id, 'tab' => $tab]))->assertOk()->assertSee('Bu hafta yapılacaklar');
            $expected ? $response->assertDontSee('data-workspace-pending', false) : $response->assertSee('Hazırlanıyor');
        }
        $this->get(route('operator.brand', ['brand' => $this->brand->id]))->assertOk()->assertSee('data-workspace-tab="search"', false)
            ->assertSee('Arama')->assertSee('Harita')->assertSee('Google Ads')->assertSee('Meta')->assertSee('Ayarlar');
        $this->get(route('operator.brand', ['brand' => $this->brand->id, 'tab' => 'ayarlar']))->assertOk()->assertSee('Genel bakış')->assertSee('Otomatik kur')->assertSee('Dijital varlıklar');
        $this->get(route('operator.brand', ['brand' => $this->brand->id, 'tab' => 'business']))->assertOk()->assertSee('Hizmetler');
    }

    public function test_menu_has_only_the_six_jobs_and_removed_screens_still_open(): void
    {
        $this->actingAs($this->admin);
        app()->setLocale('tr');
        $names = collect(OperatorMenu::groups())->flatMap(fn (array $g): array => array_column($g['items'], 'name'))->all();
        $this->assertSame(['Bugün', 'Markalar', 'Müşteriler', 'Sorgular', 'Entegrasyonlar', 'Ayarlar'], $names);
        $routes = collect(OperatorMenu::groups())->flatMap(fn (array $g): array => array_merge(...array_column($g['items'], 'routes')))->all();
        foreach (['operator.command-center', 'operator.portfolio.health', 'operator.ads_advisor', 'operator.seo_tasks', 'operator.content.calendar',
            'operator.library.search-demand-competitors', 'operator.brain.services', 'operator.leads', 'operator.prospects', 'operator.reports.monthly',
            'operator.agency', 'operator.renewals', 'operator.assets'] as $route) {
            $this->assertNotContains($route, $routes, $route);
        }
        $this->assertContains('operator.integrations.discovered', $routes);
        foreach (['operator.command-center', 'operator.portfolio.health', 'operator.reports.monthly', 'operator.assets', 'operator.content.calendar'] as $route) {
            $this->get(route($route))->assertOk();
        }

        // Bugün: operational brands with their top card and the per-channel counts.
        AnalystDecision::query()->create(['brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'x', 'fingerprint' => str_repeat('a', 64), 'material_hash' => str_repeat('b', 64),
            'title' => 'Beslenme yazısı hazırla', 'why' => 'Konu 600 gösterim alıyor.', 'priority' => 1, 'action_type' => 'prepare_article', 'status' => 'open', 'last_seen_at' => now()]);
        $this->get(route('operator.dashboard'))->assertOk()->assertSee('Bugün')->assertSee('Panorama Ankara')->assertSee('Beslenme yazısı hazırla')->assertSee('Arama 1');

        // Asset screens point to the workspace.
        $this->get(route('operator.website', ['assetId' => $this->site->id]))->assertOk()->assertSee('Analiz marka ekranında')
            ->assertSee(e(route('operator.brand', ['brand' => $this->brand->id, 'tab' => 'arama'])), false);
    }

    private function enableAi(): void
    {
        config(['moxdop.anthropic.api_key' => implode('-', ['sk', 'ant', 'fake', 'workspace'])]);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
    }

    private function area(string $district, int $rank): BrandServiceArea
    {
        return BrandServiceArea::query()->create(['brand_id' => $this->brand->id, 'country_code' => 'TR', 'country_name' => 'Türkiye', 'city_name' => 'Ankara',
            'district_name' => $district, 'normalized_key' => 'tr|ankara|'.SeoText::fold($district), 'status' => 'active', 'priority_rank' => $rank]);
    }

    private function hub(string $query, ?BrandOffering $offering, int $impressions, ?float $position, ?BrandServiceArea $area = null, bool $branded = false, string $relevance = 'relevant'): void
    {
        BrandDemandQuery::query()->create([
            'brand_id' => $this->brand->id, 'query' => $query, 'query_key' => hash('sha256', SeoText::fold($query)), 'brand_offering_id' => $offering?->id,
            'brand_service_area_id' => $area?->id, 'is_branded' => $branded, 'relevance' => $relevance, 'sources' => ['search_console'], 'source_mask' => 1,
            'gsc_impressions' => $impressions, 'gsc_clicks' => 5, 'gsc_position' => $position, 'value_score' => $impressions / 10,
        ]);
    }

    private function topic(string $label, BrandOffering $offering, string $verdict, string $coverage, int $impressions, ?string $owner = null): TopicCluster
    {
        return TopicCluster::query()->create([
            'brand_id' => $this->brand->id, 'digital_asset_id' => $this->site->id, 'brand_offering_id' => $offering->id, 'label' => $label, 'head_query' => mb_strtolower($label),
            'intent' => 'informational', 'page_type' => 'guide', 'demand_score' => $impressions, 'impressions' => $impressions, 'clicks' => 3, 'query_count' => 2,
            'owner_url' => $owner, 'coverage' => $coverage, 'verdict' => $verdict, 'verdict_detail' => ['missing_queries' => ['implant sonrası ne yenir']], 'status' => 'active',
        ]);
    }

    /** @param list<array<string, string>> $findings */
    private function verdict(string $path, string $verdict, int $clicks, float $position, array $findings): string
    {
        $url = 'https://panorama.test'.$path;
        $hash = hash('sha256', $url);
        WebsiteUrlVerdict::query()->create([
            'digital_asset_id' => $this->site->id, 'brand_id' => $this->brand->id, 'url_hash' => $hash, 'url_key' => SeoText::urlKey($url), 'url' => $url, 'path' => $path,
            'verdict' => $verdict, 'severity' => 'high', 'priority' => 100, 'reason' => 'Kritik standart hatası.', 'clicks' => $clicks, 'impressions' => 900, 'position' => $position,
            'key_events' => 2, 'indexed' => true, 'findings' => $findings, 'computed_at' => now(),
        ]);

        return $hash;
    }

    private function page(string $path, string $title, string $h1): void
    {
        $url = 'https://panorama.test'.$path;
        $identity = IntelligencePageIdentity::query()->create([
            'uuid' => (string) Str::uuid(), 'website_asset_id' => $this->site->id, 'identity_hash' => hash('sha256', $this->site->id.':'.$url), 'preferred_url' => $url,
            'preferred_url_hash' => hash('sha256', $url), 'scheme' => 'https', 'host' => 'panorama.test', 'path' => $path,
            'resolution_status' => 'resolved', 'normalization_version' => 'v1', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $projection = WebsiteIntelligenceProjectionRun::query()->create([
            'uuid' => (string) Str::uuid(), 'website_asset_id' => $this->site->id, 'trigger' => 'test', 'status' => 'completed',
            'schema_version' => 1, 'intelligence_registry_version' => 1, 'period_start' => now()->subDays(90), 'period_end' => now()->subDay(),
        ]);
        WebsitePageProfile::query()->create([
            'website_asset_id' => $this->site->id, 'page_identity_id' => $identity->id, 'projection_run_id' => $projection->id,
            'preferred_url' => $url, 'profile_version' => 1, 'projected_at' => now(), 'last_observed_at' => now(),
            'source_states' => ['website' => ['url' => $url, 'http' => ['status_code' => 200], 'content' => ['word_count' => 700, 'language' => 'tr'],
                'document_head' => ['title' => $title, 'title_present' => true, 'robots' => 'index'], 'headings' => ['h1' => $h1, 'h1_present' => true]]],
        ]);
    }
}
