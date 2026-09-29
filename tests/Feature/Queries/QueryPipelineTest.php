<?php

namespace Tests\Feature\Queries;

use App\Jobs\Queries\AggregateQuerySourcesJob;
use App\Jobs\Queries\ProcessQueriesJob;
use App\Models\Brand;
use App\Models\BrandCandidate;
use App\Models\Cluster;
use App\Models\ClusterQuery;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\FilterTerm;
use App\Models\Query;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Queries\QueryNormalizer;
use App\Services\Queries\QueryPipeline;
use App\Services\Queries\QuerySourceAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\InsertsFacts;
use Tests\TestCase;

/** MoxDOP v2 Faz 3: query_sources → queries (normalize, filter basket, service assignment, totals) → brand_queries. */
final class QueryPipelineTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    private ServiceCategory $dental;

    private ServiceCategory $hair;

    private ServiceCatalogItem $implant;

    private ServiceCatalogItem $zirkonyum;

    private Brand $brand;

    private CoreExternalResource $gsc;

    private CoreExternalResource $ads;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create(['is_active' => true]);
        $this->dental = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş sağlığı', 'normalized_key' => 'dis sagligi']);
        $this->hair = ServiceCategory::query()->firstOrCreate(['code' => 'hair'], ['name' => 'Saç ekimi', 'normalized_key' => 'sac ekimi']);
        $catalog = app(ServiceCatalogService::class);
        $this->implant = $catalog->resolveOrCreate('Diş İmplantı', 'dental', actor: $admin)['service'];
        $this->zirkonyum = $catalog->resolveOrCreate('Zirkonyum Kaplama', 'dental', actor: $admin)['service'];
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Panorama Ankara', 'sector_id' => $this->dental->id]);
        $site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website']);
        $this->gsc = CoreExternalResource::factory()->searchConsole()->create();
        $this->ads = CoreExternalResource::factory()->create(['resource_type' => 'google_ads', 'external_id' => '1234567890']);
        foreach ([$this->gsc, $this->ads] as $resource) {
            CoreAssetBinding::factory()->create(['digital_asset_id' => $site->id, 'external_resource_id' => $resource->id, 'capability' => $resource->resource_type]);
        }
        foreach (['ankara', 'çankaya'] as $term) {
            FilterTerm::query()->create(['sector_id' => null, 'term' => $term]);
        }
        FilterTerm::query()->create(['sector_id' => $this->dental->id, 'term' => 'panorama']);
    }

    public function test_normalization_is_turkish_lowercase_suffix_tolerant_and_collapses_whitespace(): void
    {
        $normalizer = app(QueryNormalizer::class);

        $this->assertSame('implant fiyatları', $normalizer->normalize('İmplant  Fiyatları Çankayada', null));
        $this->assertSame('implant', $normalizer->normalize("Ankara'da implant?", null));
        $this->assertSame('ışık tedavisi', $normalizer->normalize('IŞIK Tedavisi ankaranın', null));
        $this->assertSame('ankarabank şubesi', $normalizer->normalize('Ankarabank şubesi', null), 'a longer word is not the term + suffix');
        $this->assertSame('panorama implant', $normalizer->normalize('Panorama implant', null), 'sector term is not global');
        $this->assertSame('implant', $normalizer->normalize('Panorama implant', $this->dental->id));
        $this->assertSame('', $normalizer->normalize("panorama ankara'da", $this->dental->id));
    }

    public function test_sources_become_one_query_per_normalized_text_with_totals_and_empty_ones_are_dropped(): void
    {
        $this->source($this->gsc, 'Ankara İmplant', '2026-07-01', impressions: 100, clicks: 10);
        $this->source($this->gsc, 'implant  çankaya', '2026-08-01', impressions: 50, clicks: 5);
        $this->source($this->ads, "implant ankara'da", '2026-08-01', impressions: 30, clicks: 3, cost: 12.5, conversions: 2);
        $this->source($this->gsc, 'panorama ankara', '2026-08-01', impressions: 999, clicks: 99);

        $stats = app(QueryPipeline::class)->run();

        $this->assertSame(1, $stats['queries']);
        $query = Query::query()->sole();
        $this->assertSame('implant', $query->text);
        $this->assertSame(180, $query->impressions);
        $this->assertSame(18, $query->clicks);
        $this->assertEqualsWithDelta(12.5, $query->ads_cost, 0.001);
        $this->assertEqualsWithDelta(2.0, $query->ads_conversions, 0.001);
        $this->assertSame('gsc,google_ads', $query->sources);
        $this->assertSame('2026-07-01', $query->first_seen_on->toDateString());
        $this->assertSame('2026-08-01', $query->last_seen_on->toDateString());
        $this->assertSame($this->dental->id, $query->sector_id);
        $this->assertNull(DB::table('query_sources')->where('raw_query', 'panorama ankara')->value('query_id'));
        $this->assertSame(3, DB::table('query_sources')->where('query_id', $query->id)->count());

        // idempotent
        app(QueryPipeline::class)->run();
        $this->assertSame(1, Query::query()->count());
        $this->assertSame(180, Query::query()->sole()->impressions);
    }

    public function test_new_filter_term_merges_queries_and_removes_the_orphan(): void
    {
        $this->source($this->gsc, 'implant etimesgut', '2026-08-01', impressions: 40, clicks: 4);
        $this->source($this->gsc, 'implant', '2026-08-01', impressions: 60, clicks: 6);
        app(QueryPipeline::class)->run();
        $this->assertSame(2, Query::query()->count());

        FilterTerm::query()->create(['sector_id' => null, 'term' => 'etimesgut']);
        app(QueryPipeline::class)->run();

        $this->assertSame(['implant'], Query::query()->pluck('text')->all());
        $this->assertSame(100, Query::query()->sole()->impressions);
    }

    public function test_unbound_account_takes_the_sector_of_its_brand_candidate(): void
    {
        $unbound = CoreExternalResource::factory()->searchConsole()->create();
        $candidate = BrandCandidate::query()->create(['name' => 'Aday', 'sector_id' => $this->hair->id, 'status' => 'proposed']);
        DB::table('brand_candidate_resources')->insert(['brand_candidate_id' => $candidate->id, 'external_resource_id' => $unbound->id, 'created_at' => now(), 'updated_at' => now()]);
        $orphan = CoreExternalResource::factory()->searchConsole()->create();
        $this->source($unbound, 'fue saç ekimi', '2026-08-01', impressions: 10, clicks: 1);
        $this->source($orphan, 'kaş ekimi', '2026-08-01', impressions: 10, clicks: 1);

        app(QueryPipeline::class)->run();

        $this->assertSame($this->hair->id, Query::query()->where('text', 'fue saç ekimi')->value('sector_id'));
        $this->assertNull(Query::query()->where('text', 'kaş ekimi')->value('sector_id'));
    }

    public function test_longest_matching_keyword_wins_ties_stay_unassigned_and_locked_queries_are_kept(): void
    {
        $keywords = app(ServiceKeywordService::class);
        $keywords->replace($this->implant, "implant\ntek diş implant");
        $keywords->replace($this->zirkonyum, "zirkonyum\nkaplama\nimplant üstü zirkonyum");
        foreach (['implant fiyatları', 'tek diş implantı', 'implant kaplama', 'zirkonyum implant', 'implant üstü zirkonyum fiyatı', 'diş beyazlatma', 'implant ağrısı'] as $text) {
            $this->source($this->gsc, $text, '2026-08-01', impressions: 10, clicks: 1);
        }
        app(QueryPipeline::class)->run();
        $service = fn (string $text): ?int => Query::query()->where('text', $text)->value('service_id');

        $this->assertSame($this->implant->id, $service('implant fiyatları'));
        $this->assertSame($this->implant->id, $service('tek diş implantı'), 'suffix tolerant');
        $this->assertNull($service('implant kaplama'), 'two services tie (same words, same length)');
        $this->assertSame($this->zirkonyum->id, $service('zirkonyum implant'), 'same word count: the longer keyword wins');
        $this->assertSame($this->zirkonyum->id, $service('implant üstü zirkonyum fiyatı'), 'the longest keyword wins over "implant"');
        $this->assertNull($service('diş beyazlatma'));
        $this->assertSame('rule', Query::query()->where('text', 'implant fiyatları')->value('assignment'));

        Query::query()->where('text', 'implant ağrısı')->update(['service_id' => $this->zirkonyum->id, 'assignment' => 'manual', 'locked' => true]);
        app(QueryPipeline::class)->run();
        $this->assertSame($this->zirkonyum->id, $service('implant ağrısı'), 'manual assignment is never overwritten');
    }

    public function test_query_moved_to_another_service_leaves_its_unlocked_cluster_but_locked_clusters_keep_theirs(): void
    {
        app(ServiceKeywordService::class)->replace($this->implant, 'implant');
        $this->source($this->gsc, 'implant fiyatı', '2026-08-01', impressions: 10, clicks: 1);
        $this->source($this->gsc, 'implant ağrısı', '2026-08-01', impressions: 10, clicks: 1);
        app(QueryPipeline::class)->run();
        $open = Cluster::query()->create(['sector_id' => $this->dental->id, 'service_id' => $this->implant->id, 'name' => 'Fiyat']);
        $locked = Cluster::query()->create(['sector_id' => $this->dental->id, 'service_id' => $this->implant->id, 'name' => 'Ağrı', 'locked' => true]);
        ClusterQuery::query()->create(['cluster_id' => $open->id, 'query_id' => Query::query()->where('text', 'implant fiyatı')->value('id')]);
        ClusterQuery::query()->create(['cluster_id' => $locked->id, 'query_id' => Query::query()->where('text', 'implant ağrısı')->value('id')]);

        app(ServiceKeywordService::class)->replace($this->implant, 'dental implant');
        app(QueryPipeline::class)->run();

        $this->assertSame(0, $open->clusterQueries()->count());
        $this->assertSame(1, $locked->clusterQueries()->count());
        $this->assertSame($this->implant->id, Query::query()->where('text', 'implant ağrısı')->value('service_id'));
    }

    public function test_keyword_is_unique_within_a_sector_but_allowed_in_another_sector(): void
    {
        $keywords = app(ServiceKeywordService::class);
        $keywords->replace($this->implant, 'implant');
        $hairService = app(ServiceCatalogService::class)->resolveOrCreate('Saç Ekimi', 'hair')['service'];

        $keywords->add($hairService, 'İmplant');
        $this->assertSame(1, $hairService->matchingKeywords()->count());

        try {
            $keywords->add($this->zirkonyum, 'implant');
            $this->fail('same keyword in the same sector');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Diş İmplantı', $exception->errors()['keyword'][0]);
        }
        $this->expectException(ValidationException::class);
        $keywords->replace($this->zirkonyum, "zirkonyum\nimplant");
    }

    public function test_brand_queries_hold_last_28_days_of_bound_accounts(): void
    {
        foreach ([['2026-08-10', 100, 10, 4.0], ['2026-09-20', 200, 20, 2.0], ['2026-09-25', 200, 10, 4.0]] as [$date, $impressions, $clicks, $position]) {
            $this->insertFacts('gsc_query_page_daily', [
                'digital_asset_id' => null, 'external_resource_id' => $this->gsc->id, 'site_url' => 'sc-domain:klinik.example', 'search_type' => 'web',
                'reporting_date' => $date, 'query' => 'Ankara implant', 'page' => 'https://klinik.example/implant/', 'clicks' => $clicks, 'impressions' => $impressions,
                'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
                'metadata' => json_encode(['provider_average_position' => $position]),
            ]);
        }
        $this->source($this->gsc, 'Ankara implant', '2026-08-01', impressions: 100, clicks: 10);
        $this->source($this->gsc, 'Ankara implant', '2026-09-01', impressions: 400, clicks: 30);
        $unbound = CoreExternalResource::factory()->searchConsole()->create();
        $this->source($unbound, 'implant', '2026-09-01', impressions: 5, clicks: 1);

        app(QueryPipeline::class)->run();

        $row = DB::table('brand_queries')->sole();
        $this->assertSame($this->brand->id, (int) $row->brand_id);
        $this->assertSame(Query::query()->where('text', 'implant')->value('id'), (int) $row->query_id);
        $this->assertSame(400, (int) $row->impressions_28d, 'only 2026-08-29 … 2026-09-25');
        $this->assertSame(30, (int) $row->clicks_28d);
        $this->assertEqualsWithDelta(3.0, (float) $row->position_28d, 0.001);
        $this->assertNull($row->target_area_id);

        app(QueryPipeline::class)->run();
        $this->assertSame(1, DB::table('brand_queries')->count());
    }

    public function test_query_source_aggregation_queues_the_pipeline(): void
    {
        Queue::fake();

        (new AggregateQuerySourcesJob($this->gsc->id, '2026-08-01', '2026-08-31'))->handle(app(QuerySourceAggregator::class));

        Queue::assertPushed(ProcessQueriesJob::class);
        $this->artisan('moxdop:queries:process')->assertSuccessful();
    }

    private function source(CoreExternalResource $resource, string $raw, string $month, int $impressions, int $clicks, ?float $cost = null, ?float $conversions = null): void
    {
        DB::table('query_sources')->insert([
            'external_resource_id' => $resource->id, 'source' => $resource->resource_type === 'google_ads' ? 'google_ads' : 'gsc',
            'raw_query' => $raw, 'month' => $month, 'impressions' => $impressions, 'clicks' => $clicks, 'position' => null,
            'cost' => $cost, 'conversions' => $conversions, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
