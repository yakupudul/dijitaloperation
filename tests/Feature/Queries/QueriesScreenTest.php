<?php

namespace Tests\Feature\Queries;

use App\Ai\Agents\QueryClusterAgent;
use App\Ai\Agents\QueryRulesAgent;
use App\Jobs\Queries\RescanQueriesJob;
use App\Livewire\Operator\Library\QueriesPage;
use App\Models\Brand;
use App\Models\Cluster;
use App\Models\ClusterQuery;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\FilterTerm;
use App\Models\Query;
use App\Models\QueryReview;
use App\Models\QueryReviewItem;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\ServiceMatchingKeyword;
use App\Models\User;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Queries\ClusterEditor;
use App\Services\Queries\QueryPipeline;
use App\Services\Queries\QueryRuleProposer;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\InsertsFacts;
use Tests\TestCase;

/** MoxDOP v2 Faz 3: Sorgular screen, "AI ile kural üret", "AI ile kümele", cluster edits, filter basket, matching keywords. */
final class QueriesScreenTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    private User $admin;

    private ServiceCategory $dental;

    private ServiceCatalogItem $implant;

    private ServiceCatalogItem $zirkonyum;

    private CoreExternalResource $gsc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->dental = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş sağlığı', 'normalized_key' => 'dis sagligi']);
        $catalog = app(ServiceCatalogService::class);
        $this->implant = $catalog->resolveOrCreate('Diş İmplantı', 'dental', actor: $this->admin)['service'];
        $this->zirkonyum = $catalog->resolveOrCreate('Zirkonyum Kaplama', 'dental', actor: $this->admin)['service'];
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'sector_id' => $this->dental->id]);
        $site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website']);
        $this->gsc = CoreExternalResource::factory()->searchConsole()->create();
        CoreAssetBinding::factory()->create(['digital_asset_id' => $site->id, 'external_resource_id' => $this->gsc->id, 'capability' => 'search_console']);
        Http::preventStrayRequests();
    }

    public function test_screen_renders_every_tab_and_filters(): void
    {
        app(ServiceKeywordService::class)->replace($this->implant, 'implant');
        $this->sources(['implant fiyatları' => 300, 'diş taşı temizliği' => 20]);
        $cluster = Cluster::query()->create(['sector_id' => $this->dental->id, 'service_id' => $this->implant->id, 'name' => 'İmplant fiyatı', 'intent' => 'commercial', 'page_type' => 'service']);
        ClusterQuery::query()->create(['cluster_id' => $cluster->id, 'query_id' => $this->queryId('implant fiyatları')]);

        $this->get(route('operator.library.queries'))->assertOk()->assertSee('implant fiyatları')->assertSee('atanmamış')
            ->assertSee('AI ile planla')->assertSee('Bekleyenler')->assertSee('Filtreye ekle');

        Livewire::test(QueriesPage::class)
            ->set('service', '__none')->assertSee('diş taşı temizliği')->assertDontSee('implant fiyatları')
            ->set('service', (string) $this->implant->id)->assertSee('implant fiyatları')->assertSee('İmplant fiyatı')
            ->set('cluster', '__none')->assertDontSee('implant fiyatları')
            ->set('cluster', '')->set('search', 'FİYAT')->assertSee('implant fiyatları')
            ->call('setTab', 'clusters')->assertSee('İmplant fiyatı')->assertSee('ticari')->assertSee('hizmet')
            ->call('setTab', 'pending')->assertSee('Bekleyen sorgu yok')
            ->call('setTab', 'filters')->assertSee('Sepet boş')
            ->call('setTab', 'keywords')->assertSee('Sektör seçin')
            ->set('sector', (string) $this->dental->id)->assertSee('Zirkonyum Kaplama')->assertSee('implant');
    }

    public function test_bulk_assign_locks_and_hide_removes_from_list(): void
    {
        $this->sources(['diş taşı temizliği' => 20, 'kötü sorgu' => 5]);
        $ids = [$this->queryId('diş taşı temizliği')];

        Livewire::test(QueriesPage::class)
            ->set('selected', $ids)->set('bulkService', (string) $this->zirkonyum->id)->call('assignSelected')
            ->set('selected', [$this->queryId('kötü sorgu')])->call('hideSelected')
            ->assertDontSee('kötü sorgu');

        $query = Query::query()->find($ids[0]);
        $this->assertSame($this->zirkonyum->id, $query->service_id);
        $this->assertTrue($query->locked);
        $this->assertSame('manual', $query->assignment);
        $this->assertTrue(Query::query()->where('text', 'kötü sorgu')->value('hidden'));
        $this->assertSame(0, FilterTerm::query()->count(), 'hide does not touch the filter basket');

        app(QueryPipeline::class)->run();
        $this->assertSame($this->zirkonyum->id, Query::query()->find($ids[0])->service_id);
    }

    public function test_ai_rules_are_validated_approved_and_start_a_rescan_review(): void
    {
        $this->enableAi();
        $this->sources(['implant dentgroup' => 40, 'implant' => 60, 'zirkonyum diş' => 10, 'implant ağrısı' => 5]);
        $locked = $this->queryId('implant ağrısı');
        Query::query()->whereKey($locked)->update(['service_id' => $this->zirkonyum->id, 'assignment' => 'manual', 'locked' => true]);
        $prompts = [];
        QueryRulesAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = $prompt;

            return [
                'filter_terms' => [
                    ['term' => 'Dentgroup', 'sector_id' => null, 'reason' => 'Rakip adı'],
                    ['term' => 'nedir', 'sector_id' => null, 'reason' => 'soru kelimesi'],
                    ['term' => 'kızılay', 'sector_id' => null, 'reason' => 'sorgularda yok'],
                    ['term' => 'implant', 'sector_id' => 987654, 'reason' => 'bilinmeyen sektör'],
                ],
                'keywords' => [
                    ['service_id' => $this->implant->id, 'keyword' => 'implant', 'reason' => 'Hizmet adı'],
                    ['service_id' => $this->zirkonyum->id, 'keyword' => 'zirkonyum', 'reason' => 'Hizmet adı'],
                    ['service_id' => $this->zirkonyum->id, 'keyword' => 'implant', 'reason' => 'aynı sektörde ikinci kez'],
                    ['service_id' => 424242, 'keyword' => 'kaplama', 'reason' => 'bilinmeyen hizmet'],
                    ['service_id' => $this->implant->id, 'keyword' => 'fiyat', 'reason' => 'genel kelime'],
                ],
                'prompt_version' => QueryRulesAgent::PROMPT_VERSION,
            ];
        });

        $page = Livewire::test(QueriesPage::class)
            ->set('selected', [$this->queryId('implant dentgroup'), $this->queryId('zirkonyum diş')])
            ->call('proposeRules')
            ->assertSet('rulesOpen', true)
            ->assertSee('dentgroup')->assertDontSee('kızılay')->assertSee('Zirkonyum Kaplama');

        $this->assertCount(1, $prompts);
        $this->assertStringContainsString('implant dentgroup', $prompts[0]);
        $data = json_decode(substr($prompts[0], strlen("DATA_JSON\n")), true);
        $this->assertSame(['implant dentgroup', 'zirkonyum diş'], array_column($data['queries'], 'text'), 'only selected queries are sent');
        $this->assertContains('implant ağrısı', $data['library_sample'], 'the good library queries go as a sample');

        $page->set('pickTerms', [0 => true])->set('pickKeywords', [0 => true, 1 => true])->call('approveRules')
            ->assertSet('rulesOpen', false)->assertSee('1 filtre terimi · 2 eşleme kelimesi')->assertSee('tarama başladı');

        $this->assertSame('ai', FilterTerm::query()->where('term', 'dentgroup')->value('source'));
        $this->assertSame(['implant'], $this->implant->matchingKeywords()->pluck('normalized_key')->all());
        $this->assertSame(['zirkonyum'], $this->zirkonyum->matchingKeywords()->pluck('normalized_key')->all());
        $this->assertTrue(Query::query()->where('text', 'implant dentgroup')->exists(), 'nothing is deleted before the review is approved');
        $this->assertNull(Query::query()->where('text', 'implant')->value('service_id'), 'nothing is reassigned before approval');
        $review = QueryReview::query()->sole();
        $this->assertSame([QueryReview::READY, 1, 2], [$review->status, $review->deletions, $review->changes]);
        $this->assertSame('dentgroup', $review->items()->where('kind', QueryReviewItem::DELETE)->sole()->term);
        $this->assertFalse($review->items()->where('query_id', $locked)->exists(), 'locked assignment is not proposed');
    }

    public function test_ai_clustering_creates_clusters_flags_suggested_queries_and_keeps_locked_clusters_on_rerun(): void
    {
        $this->enableAi();
        app(ServiceKeywordService::class)->replace($this->implant, 'implant');
        $this->sources(['implant fiyatları' => 300, 'implant ücreti' => 100, 'implant sonrası ağrı' => 80, 'implant ağrısı ne kadar sürer' => 20]);
        // Search Console: the page Google shows for a topic's queries on the sector's site.
        foreach ([['İmplant Ücreti', 'https://klinik.test/implant', 50], ['implant fiyatları', 'https://klinik.test/implant', 90], ['implant fiyatları', 'https://klinik.test/blog', 10]] as [$query, $page, $impressions]) {
            $this->insertFacts('gsc_query_page_daily', ['digital_asset_id' => null, 'external_resource_id' => $this->gsc->id, 'site_url' => 'sc-domain:klinik.test', 'reporting_date' => now()->subDays(3)->toDateString(),
                'query' => $query, 'page' => $page, 'clicks' => 1, 'impressions' => $impressions, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
                'record_fingerprint' => hash('sha256', $query.$page), 'created_at' => now(), 'updated_at' => now()]);
        }
        $ids = fn (string ...$texts): array => array_map(fn (string $t): int => $this->queryId($t), $texts);
        $prompts = [];
        QueryClusterAgent::fake(function (string $prompt) use (&$prompts, $ids): array {
            $prompts[] = $prompt;
            if (count($prompts) === 1) {
                return ['clusters' => [
                    ['name' => 'İmplant fiyatı', 'intent' => 'commercial', 'page_type' => 'service', 'query_ids' => [...$ids('implant fiyatları', 'implant ücreti'), 999999],
                        'main_query_id' => 999999, 'representative_query_ids' => $ids('implant ücreti'), 'new_queries' => ['implant fiyatları 2026', 'İmplant Ücreti'],
                        'subtopics' => ['Fiyatı etkileyen durumlar'], 'reasoning' => 'Aynı fiyat ihtiyacı.',
                        'user_need' => '  İmplant tedavisinin fiyatını öğrenmek ', 'exclusions' => ['İmplant sonrası ağrı', '', 42, 'İmplant sonrası ağrı']],
                    ['name' => 'İmplant sonrası ağrı', 'intent' => 'informational', 'page_type' => 'guide', 'query_ids' => $ids('implant sonrası ağrı', 'implant fiyatları'),
                        'main_query_id' => null, 'representative_query_ids' => [], 'new_queries' => [], 'subtopics' => [], 'reasoning' => 'Bilgi.'],
                    ['name' => 'Uydurma', 'intent' => 'local', 'page_type' => 'location', 'query_ids' => [555555], 'main_query_id' => 555555,
                        'representative_query_ids' => [], 'new_queries' => ['ankara implant'], 'subtopics' => [], 'reasoning' => '-'],
                ], 'prompt_version' => QueryClusterAgent::PROMPT_VERSION];
            }

            return ['clusters' => [
                ['name' => 'Ağrı süresi', 'intent' => 'informational', 'page_type' => 'faq', 'query_ids' => $ids('implant ağrısı ne kadar sürer', 'implant sonrası ağrı'),
                    'main_query_id' => null, 'representative_query_ids' => [], 'new_queries' => [], 'subtopics' => [], 'reasoning' => 'SSS.'],
            ], 'prompt_version' => QueryClusterAgent::PROMPT_VERSION];
        });

        Livewire::test(QueriesPage::class)->set('service', (string) $this->implant->id)->call('clusterService')->assertSee('2 küme · 1 önerilen sorgu');

        $price = Cluster::query()->where('name', 'İmplant fiyatı')->sole();
        $this->assertSame($this->dental->id, $price->sector_id);
        $this->assertSame($this->queryId('implant fiyatları'), $price->main_query_id, 'invalid main id → top query');
        $this->assertSame([], $price->representative_query_ids, '"implant ücreti" is a variant inside the "implant fiyatları" topic, not a topic of its own');
        $topics = json_decode(substr($prompts[0], strlen("DATA_JSON\n")), true)['topics'];
        $this->assertSame(['implant fiyatları', 'implant sonrası ağrı', 'implant ağrısı ne kadar sürer'], array_column($topics, 'topic'), 'topics go to AI, not raw queries');
        $this->assertSame([['fiyat'], 2, 400, ['implant ücreti'], 'https://klinik.test/implant'],
            [$topics[0]['facets'], $topics[0]['variants'], $topics[0]['impressions'], $topics[0]['examples'], $topics[0]['google_url']]);
        $this->assertArrayNotHasKey('google_url', $topics[1]);
        $this->assertSame('İmplant tedavisinin fiyatını öğrenmek', $price->user_need);
        $this->assertSame(['İmplant sonrası ağrı'], $price->exclusions, 'trimmed, strings only, unique');
        $this->assertNull(Cluster::query()->where('name', 'İmplant sonrası ağrı')->value('user_need'), 'missing need → null');
        $suggested = Query::query()->where('text', 'implant fiyatları 2026')->sole();
        $this->assertTrue($suggested->is_suggested);
        $this->assertSame(0, $suggested->impressions);
        $this->assertTrue(ClusterQuery::query()->where('query_id', $suggested->id)->value('is_suggested'));
        $this->assertSame(3, $price->clusterQueries()->count(), 'two real + one suggested; "İmplant Ücreti" normalizes to an existing query');
        $pain = Cluster::query()->where('name', 'İmplant sonrası ağrı')->sole();
        $this->assertSame($ids('implant sonrası ağrı'), $pain->clusterQueries()->pluck('query_id')->all(), 'a query is in one cluster only');
        $this->assertFalse(Cluster::query()->where('name', 'Uydurma')->exists());
        $this->assertFalse(Query::query()->where('text', 'ankara implant')->exists());
        $this->get(route('operator.library.queries', ['service' => $this->implant->id]))->assertSee('önerilen');

        app(ClusterEditor::class)->rename($price, 'İmplant fiyatları');
        Livewire::test(QueriesPage::class)->set('service', (string) $this->implant->id)->call('clusterService');

        $this->assertStringNotContainsString('implant ücreti', $prompts[1], 'locked cluster queries are not sent again');
        $this->assertStringContainsString('İmplant fiyatları', $prompts[1]);
        $this->assertSame(3, Cluster::query()->whereKey($price->id)->sole()->clusterQueries()->count(), 'locked cluster untouched');
        $this->assertTrue(Query::query()->whereKey($suggested->id)->exists(), 'suggested query of a locked cluster stays');
        $this->assertFalse(Cluster::query()->whereKey($pain->id)->exists(), 'unlocked cluster replaced');
        $this->assertSame(['Ağrı süresi', 'İmplant fiyatları'], Cluster::query()->orderBy('name')->pluck('name')->all());
    }

    public function test_cluster_split_merge_move_approve_and_delete_lock_the_clusters(): void
    {
        $this->sources(['a1' => 50, 'a2' => 40, 'a3' => 30, 'b1' => 20]);
        foreach (['a1', 'a2', 'a3', 'b1'] as $text) {
            Query::query()->where('text', $text)->update(['service_id' => $this->implant->id]);
        }
        $a = $this->cluster('A', ['a1', 'a2', 'a3']);
        $b = $this->cluster('B', ['b1']);
        $suggested = Query::query()->create(['text' => 'b önerilen', 'text_hash' => hash('sha256', 'b önerilen'), 'service_id' => $this->implant->id, 'is_suggested' => true]);
        ClusterQuery::query()->create(['cluster_id' => $b->id, 'query_id' => $suggested->id, 'is_suggested' => true]);

        $page = Livewire::test(QueriesPage::class)->set('tab', 'clusters')->set('service', (string) $this->implant->id)
            ->call('openCluster', $a->id)->assertSee('a3')
            ->set('selectedClusterQueries', [$this->queryId('a1')])->set('splitName', 'A-bir')->call('splitCluster');
        $split = Cluster::query()->where('name', 'A-bir')->sole();
        $this->assertTrue($split->locked);
        $this->assertSame($this->queryId('a1'), $split->main_query_id);
        $this->assertSame($this->queryId('a2'), $a->refresh()->main_query_id, 'main query moved away → next best');
        $this->assertTrue($a->locked);

        $page->set('selectedClusterQueries', [$this->queryId('a3')])->set('moveTarget', (string) $b->id)->call('moveQueries');
        $this->assertSame($b->id, ClusterQuery::query()->where('query_id', $this->queryId('a3'))->value('cluster_id'));
        $this->assertTrue($b->refresh()->locked);

        $page->set('mergeIds', [(string) $split->id])->call('mergeClusters');
        $this->assertFalse(Cluster::query()->whereKey($split->id)->exists());
        $this->assertSame([$this->queryId('a1'), $this->queryId('a2')], ClusterQuery::query()->where('cluster_id', $a->id)->orderBy('query_id')->pluck('query_id')->all());

        $page->call('approveCluster')->set('clusterForm.name', 'A son')->call('saveCluster');
        $a->refresh();
        $this->assertTrue($a->approved);
        $this->assertSame('A son', $a->name);

        $page->call('openCluster', $b->id)->call('deleteCluster');
        $this->assertFalse(Cluster::query()->whereKey($b->id)->exists());
        $this->assertFalse(Query::query()->whereKey($suggested->id)->exists(), 'suggested query goes with its cluster');
        $this->assertTrue(Query::query()->where('text', 'b1')->exists(), 'real queries stay (kümesiz)');
    }

    public function test_filter_basket_and_matching_keywords_are_edited_on_the_screen(): void
    {
        app(ServiceKeywordService::class)->replace($this->implant, 'implant');
        $this->sources(['implant kızılay' => 10]);

        $page = Livewire::test(QueriesPage::class)->call('setTab', 'filters')
            ->set('termText', 'Kızılay')->call('addTerm')->assertSee('kızılay')->assertSee('tarama başladı')
            ->set('termText', 'Panorama')->set('termSector', (string) $this->dental->id)->call('addTerm')->assertSee('Diş sağlığı')
            ->set('termText', 'kızılay')->set('termSector', '')->call('addTerm')->assertHasErrors('termText');
        $this->assertSame(['implant kızılay'], Query::query()->pluck('text')->all(), 'a basket change never deletes before approval');
        $this->assertSame('kızılay', QueryReview::query()->sole()->items()->where('kind', QueryReviewItem::DELETE)->sole()->term);
        $this->assertSame($this->dental->id, FilterTerm::query()->where('term', 'panorama')->value('sector_id'));
        $page->set('sector', (string) $this->dental->id)->assertSee('panorama')->assertDontSee('kızılay', false);

        $page->call('deleteTerm', FilterTerm::query()->where('term', 'kızılay')->value('id'));
        $this->assertSame(['implant kızılay'], Query::query()->pluck('text')->all());

        $page->call('setTab', 'keywords')->set('sector', (string) $this->dental->id)
            ->set('newKeyword.'.$this->zirkonyum->id, 'İmplant')->call('addKeyword', $this->zirkonyum->id)
            ->assertHasErrors('newKeyword.'.$this->zirkonyum->id)->assertSee('bu sektörde zaten')
            ->set('newKeyword.'.$this->zirkonyum->id, 'zirkonyum')->call('addKeyword', $this->zirkonyum->id)->assertHasNoErrors();
        $this->assertTrue($this->zirkonyum->matchingKeywords()->where('normalized_key', 'zirkonyum')->exists());

        $page->call('deleteKeyword', ServiceMatchingKeyword::query()->where('normalized_key', 'implant')->value('id'));
        $this->assertSame($this->implant->id, Query::query()->sole()->service_id, 'keyword removed → review first, no silent reassignment');
        $change = QueryReview::query()->sole()->items()->where('kind', QueryReviewItem::SERVICE)->sole();
        $this->assertSame([$this->implant->id, null], [$change->from_service_id, $change->to_service_id]);
    }

    public function test_hidden_queries_are_listed_under_gizlenenler_and_can_be_restored(): void
    {
        $this->sources(['kötü sorgu' => 5, 'implant' => 10]);
        $id = $this->queryId('kötü sorgu');

        Livewire::test(QueriesPage::class)->assertSee('AI ile filtre kural üret')
            ->set('selected', [$id])->call('hideSelected')->assertDontSee('kötü sorgu')
            ->set('hidden', true)->assertSee('kötü sorgu')->assertDontSee('>implant<', false)->assertSee('Geri al')
            ->set('selected', [$id])->call('unhideSelected')->assertSee('1 sorgu geri alındı')
            ->set('hidden', false)->assertSee('kötü sorgu');
        $this->assertFalse(Query::query()->find($id)->hidden);
    }

    public function test_rule_approval_without_ticks_does_not_queue_a_reprocess(): void
    {
        Queue::fake();
        $this->admin->forceFill(['is_active' => true])->save();
        Cache::put(QueryRuleProposer::cacheKey($this->admin->id), ['status' => 'ready', 'terms' => [['term' => 'etimesgut', 'sector_id' => null, 'sector' => null, 'reason' => 'x']], 'keywords' => []], now()->addHour());

        Livewire::test(QueriesPage::class)->set('rulesOpen', true)->call('approveRules')->assertSee('Hiçbir öneri seçilmedi')->assertSet('rulesOpen', true);
        Queue::assertNotPushed(RescanQueriesJob::class);

        FilterTerm::query()->create(['sector_id' => null, 'term' => 'etimesgut']);
        Livewire::test(QueriesPage::class)->set('rulesOpen', true)->set('pickTerms', [0 => true])->call('approveRules')->assertSee('zaten kayıtlı');
        Queue::assertNotPushed(RescanQueriesJob::class);
    }

    public function test_keywords_of_services_without_a_sector_are_global(): void
    {
        $keywords = app(ServiceKeywordService::class);
        $global = app(ServiceCatalogService::class)->resolveOrCreate('Genel Muayene', null, actor: $this->admin)['service'];
        $this->assertTrue(blank($global->sector));
        $keywords->add($global, 'muayene');
        $keywords->replace($this->implant, 'implant');

        foreach ([[$this->zirkonyum, 'Muayene', 'Genel Muayene'], [$global, 'implant', 'Diş İmplantı']] as [$service, $label, $owner]) {
            try {
                $keywords->add($service, $label);
                $this->fail('keyword taken: '.$label);
            } catch (ValidationException $exception) {
                $this->assertStringContainsString($owner, $exception->errors()['keyword'][0]);
            }
        }
        $this->assertSame(['muayene'], $global->matchingKeywords()->pluck('normalized_key')->all());
    }

    public function test_ai_buttons_need_a_selection_or_a_service_and_report_missing_provider(): void
    {
        $this->sources(['implant' => 10]);
        Livewire::test(QueriesPage::class)
            ->call('proposeRules')->assertSet('rulesOpen', false)->assertSee('Önce sorgu seçin')
            ->call('clusterService')->assertSee('Önce hizmet seçin')
            ->set('selected', [$this->queryId('implant')])->call('proposeRules')->assertSee('AI bağlı değil');
    }

    private function enableAi(): void
    {
        config(['moxdop.anthropic.api_key' => 'test-anthropic-value']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
    }

    /** @param array<string, int> $rows raw query => impressions */
    private function sources(array $rows): void
    {
        foreach ($rows as $raw => $impressions) {
            DB::table('query_sources')->insert([
                'external_resource_id' => $this->gsc->id, 'source' => 'gsc', 'raw_query' => $raw, 'month' => '2026-08-01',
                'impressions' => $impressions, 'clicks' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        app(QueryPipeline::class)->run(import: true);
    }

    private function queryId(string $text): int
    {
        return (int) Query::query()->where('text', $text)->value('id');
    }

    /** @param list<string> $texts */
    private function cluster(string $name, array $texts): Cluster
    {
        $cluster = Cluster::query()->create(['sector_id' => $this->dental->id, 'service_id' => $this->implant->id, 'name' => $name, 'main_query_id' => $this->queryId($texts[0])]);
        foreach ($texts as $text) {
            ClusterQuery::query()->create(['cluster_id' => $cluster->id, 'query_id' => $this->queryId($text)]);
        }

        return $cluster;
    }
}
