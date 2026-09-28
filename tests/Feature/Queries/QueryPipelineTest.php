<?php

namespace Tests\Feature\Queries;

use App\Ai\Agents\Brain\QueryServiceClassifierAgent;
use App\Ai\Agents\Queries\AssetSectorAgent;
use App\Ai\Agents\Queries\QueryClusterAgent;
use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\QueryVariant;
use App\Models\ResourceAutomation;
use App\Models\SearchQueryLibraryItem;
use App\Models\ServiceCatalogItem;
use App\Models\User;
use App\Services\Integrations\DataForSeo\DataForSeoProviderCredentialService;
use App\Services\Integrations\ResourceAutomationService;
use App\Services\Queries\AssetSectorService;
use App\Services\Queries\ClusterPageResearch;
use App\Services\Queries\QueryPipeline;
use App\Services\Queries\QueryServiceMatcher;
use App\Services\SearchDemand\ServiceCatalogService;
use App\Services\SearchDemand\ServiceKeywordService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Brain\InsertsFacts;
use Tests\TestCase;

/** Sorgu hattı: every account → core queries → sector → service → clusters → SERP page type. */
final class QueryPipelineTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    private User $admin;

    private CoreIntegration $google;

    private Brand $brand;

    private DigitalAsset $site;

    private CoreExternalResource $boundGsc;

    private ServiceCatalogItem $implant;

    private ServiceCatalogItem $whitening;

    private ServiceCatalogItem $ortho;

    private int $dental;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->dental = (int) DB::table('service_categories')->where('code', 'dental')->value('id');
        $catalog = app(ServiceCatalogService::class);
        $this->implant = $catalog->resolveOrCreate('İmplant Tedavisi', 'dental', actor: $this->admin)['service'];
        $this->whitening = $catalog->resolveOrCreate('Diş Beyazlatma', 'dental', actor: $this->admin)['service'];
        $this->ortho = $catalog->resolveOrCreate('Ortodonti', 'dental', actor: $this->admin)['service'];
        app(ServiceKeywordService::class)->append($this->implant, ['implant']);
        $this->google = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);

        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Atlas Diş']);
        $this->brand->sectors()->attach($this->dental);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'primary_url' => 'https://atlasdis.com/', 'domain' => 'atlasdis.com']);
        $this->boundGsc = $this->resource('search_console', 'sc-domain:atlasdis.com', 'atlasdis.com');
        CoreAssetBinding::query()->create(['digital_asset_id' => $this->site->id, 'external_resource_id' => $this->boundGsc->id, 'capability' => 'search_console', 'status' => 'active', 'configuration' => []]);
        Http::preventStrayRequests();
    }

    public function test_every_account_feeds_core_queries_even_unbound_and_sector_files_them(): void
    {
        $unbound = $this->resource('search_console', 'sc-domain:panorama.com.tr', 'panorama.com.tr');
        $this->gsc($unbound, 'ankara implant fiyatı', 5, 100);
        $this->gsc($unbound, 'implant fiyati', 2, 50);
        $this->gsc($unbound, 'panorama zirkonyum', 1, 20);
        $this->gsc($this->boundGsc, 'Çankaya implant fiyatı', 3, 30);
        $this->gsc($this->boundGsc, 'atlas diş', 9, 90);
        $automation = ResourceAutomation::query()->create(['external_resource_id' => $unbound->id]);
        $this->assertNull(app(ResourceAutomationService::class)->portfolioGate($automation->load('resource')), 'free query sources are pulled from unbound accounts too');

        app(QueryPipeline::class)->daily();

        $core = SearchQueryLibraryItem::query()->where('canonical_text', 'implant fiyatı')->sole();
        $this->assertSame(180, (int) $core->gsc_impressions, 'one core query across accounts, ı/i folded, places stripped');
        $this->assertSame(10, (int) $core->gsc_clicks);
        $this->assertSame(3, (int) $core->variant_count);
        $variant = QueryVariant::query()->where('raw_text', 'ankara implant fiyatı')->sole();
        $this->assertTrue($variant->had_location);
        $this->assertSame($unbound->id, (int) $variant->external_resource_id);
        $this->assertSame(['implant' => 'matched'], [
            'implant' => DB::table('search_query_library_sectors')->where('search_query_library_item_id', $core->id)->where('service_category_id', $this->dental)->value('match_status'),
        ], 'filed under the bound brand sector and matched by rule');
        $this->assertSame([$this->implant->id], $core->services()->pluck('service_catalog_items.id')->all());
        $this->assertSame('brand', DB::table('asset_sectors')->where('subject_type', 'resource')->where('subject_id', $this->boundGsc->id)->value('method'));

        $zirkonyum = SearchQueryLibraryItem::query()->where('canonical_text', 'zirkonyum')->sole();
        $this->assertTrue(QueryVariant::query()->where('raw_text', 'panorama zirkonyum')->value('had_own_brand'), 'unbound account: its own domain is its brand');
        $this->assertSame(0, DB::table('search_query_library_sectors')->where('search_query_library_item_id', $zirkonyum->id)->count(), 'no sector yet: not filed');
        $this->assertSame('brand', QueryVariant::query()->where('raw_text', 'atlas diş')->value('kind'), 'own brand only: not a core query');

        app(AssetSectorService::class)->set('resource', $unbound->id, $this->dental, $this->admin);
        app(QueryPipeline::class)->daily();

        $this->assertSame(1, DB::table('search_query_library_sectors')->where('search_query_library_item_id', $zirkonyum->id)->where('service_category_id', $this->dental)->count(), 'manual sector: filed after the next pass');
        $this->assertSame('unmatched', DB::table('search_query_library_sectors')->where('search_query_library_item_id', $zirkonyum->id)->value('match_status'));
    }

    public function test_ai_assigns_sectors_in_one_batch_and_manual_wins(): void
    {
        $this->enableAi();
        $gsc = $this->resource('search_console', 'sc-domain:hukukburo.com', 'hukukburo.com');
        $ads = $this->resource('google_ads', '5551112222', 'Güzellik Salonu Reklam');
        $calls = 0;
        AssetSectorAgent::fake(function ($prompt) use (&$calls, $gsc, $ads): array {
            $calls++;
            $keys = array_column(json_decode(Str::after((string) $prompt, "INPUT_JSON\n"), true)['assets'], 'key');

            return ['items' => array_values(array_filter([
                in_array('resource:'.$gsc->id, $keys, true) ? ['key' => 'resource:'.$gsc->id, 'sector' => 'legal', 'confidence' => 0.8, 'reason' => 'Hukuk sorguları'] : null,
                in_array('resource:'.$ads->id, $keys, true) ? ['key' => 'resource:'.$ads->id, 'sector' => 'beauty', 'confidence' => 0.7, 'reason' => 'Salon'] : null,
            ]))];
        });

        $stats = app(AssetSectorService::class)->assignPending();

        $this->assertSame(1, $calls, 'many assets per AI call');
        $this->assertSame(2, $stats['ai']);
        $legal = (int) DB::table('service_categories')->where('code', 'legal')->value('id');
        $row = DB::table('asset_sectors')->where('subject_type', 'resource')->where('subject_id', $gsc->id)->first();
        $this->assertSame([$legal, 'ai'], [(int) $row->service_category_id, $row->method]);
        $this->assertSame('legal', app(AssetSectorService::class)->sectorForResource($gsc->id));

        app(AssetSectorService::class)->assignPending();
        $this->assertSame(1, $calls, 'unchanged signals: not asked again');

        app(AssetSectorService::class)->set('resource', $gsc->id, $this->dental, $this->admin);
        DB::table('asset_sectors')->where('subject_id', $gsc->id)->update(['signals_hash' => 'changed']);
        app(AssetSectorService::class)->assignPending();
        $row = DB::table('asset_sectors')->where('subject_type', 'resource')->where('subject_id', $gsc->id)->first();
        $this->assertSame([$this->dental, 'manual'], [(int) $row->service_category_id, $row->method], 'manual wins forever');
    }

    public function test_rules_then_ai_fallback_then_manual_reassignment_wins(): void
    {
        $this->enableAi();
        $this->gsc($this->boundGsc, 'implant fiyatları', 5, 100);
        $this->gsc($this->boundGsc, 'dişlerim sarardı', 1, 40);
        $this->gsc($this->boundGsc, 'hava durumu', 0, 10);
        QueryServiceClassifierAgent::fake(function ($prompt): array {
            $input = json_decode(Str::after((string) $prompt, "INPUT_JSON\n"), true);

            return ['items' => array_map(fn (array $q): array => [
                'query_id' => $q['id'], 'service_id' => str_contains($q['text'], 'sarar') ? $this->whitening->id : null,
                'intent' => 'informational', 'reason' => 'test',
            ], $input['queries'])];
        });

        app(QueryPipeline::class)->daily();

        $implant = SearchQueryLibraryItem::query()->where('canonical_text', 'implant fiyatları')->sole();
        $sarardi = SearchQueryLibraryItem::query()->where('canonical_text', 'dişlerim sarardı')->sole();
        $hava = SearchQueryLibraryItem::query()->where('canonical_text', 'hava durumu')->sole();
        $this->assertSame('keyword_match', $implant->services()->sole()->pivot->provenance, 'rule: suffix-tolerant implant → İmplant Tedavisi');
        $this->assertSame([$this->whitening->id, 'ai_match'], [$sarardi->services()->sole()->id, $sarardi->services()->sole()->pivot->provenance]);
        $this->assertSame('irrelevant', $this->linkStatus($hava), 'AI: none of the sector services → alakasız');
        QueryServiceClassifierAgent::assertNotPrompted(fn ($prompt): bool => $prompt->contains('implant fiyatları'));

        $matcher = app(QueryServiceMatcher::class);
        $matcher->assign([$implant->id], $this->ortho->id, $this->admin);
        app(QueryPipeline::class)->daily(force: true);
        $this->assertSame([$this->ortho->id], $implant->fresh()->services()->pluck('service_catalog_items.id')->all(), 'manual wins over the rule');
        $this->assertTrue(DB::table('library_query_service_blocks')->where('query_id', $implant->id)->where('service_id', $this->implant->id)->exists());

        $matcher->restore([$hava->id], 'dental', $this->admin);
        $this->assertSame('pending', $this->linkStatus($hava));
        $matcher->markIrrelevant([$sarardi->id], 'dental', $this->admin);
        $this->assertSame(['irrelevant', 0], [$this->linkStatus($sarardi), $sarardi->services()->count()]);
    }

    public function test_clusters_by_ai_then_page_type_from_google_top_ten(): void
    {
        $this->enableAi();
        foreach (['implant fiyatı' => 300, 'implant fiyatları' => 200, 'implant ağrılı mı' => 80, 'implant ağrısı' => 60] as $text => $impressions) {
            $this->gsc($this->boundGsc, $text, 1, $impressions);
        }
        QueryServiceClassifierAgent::fake([['items' => []]]);
        app(QueryPipeline::class)->daily();
        QueryClusterAgent::fake(function ($prompt): array {
            $queries = collect(json_decode(Str::after((string) $prompt, "INPUT_JSON\n"), true)['queries']);
            $ids = fn (string $needle): array => $queries->filter(fn (array $q): bool => str_contains($q['text'], $needle))->pluck('id')->values()->all();

            return ['clusters' => [
                ['name' => 'İmplant Fiyatları', 'head_query_id' => $ids('fiyat')[0], 'query_ids' => $ids('fiyat'), 'page_type' => 'hizmet', 'reason' => 'fiyat'],
                ['name' => 'İmplant Ağrısı', 'head_query_id' => $ids('ağrı')[0], 'query_ids' => $ids('ağrı'), 'page_type' => 'sss', 'reason' => 'ağrı'],
            ]];
        });
        $integration = CoreIntegration::factory()->dataforseo()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        config(['moxdop.dataforseo.base_url' => 'https://api.dataforseo.com', 'moxdop.dataforseo.login' => null, 'moxdop.dataforseo.password' => null]);
        app(DataForSeoProviderCredentialService::class)->save($integration, ['login' => 'agency@example.com', 'password' => 'secret'], $this->admin);
        Http::fake(function (Request $request) {
            $keyword = (string) data_get($request->data(), '0.keyword');
            $items = str_contains($keyword, 'ağrı')
                ? [['https://saglik.test/blog/implant-agrisi-ne-kadar-surer', 'İmplant ağrısı ne kadar sürer?'], ['https://klinik.test/makale/implant-sonrasi', 'İmplant sonrası ağrı nasıl geçer'],
                    ['https://eksisozluk.com/implant--1', 'implant'], ['https://dis.test/blog/agri', 'Ağrı rehberi'], ['https://atlas.test/implant', 'İmplant Tedavisi']]
                : [['https://a.test/implant', 'İmplant Tedavisi | A Klinik'], ['https://b.test/implant-tedavisi', 'İmplant Tedavisi'], ['https://doktortakvimi.com/implant', 'İmplant doktorları'],
                    ['https://c.test/blog/implant-fiyatlari-nedir', 'İmplant fiyatları nedir?']];

            return Http::response(['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.002, 'tasks_count' => 1, 'tasks_error' => 0,
                'tasks' => [['id' => 't', 'status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.002, 'result' => [['items' => array_map(
                    fn (array $r, int $i): array => ['type' => 'organic', 'rank_group' => $i + 1, 'url' => $r[0], 'title' => $r[1]], $items, array_keys($items))]]]]]);
        });

        $stats = app(QueryPipeline::class)->weekly();

        $this->assertSame(1, $stats['clusters']['services']);
        $clusters = DB::table('library_query_clusters')->where('service_id', $this->implant->id)->get()->keyBy('name');
        $this->assertSame(2, $clusters->count());
        $fiyat = $clusters['İmplant Fiyatları'];
        $agri = $clusters['İmplant Ağrısı'];
        $this->assertSame('implant fiyatı', $fiyat->head_query);
        $this->assertSame(2, DB::table('search_query_library_item_service')->where('library_cluster_id', $agri->id)->count());
        $this->assertSame(['hizmet', 'serp'], [$fiyat->page_decision, $fiyat->decision_source], 'service pages + a directory lead the top 10');
        $this->assertSame(['blog', 'serp'], [$agri->page_decision, $agri->decision_source], 'articles lead: the AI guess (SSS) is corrected by Google');
        $this->assertSame(3, json_decode($agri->serp_evidence, true)['counts']['blog']);
        $this->assertSame(2, $stats['research']['checked']);

        $sent = Http::recorded()->count();
        app(ClusterPageResearch::class)->researchDue();
        $this->assertSame($sent, Http::recorded()->count(), '30-day cache: no second paid call');

        DB::table('library_query_clusters')->where('id', $fiyat->id)->update(['page_decision' => 'blog', 'decision_source' => 'manual', 'researched_at' => now()->subDays(40)]);
        app(ClusterPageResearch::class)->researchDue();
        $this->assertSame('blog', DB::table('library_query_clusters')->where('id', $fiyat->id)->value('page_decision'), 'operator decision is never overwritten');

        $this->actingAs($this->admin)->get(route('operator.library.search-queries', ['tab' => 'clusters']))->assertOk()
            ->assertSee('İmplant Ağrısı')->assertSee('Google ilk 10')->assertSee('Manuel');
    }

    public function test_clusters_without_serp_keep_the_ai_guess_marked_without_evidence(): void
    {
        $this->enableAi();
        foreach (['implant fiyatı', 'implant fiyatları', 'implant ücreti'] as $text) {
            $this->gsc($this->boundGsc, $text, 1, 50);
        }
        QueryServiceClassifierAgent::fake([['items' => []]]);
        app(QueryPipeline::class)->daily();
        QueryClusterAgent::fake(fn ($prompt): array => ['clusters' => [[
            'name' => 'İmplant Fiyatları', 'head_query_id' => json_decode(Str::after((string) $prompt, "INPUT_JSON\n"), true)['queries'][0]['id'],
            'query_ids' => array_column(json_decode(Str::after((string) $prompt, "INPUT_JSON\n"), true)['queries'], 'id'), 'page_type' => 'hizmet', 'reason' => 'x',
        ]]]);

        app(QueryPipeline::class)->weekly();

        $cluster = DB::table('library_query_clusters')->sole();
        $this->assertSame(['hizmet', 'ai', null], [$cluster->page_decision, $cluster->decision_source, $cluster->serp_evidence]);
        Http::assertNothingSent();
        $this->actingAs($this->admin)->get(route('operator.library.search-queries', ['tab' => 'clusters']))->assertOk()->assertSee('kanıt yok');
    }

    private function linkStatus(SearchQueryLibraryItem $item): ?string
    {
        return DB::table('search_query_library_sectors')->where('search_query_library_item_id', $item->id)->where('service_category_id', $this->dental)->value('match_status');
    }

    private function enableAi(): void
    {
        config(['moxdop.openai.api_key' => 'sk-test', 'moxdop.anthropic.api_key' => 'sk-ant-test']);
    }

    private function resource(string $type, string $externalId, string $name): CoreExternalResource
    {
        return CoreExternalResource::factory()->create([
            'integration_id' => $this->google->id, 'provider' => 'google', 'resource_type' => $type, 'external_id' => $externalId,
            'display_name' => $name, 'metadata' => $type === 'search_console' ? ['site_url' => $externalId] : [], 'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
    }

    private function gsc(CoreExternalResource $resource, string $query, int $clicks, int $impressions): void
    {
        $binding = CoreAssetBinding::query()->where('external_resource_id', $resource->id)->where('status', 'active')->first();
        $this->insertFacts('gsc_query_daily', [
            'digital_asset_id' => $binding?->digital_asset_id, 'external_resource_id' => $resource->id, 'site_url' => $resource->external_id,
            'reporting_date' => now()->subDays(5)->toDateString(), 'query' => $query, 'clicks' => $clicks, 'impressions' => $impressions,
            'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
