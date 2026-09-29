<?php

namespace Tests\Feature\Queries;

use App\Ai\Agents\QueryClusterAgent;
use App\Ai\Agents\QueryRulesAgent;
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
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\ServiceMatchingKeyword;
use App\Models\User;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Queries\ClusterEditor;
use App\Services\Queries\QueryPipeline;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** MoxDOP v2 Faz 3: Sorgular screen, "AI ile kural üret", "AI ile kümele", cluster edits, filter basket, matching keywords. */
final class QueriesScreenTest extends TestCase
{
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

        $this->get(route('operator.library.queries'))->assertOk()->assertSee('implant fiyatları')->assertSee('atanmamış');

        Livewire::test(QueriesPage::class)
            ->set('service', '__none')->assertSee('diş taşı temizliği')->assertDontSee('implant fiyatları')
            ->set('service', (string) $this->implant->id)->assertSee('implant fiyatları')->assertSee('İmplant fiyatı')
            ->set('cluster', '__none')->assertDontSee('implant fiyatları')
            ->set('cluster', '')->set('search', 'FİYAT')->assertSee('implant fiyatları')
            ->call('setTab', 'clusters')->assertSee('İmplant fiyatı')->assertSee('ticari')->assertSee('hizmet')
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

    public function test_ai_rules_are_validated_approved_and_all_queries_reprocessed(): void
    {
        $this->enableAi();
        $this->sources(['implant etimesgut' => 40, 'implant' => 60, 'zirkonyum diş' => 10, 'implant ağrısı' => 5]);
        $locked = $this->queryId('implant ağrısı');
        Query::query()->whereKey($locked)->update(['service_id' => $this->zirkonyum->id, 'assignment' => 'manual', 'locked' => true]);
        $prompts = [];
        QueryRulesAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = $prompt;

            return [
                'filter_terms' => [
                    ['term' => 'Etimesgut', 'sector_id' => null, 'reason' => 'İlçe adı'],
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
            ->set('selected', [$this->queryId('implant etimesgut'), $this->queryId('zirkonyum diş')])
            ->call('proposeRules')
            ->assertSet('rulesOpen', true)
            ->assertSee('etimesgut')->assertDontSee('kızılay')->assertSee('Zirkonyum Kaplama');

        $this->assertCount(1, $prompts);
        $this->assertStringContainsString('implant etimesgut', $prompts[0]);
        $this->assertStringNotContainsString('implant ağrısı', $prompts[0], 'only selected queries are sent');

        $page->set('pickTerms', [0 => true])->set('pickKeywords', [0 => true, 1 => true])->call('approveRules')
            ->assertSet('rulesOpen', false)->assertSee('1 filtre terimi · 2 eşleme kelimesi');

        $this->assertSame('ai', FilterTerm::query()->where('term', 'etimesgut')->value('source'));
        $this->assertSame(['implant'], $this->implant->matchingKeywords()->pluck('normalized_key')->all());
        $this->assertSame(['zirkonyum'], $this->zirkonyum->matchingKeywords()->pluck('normalized_key')->all());
        $this->assertFalse(Query::query()->where('text', 'implant etimesgut')->exists(), 'reprocessed: merged into "implant"');
        $implant = Query::query()->where('text', 'implant')->sole();
        $this->assertSame(100, $implant->impressions);
        $this->assertSame($this->implant->id, $implant->service_id);
        $this->assertSame($this->zirkonyum->id, Query::query()->where('text', 'zirkonyum diş')->value('service_id'));
        $this->assertSame($this->zirkonyum->id, Query::query()->find($locked)->service_id, 'locked assignment kept');
    }

    public function test_ai_clustering_creates_clusters_flags_suggested_queries_and_keeps_locked_clusters_on_rerun(): void
    {
        $this->enableAi();
        app(ServiceKeywordService::class)->replace($this->implant, 'implant');
        $this->sources(['implant fiyatları' => 300, 'implant ücreti' => 100, 'implant sonrası ağrı' => 80, 'implant ağrısı ne kadar sürer' => 20]);
        $ids = fn (string ...$texts): array => array_map(fn (string $t): int => $this->queryId($t), $texts);
        $prompts = [];
        QueryClusterAgent::fake(function (string $prompt) use (&$prompts, $ids): array {
            $prompts[] = $prompt;
            if (count($prompts) === 1) {
                return ['clusters' => [
                    ['name' => 'İmplant fiyatı', 'intent' => 'commercial', 'page_type' => 'service', 'query_ids' => [...$ids('implant fiyatları', 'implant ücreti'), 999999],
                        'main_query_id' => 999999, 'representative_query_ids' => $ids('implant ücreti'), 'new_queries' => ['implant fiyatları 2026', 'İmplant Ücreti'],
                        'subtopics' => ['Fiyatı etkileyen durumlar'], 'reasoning' => 'Aynı fiyat ihtiyacı.'],
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
        $this->assertSame($ids('implant ücreti'), $price->representative_query_ids);
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

        $page->call('approveCluster')->set('clusterName', 'A son')->call('renameCluster');
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
            ->set('termText', 'Kızılay')->call('addTerm')->assertSee('kızılay')
            ->set('termText', 'Panorama')->set('termSector', (string) $this->dental->id)->call('addTerm')->assertSee('Diş sağlığı')
            ->set('termText', 'kızılay')->set('termSector', '')->call('addTerm')->assertHasErrors('termText');
        $this->assertSame(['implant'], Query::query()->pluck('text')->all(), 'basket change reprocesses queries');
        $this->assertSame($this->dental->id, FilterTerm::query()->where('term', 'panorama')->value('sector_id'));

        $page->call('deleteTerm', FilterTerm::query()->where('term', 'kızılay')->value('id'));
        $this->assertSame(['implant kızılay'], Query::query()->pluck('text')->all());

        $page->call('setTab', 'keywords')->set('sector', (string) $this->dental->id)
            ->set('newKeyword.'.$this->zirkonyum->id, 'İmplant')->call('addKeyword', $this->zirkonyum->id)
            ->assertHasErrors('newKeyword.'.$this->zirkonyum->id)->assertSee('bu sektörde zaten')
            ->set('newKeyword.'.$this->zirkonyum->id, 'zirkonyum')->call('addKeyword', $this->zirkonyum->id)->assertHasNoErrors();
        $this->assertTrue($this->zirkonyum->matchingKeywords()->where('normalized_key', 'zirkonyum')->exists());

        $page->call('deleteKeyword', ServiceMatchingKeyword::query()->where('normalized_key', 'implant')->value('id'));
        $this->assertNull(Query::query()->sole()->service_id, 'keyword removed → reprocessed, unassigned');
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
        app(QueryPipeline::class)->run();
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
