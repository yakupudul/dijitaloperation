<?php

namespace Tests\Feature\Queries;

use App\Ai\Agents\QueryClusterAgent;
use App\Ai\Agents\QueryClusterReviewAgent;
use App\Ai\Agents\QueryRulesAgent;
use App\Jobs\Queries\ClusterQueriesJob;
use App\Jobs\Queries\RescanQueriesJob;
use App\Livewire\Operator\Library\QueriesPage;
use App\Models\AiTask;
use App\Models\Brand;
use App\Models\BrandOffering;
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
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Prompts\PromptRegistry;
use App\Services\Queries\ClusterEditor;
use App\Services\Queries\QueryClusterer;
use App\Services\Queries\QueryClusterQueue;
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

    public function test_ai_clustering_creates_clusters_never_invents_queries_auto_approves_and_keeps_locked_and_approved_clusters_on_rerun(): void
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
                ], 'skipped' => [['id' => $ids('implant ağrısı ne kadar sürer')[0], 'reason' => 'not_relevant', 'service' => null]],
                    'prompt_version' => QueryClusterAgent::PROMPT_VERSION];
            }

            return ['clusters' => [
                ['name' => 'Ağrı süresi', 'intent' => 'informational', 'page_type' => 'faq', 'query_ids' => $ids('implant ağrısı ne kadar sürer', 'implant sonrası ağrı'),
                    'main_query_id' => null, 'representative_query_ids' => [], 'new_queries' => [], 'subtopics' => [], 'reasoning' => 'SSS.'],
            ], 'prompt_version' => QueryClusterAgent::PROMPT_VERSION];
        });

        Livewire::test(QueriesPage::class)->set('service', (string) $this->implant->id)->call('clusterService')->assertSee('hazır · 2 yeni küme · 2 otomatik onaylandı');

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
        $this->assertFalse(Query::query()->where('text', 'implant fiyatları 2026')->exists(), 'a query the model made up is never stored');
        $this->assertSame(2, $price->clusterQueries()->count(), 'only collected searches; "İmplant Ücreti" is already in the topic');
        $this->assertTrue($price->approved, 'a sound cluster of a finished run is approved on its own');
        $this->assertFalse($price->locked, 'auto-approval does not lock: the operator can still edit');
        $pain = Cluster::query()->where('name', 'İmplant sonrası ağrı')->sole();
        $this->assertSame($ids('implant sonrası ağrı'), $pain->clusterQueries()->pluck('query_id')->all(), 'a query is in one cluster only');
        $this->assertFalse(Cluster::query()->where('name', 'Uydurma')->exists());
        $this->assertFalse(Query::query()->where('text', 'ankara implant')->exists());

        app(ClusterEditor::class)->rename($price, 'İmplant fiyatları');
        Livewire::test(QueriesPage::class)->set('service', (string) $this->implant->id)->call('clusterService');

        $second = json_decode(substr($prompts[1], strlen("DATA_JSON\n")), true);
        $this->assertStringNotContainsString('implant ücreti', json_encode($second['topics'], JSON_UNESCAPED_UNICODE), 'locked cluster queries are not sent again as topics');
        $this->assertSame(['İmplant fiyatları', true], [$second['existing_clusters'][0]['name'], $second['existing_clusters'][0]['locked']], 'the locked cluster is shown as an existing cluster');
        $this->assertSame(2, Cluster::query()->whereKey($price->id)->sole()->clusterQueries()->count(), 'locked cluster untouched');
        $this->assertTrue(Cluster::query()->whereKey($pain->id)->exists(), 'an approved cluster is in use by brands: a full run keeps it');
        $this->assertSame(['Ağrı süresi', 'İmplant fiyatları', 'İmplant sonrası ağrı'], Cluster::query()->orderBy('name')->pluck('name')->all());
    }

    public function test_clustering_goes_in_parts_places_every_topic_and_reviews_the_clusters(): void
    {
        $this->enableAi();
        config(['moxdop-query-rules.cluster' => ['skeleton_topics' => 2, 'place_topics' => 2]]);
        app(ServiceKeywordService::class)->replace($this->implant, 'implant');
        $this->sources(['implant fiyatları' => 500, 'implant sonrası ağrı' => 400, 'implant markaları' => 300, 'implant kemik tozu' => 200, 'implant sigara' => 100]);
        $calls = [];
        QueryClusterAgent::fake(function (string $prompt) use (&$calls): array {
            $data = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);
            $calls[] = $data;
            $ids = array_column($data['topics'], 'id');
            $row = fn (?int $existing, string $name, array $queryIds): array => ['existing_cluster_id' => $existing, 'name' => $name, 'intent' => 'informational',
                'user_need' => 'İmplant hakkında bilgi', 'page_type' => 'guide', 'query_ids' => $queryIds, 'main_query_id' => $queryIds[0] ?? null,
                'representative_query_ids' => [], 'new_queries' => [], 'subtopics' => ['Süreç'], 'exclusions' => [], 'reasoning' => '-'];

            return match (count($calls)) {
                1 => ['clusters' => [$row(null, 'İmplant genel', $ids)], 'skipped' => []],
                // The second topic is not mentioned: it is asked again in the next part.
                2 => ['clusters' => [$row($data['existing_clusters'][0]['id'], 'İmplant genel', [$ids[0]])], 'skipped' => []],
                3 => ['clusters' => [$row(null, 'İmplant malzemesi', [$ids[0]])],
                    'skipped' => [['id' => $ids[1], 'reason' => 'other_service', 'service' => 'Zirkonyum Kaplama']]],
                default => ['clusters' => [], 'skipped' => array_map(fn (int $id): array => ['id' => $id, 'reason' => 'not_relevant', 'service' => null], $ids)],
            } + ['prompt_version' => QueryClusterAgent::PROMPT_VERSION];
        });
        $reviews = [];
        QueryClusterReviewAgent::fake(function (string $prompt) use (&$reviews): array {
            $data = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);
            $reviews[] = $data;
            $byName = array_column($data['clusters'], 'id', 'name');

            return ['merges' => [['into_id' => $byName['İmplant genel'], 'from_ids' => [$byName['İmplant malzemesi'], 999]]],
                'updates' => [['id' => $byName['İmplant genel'], 'name' => 'İmplant rehberi', 'intent' => 'informational', 'page_type' => 'guide',
                    'user_need' => 'İmplantı baştan sona anlamak', 'subtopics' => ['Fiyat', 'Ağrı', 'Markalar'], 'exclusions' => []]],
                'prompt_version' => QueryClusterReviewAgent::PROMPT_VERSION];
        });

        Livewire::test(QueriesPage::class)->call('setTab', 'clusters')->set('service', (string) $this->implant->id)->call('clusterService')
            ->assertSee('hazır')->assertSee('Kümede olmayan sorgu: 1')->assertSee('başka hizmete ait 1')->assertSee('(Zirkonyum Kaplama 1)')->assertSee('İmplant rehberi');

        $this->assertCount(3, $calls, 'skeleton + two placing parts');
        $this->assertSame([2, 2, 2], array_map(fn (array $call): int => count($call['topics']), $calls));
        $this->assertSame(array_column($calls[1]['topics'], 'id')[1], array_column($calls[2]['topics'], 'id')[0], 'a topic the answer did not mention is asked again first');
        $this->assertSame(['Zirkonyum Kaplama'], $calls[0]['other_services']);
        $this->assertSame([], $calls[0]['existing_clusters']);
        $this->assertSame(['İmplant genel'], array_column($calls[1]['existing_clusters'], 'name'), 'later parts see the clusters built so far');
        $this->assertCount(2, $reviews[0]['clusters']);
        $cluster = Cluster::query()->sole();
        $this->assertSame(['İmplant rehberi', 'İmplantı baştan sona anlamak', ['Fiyat', 'Ağrı', 'Markalar']], [$cluster->name, $cluster->user_need, $cluster->subtopics]);
        $this->assertSame(4, $cluster->clusterQueries()->count(), 'merged cluster keeps every placed query');
        $this->assertFalse(ClusterQuery::query()->where('query_id', $this->queryId('implant sigara'))->exists(), 'left out by the AI');
        $state = QueryClusterer::state($this->implant->id);
        $this->assertSame(['ready', 'done', 4], [$state['status'], $state['step'], $state['part']]);
        $this->assertSame([1, 0, 0], [$state['skipped']['other_service'], $state['skipped']['not_relevant'], $state['skipped']['unprocessed']]);
        $this->assertArrayNotHasKey('left_out', $state);

        // A locked cluster keeps its definition: the review never merges it away nor rewrites it.
        $cluster->forceFill(['locked' => true])->save();
        Cluster::query()->create(['sector_id' => $this->dental->id, 'service_id' => $this->implant->id, 'name' => 'İmplant malzemesi', 'intent' => 'informational', 'page_type' => 'guide']);
        Cluster::query()->create(['sector_id' => $this->dental->id, 'service_id' => $this->implant->id, 'name' => 'Boş', 'intent' => 'informational', 'page_type' => 'guide']);
        QueryClusterReviewAgent::fake(fn (): array => ['merges' => [['into_id' => Cluster::query()->where('name', 'Boş')->value('id'), 'from_ids' => [$cluster->id]]],
            'updates' => [['id' => $cluster->id, 'name' => 'Değişmemeli', 'intent' => 'local', 'page_type' => 'location', 'user_need' => 'x', 'subtopics' => [], 'exclusions' => []]],
            'prompt_version' => QueryClusterReviewAgent::PROMPT_VERSION]);
        QueryClusterer::start($this->implant->id, 'place');
        ClusterQueriesJob::dispatch($this->implant->id);
        $this->assertSame('İmplant rehberi', $cluster->fresh()->name);
        $this->assertSame(4, $cluster->clusterQueries()->count());
    }

    public function test_delegated_clustering_waits_for_claude_and_keeps_the_asked_part_while_triage_adds_queries(): void
    {
        $this->enableAi();
        config(['moxdop-mcp.token' => 'test-mcp-token']);
        $registry = app(PromptRegistry::class);
        foreach ([QueryClusterAgent::OPERATION, QueryClusterReviewAgent::OPERATION] as $operation) {
            $registry->publish($operation, ['template' => (string) $registry->current($operation)->template, 'model' => AiTaskQueue::MODEL], $this->admin);
        }
        QueryClusterAgent::fake()->preventStrayPrompts();
        QueryClusterReviewAgent::fake()->preventStrayPrompts();
        app(ServiceKeywordService::class)->replace($this->implant, 'implant');
        $this->sources(['implant fiyatları' => 500, 'implant sonrası ağrı' => 400]);
        $tasks = app(AiTaskQueue::class);

        QueryClusterer::start($this->implant->id);
        ClusterQueriesJob::dispatch($this->implant->id);

        $task = AiTask::query()->sole();
        $this->assertSame([QueryClusterAgent::OPERATION, AiTask::PENDING], [$task->operation, $task->status]);
        $state = QueryClusterer::state($this->implant->id);
        $this->assertSame(['running', 'claude'], [$state['status'], $state['waiting']]);
        $this->assertStringStartsWith('Claude bekleniyor', QueryClusterer::label($state)['text']);
        QueryClusterAgent::assertNeverPrompted();

        // Triage adds a bigger query meanwhile: the asked part stays as asked, so Claude's answer is found again.
        $this->sources(['implant bakımı' => 900]);
        $ids = [$this->queryId('implant fiyatları'), $this->queryId('implant sonrası ağrı')];
        $row = fn (string $name, string $type, array $queryIds): array => ['existing_cluster_id' => null, 'name' => $name, 'intent' => 'informational',
            'user_need' => 'İmplant hakkında bilgi', 'page_type' => $type, 'query_ids' => $queryIds, 'main_query_id' => $queryIds[0],
            'representative_query_ids' => [], 'new_queries' => [], 'subtopics' => [], 'exclusions' => [], 'reasoning' => '-'];
        $this->assertSame([], $tasks->submit($task, ['clusters' => [$row('İmplant fiyatı', 'service', [$ids[0]]), $row('İmplant sonrası', 'guide', [$ids[1]])],
            'skipped' => [], 'prompt_version' => QueryClusterAgent::PROMPT_VERSION]));

        $this->assertSame(['İmplant fiyatı', 'İmplant sonrası'], Cluster::query()->orderBy('id')->pluck('name')->all());
        $this->assertFalse(ClusterQuery::query()->where('query_id', $this->queryId('implant bakımı'))->exists(), 'not in the asked part');
        $review = AiTask::query()->where('operation', QueryClusterReviewAgent::OPERATION)->sole();
        $this->assertSame(AiTask::PENDING, $review->status);
        $this->assertSame(AiTask::CONSUMED, $task->fresh()->status);
        $this->assertSame('review', QueryClusterer::state($this->implant->id)['step']);

        $this->assertSame([], $tasks->submit($review, ['merges' => [], 'updates' => [], 'prompt_version' => QueryClusterReviewAgent::PROMPT_VERSION]));

        $state = QueryClusterer::state($this->implant->id);
        $this->assertSame(['ready', 2], [$state['status'], $state['approved']]);
        $this->assertArrayNotHasKey('pending', $state);
        $this->assertArrayNotHasKey('waiting', $state);
        $this->assertSame(2, AiTask::query()->count());
    }

    public function test_clustering_refuses_catch_all_clusters_and_the_review_never_merges_other_page_types_or_approved_clusters(): void
    {
        $this->enableAi();
        app(ServiceKeywordService::class)->replace($this->implant, 'implant');
        $this->sources(['implant fiyatları' => 500, 'implant sonrası ağrı' => 400]);
        $calls = 0;
        QueryClusterAgent::fake(function (string $prompt) use (&$calls): array {
            $calls++;
            $ids = array_column(json_decode(substr($prompt, strlen("DATA_JSON\n")), true)['topics'], 'id');
            $row = fn (string $name, string $type, array $queryIds): array => ['existing_cluster_id' => null, 'name' => $name, 'intent' => 'informational',
                'user_need' => 'x', 'page_type' => $type, 'query_ids' => $queryIds, 'main_query_id' => null, 'representative_query_ids' => [],
                'new_queries' => [], 'subtopics' => [], 'exclusions' => [], 'reasoning' => '-'];

            // First answer: one catch-all cluster (refused, its topics are asked again); then a real split.
            return ($calls === 1 ? ['clusters' => [$row('Diğer sorular', 'guide', $ids)]]
                : ['clusters' => [$row('İmplant fiyatı', 'service', [$ids[0]]), $row('İmplant sonrası ağrı', 'guide', [$ids[1]])]])
                + ['skipped' => [], 'prompt_version' => QueryClusterAgent::PROMPT_VERSION];
        });
        QueryClusterReviewAgent::fake(function (string $prompt): array {
            $byName = array_column(json_decode(substr($prompt, strlen("DATA_JSON\n")), true)['clusters'], 'id', 'name');

            return ['merges' => [['into_id' => $byName['İmplant fiyatı'], 'from_ids' => [$byName['İmplant sonrası ağrı']]]],
                'updates' => [['id' => $byName['İmplant fiyatı'], 'name' => 'Genel', 'intent' => 'commercial', 'page_type' => 'service',
                    'user_need' => 'x', 'subtopics' => [], 'exclusions' => []]], 'prompt_version' => QueryClusterReviewAgent::PROMPT_VERSION];
        });

        Livewire::test(QueriesPage::class)->set('service', (string) $this->implant->id)->call('clusterService')->assertSee('2 otomatik onaylandı');

        $this->assertSame(2, $calls);
        $this->assertFalse(Cluster::query()->where('name', 'Diğer sorular')->exists(), 'a catch-all cluster is never stored');
        $this->assertSame(['İmplant fiyatı', 'İmplant sonrası ağrı'], Cluster::query()->orderBy('name')->pluck('name')->all(),
            'a service page and a guide are never merged; a catch-all rename is refused');
        $this->assertSame(2, Cluster::query()->where('approved', true)->count());

        // A later review never merges an approved cluster away (brands use it).
        $guide = Cluster::query()->where('name', 'İmplant sonrası ağrı')->sole();
        $other = Cluster::query()->create(['sector_id' => $this->dental->id, 'service_id' => $this->implant->id, 'name' => 'İmplant ağrısı', 'intent' => 'informational', 'page_type' => 'guide']);
        QueryClusterReviewAgent::fake(fn (): array => ['merges' => [['into_id' => $other->id, 'from_ids' => [$guide->id]]], 'updates' => [],
            'prompt_version' => QueryClusterReviewAgent::PROMPT_VERSION]);
        QueryClusterer::start($this->implant->id, 'place');
        ClusterQueriesJob::dispatch($this->implant->id);
        $this->assertTrue($guide->fresh() !== null, 'approved cluster kept');
    }

    public function test_cluster_all_runs_services_one_by_one_and_the_overview_shows_brands_and_state(): void
    {
        $this->enableAi();
        app(ServiceKeywordService::class)->replace($this->implant, 'implant');
        app(ServiceKeywordService::class)->replace($this->zirkonyum, 'zirkonyum');
        $this->sources(['implant fiyatları' => 500, 'zirkonyum kaplama fiyatı' => 50, 'zirkonyum kaç yıl dayanır' => 40]);
        $brand = Brand::query()->sole();
        $brand->update(['name' => 'Klinik Ankara']);
        BrandOffering::query()->create(['brand_id' => $brand->id, 'service_catalog_item_id' => $this->implant->id, 'status' => 'active']);
        // Zirkonyum already has an approved cluster: only its unclustered query is placed (no re-cluster).
        $approved = $this->cluster('Zirkonyum fiyatı', ['zirkonyum kaplama fiyatı']);
        $approved->forceFill(['service_id' => $this->zirkonyum->id, 'locked' => true, 'approved' => true])->save();

        Livewire::test(QueriesPage::class)->call('setTab', 'clusters')
            ->assertSee('Hepsini kümele')->assertSee('Diş sağlığı')->assertSee('Diş İmplantı')->assertSee('Klinik Ankara')
            ->assertSee('kümelenmedi')->assertSee('1 sorgu kümede değil')->assertSee('1 onaylı');

        $services = [];
        QueryClusterAgent::fake(function (string $prompt) use (&$services): array {
            $data = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);
            $services[] = $data['service'];
            $existing = $data['existing_clusters'][0]['id'] ?? null;

            return ['clusters' => [['existing_cluster_id' => $existing, 'name' => 'Yeni', 'intent' => 'commercial', 'user_need' => 'x', 'page_type' => 'service',
                'query_ids' => array_column($data['topics'], 'id'), 'main_query_id' => null, 'representative_query_ids' => [], 'new_queries' => [],
                'subtopics' => [], 'exclusions' => [], 'reasoning' => '-']], 'prompt_version' => QueryClusterAgent::PROMPT_VERSION];
        });

        Livewire::test(QueriesPage::class)->call('setTab', 'clusters')->call('clusterAll')->assertSee('2 hizmet sıraya alındı')
            ->assertSee('Son toplu kümeleme bitti · 2 hizmet')->assertSee('kümelendi')->assertDontSee('kümelenmedi');

        $this->assertSame(['Diş İmplantı', 'Zirkonyum Kaplama'], $services, 'biggest demand first, one service at a time');
        $this->assertSame(2, $approved->clusterQueries()->count(), 'the new query joined the approved cluster');
        $this->assertSame('Zirkonyum fiyatı', $approved->fresh()->name, 'its definition did not change');
        $this->assertSame(1, Cluster::query()->where('service_id', $this->implant->id)->count());
        $this->assertSame([], app(QueryClusterQueue::class)->servicesToCluster());
    }

    public function test_a_failed_part_marks_the_run_as_error_and_stop_ends_the_queue(): void
    {
        $this->enableAi();
        app(ServiceKeywordService::class)->replace($this->implant, 'implant');
        $this->sources(['implant fiyatları' => 500]);
        QueryClusterAgent::fake(fn () => throw new \RuntimeException('provider down'));

        QueryClusterer::start($this->implant->id);
        try {
            ClusterQueriesJob::dispatch($this->implant->id);
        } catch (\RuntimeException) {
            // The sync queue rethrows after failed().
        }
        $this->assertSame('error', QueryClusterer::state($this->implant->id)['status']);
        Livewire::test(QueriesPage::class)->call('setTab', 'clusters')->assertSee('hata · yeniden deneyin');

        Cache::put(QueryClusterQueue::KEY, ['status' => 'running', 'queue' => [$this->zirkonyum->id], 'current' => $this->implant->id, 'done' => 0, 'total' => 2]);
        QueryClusterer::start($this->implant->id);
        Livewire::test(QueriesPage::class)->call('setTab', 'clusters')->assertSee('Durdur')->call('stopClusterAll');
        $this->assertSame(['stopped', []], [QueryClusterQueue::state()['status'], QueryClusterQueue::state()['queue']]);
        $this->assertSame('stopped', QueryClusterer::state($this->implant->id)['status']);
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

        $page->call('approveCluster')->set('clusterForm.name', 'A son')->call('saveCluster')
            ->assertSeeHtml('data-drawer-message')->assertSee('Küme kaydedildi.');
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
