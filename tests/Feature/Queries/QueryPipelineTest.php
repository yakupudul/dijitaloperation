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
use App\Models\QueryReviewItem;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Queries\QueryNormalizer;
use App\Services\Queries\QueryPipeline;
use App\Services\Queries\QueryRescanner;
use App\Services\Queries\QuerySourceAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\InsertsFacts;
use Tests\TestCase;

/**
 * Sorgular pipeline: query_sources → queries (normalize, negative filter basket, first import with service assignment,
 * totals) → brand_queries. Filter terms delete CONTAINING queries (never stripped) and apply across sectors.
 */
final class QueryPipelineTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    private ServiceCategory $dental;

    private ServiceCategory $hair;

    private ServiceCatalogItem $implant;

    private ServiceCatalogItem $zirkonyum;

    private Brand $brand;

    private DigitalAsset $site;

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
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website']);
        $this->gsc = CoreExternalResource::factory()->searchConsole()->create();
        $this->ads = CoreExternalResource::factory()->create(['resource_type' => 'google_ads', 'external_id' => '1234567890']);
        foreach ([$this->gsc, $this->ads] as $resource) {
            CoreAssetBinding::factory()->create(['digital_asset_id' => $this->site->id, 'external_resource_id' => $resource->id, 'capability' => $resource->resource_type]);
        }
        foreach (['ankara', 'çankaya'] as $term) {
            FilterTerm::query()->create(['sector_id' => null, 'term' => $term]);
        }
        FilterTerm::query()->create(['sector_id' => $this->hair->id, 'term' => 'panorama']);
    }

    public function test_normalization_only_lowercases_and_collapses_and_filter_terms_match_whole_words_across_sectors(): void
    {
        $normalizer = app(QueryNormalizer::class);

        $this->assertSame('implant fiyatları çankayada', $normalizer->normalize('İmplant  Fiyatları Çankayada'), 'nothing is stripped');
        $this->assertSame("ankara'da implant", $normalizer->normalize("Ankara'da implant?"));
        $this->assertSame('ışık tedavisi', $normalizer->normalize('IŞIK Tedavisi'));
        $this->assertSame('çankaya', $normalizer->matchingTerm('implant çankayada'), 'suffix tolerant');
        $this->assertSame('ankara', $normalizer->matchingTerm("Ankara'da implant"));
        $this->assertNull($normalizer->matchingTerm('ankarabank şubesi'), 'a longer word is not the term + suffix');
        $this->assertSame('panorama', $normalizer->matchingTerm('panorama implant'), 'a hair sector term applies to a dental query too');
        $this->assertTrue(QueryNormalizer::containsTerm('tek diş implantı', 'diş implant'));
        $this->assertFalse(QueryNormalizer::containsTerm('implant', 'diş implant'));
    }

    public function test_first_import_deletes_containing_queries_keeps_one_record_per_text_and_assigns_services(): void
    {
        app(ServiceKeywordService::class)->replace($this->implant, 'implant');
        $this->source($this->gsc, 'İmplant  Fiyatları', '2026-07-01', impressions: 100, clicks: 10);
        $this->source($this->gsc, 'implant fiyatları', '2026-08-01', impressions: 50, clicks: 5);
        $this->source($this->ads, 'implant fiyatları?', '2026-08-01', impressions: 30, clicks: 3, cost: 12.5, conversions: 2);
        $this->source($this->gsc, 'implant ankara', '2026-08-01', impressions: 999, clicks: 99);
        $this->source($this->gsc, "çankaya'da diş beyazlatma", '2026-08-01', impressions: 20, clicks: 2);
        $this->assertNull(QueryPipeline::importedAt());

        $stats = app(QueryPipeline::class)->run(import: true);

        $this->assertNotNull(QueryPipeline::importedAt());
        $this->assertSame(1, $stats['queries']);
        $query = Query::query()->sole();
        $this->assertSame('implant fiyatları', $query->text, 'the term is not stripped: "implant ankara" is deleted, not merged');
        $this->assertSame(180, $query->impressions);
        $this->assertSame(18, $query->clicks);
        $this->assertEqualsWithDelta(12.5, $query->ads_cost, 0.001);
        $this->assertSame('gsc,google_ads', $query->sources);
        $this->assertSame('2026-07-01', $query->first_seen_on->toDateString());
        $this->assertSame($this->dental->id, $query->sector_id);
        $this->assertSame($this->implant->id, $query->service_id);
        $this->assertSame('rule', $query->assignment);
        $this->assertNull(DB::table('query_sources')->where('raw_query', 'implant ankara')->value('query_id'));
        $this->assertSame(3, DB::table('query_sources')->where('query_id', $query->id)->count());

        app(QueryPipeline::class)->run();
        $this->assertSame(1, Query::query()->count(), 'routine runs never add to the library');
        $this->assertSame(180, Query::query()->sole()->impressions);
    }

    public function test_routine_run_never_deletes_or_reassigns_library_queries(): void
    {
        $this->source($this->gsc, 'implant etimesgut', '2026-08-01', impressions: 40, clicks: 4);
        app(QueryPipeline::class)->run(import: true);
        $id = Query::query()->where('text', 'implant etimesgut')->value('id');

        FilterTerm::query()->create(['sector_id' => null, 'term' => 'etimesgut']);
        app(ServiceKeywordService::class)->replace($this->implant, 'implant');
        app(QueryPipeline::class)->run();

        $query = Query::query()->find($id);
        $this->assertNotNull($query, 'a new filter term goes through the review, nothing is deleted silently');
        $this->assertNull($query->service_id, 'a keyword change goes through the review, no silent reassignment');
    }

    public function test_asset_sector_override_wins_over_the_brand_and_unbound_accounts_are_not_imported(): void
    {
        $this->assertSame($this->dental->id, $this->site->sectorId());
        $this->site->forceFill(['sector_id' => $this->hair->id])->save();
        $this->assertSame($this->hair->id, $this->site->fresh()->sectorId());
        $this->assertSame('Saç ekimi', $this->site->fresh()->sector()?->name);
        $other = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_business_profile']);
        $this->assertSame('Diş sağlığı', $other->sector()?->name, 'without an override the asset inherits the brand');

        $unbound = CoreExternalResource::factory()->searchConsole()->create();
        $candidate = BrandCandidate::query()->create(['name' => 'Aday', 'sector_id' => $this->dental->id, 'status' => 'proposed']);
        DB::table('brand_candidate_resources')->insert(['brand_candidate_id' => $candidate->id, 'external_resource_id' => $unbound->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->source($this->gsc, 'fue saç ekimi', '2026-08-01', impressions: 10, clicks: 1);
        $this->source($unbound, 'kaş ekimi', '2026-08-01', impressions: 10, clicks: 1);

        app(QueryPipeline::class)->run(import: true);

        $this->assertSame($this->hair->id, Query::query()->where('text', 'fue saç ekimi')->value('sector_id'), 'the asset override is the account sector');
        $this->assertFalse(Query::query()->where('text', 'kaş ekimi')->exists(), 'only accounts bound to brands are imported');
    }

    public function test_nested_longer_keyword_wins_conflicts_stay_unassigned_and_locked_queries_are_kept(): void
    {
        $keywords = app(ServiceKeywordService::class);
        $keywords->replace($this->implant, "implant\ntek diş implant");
        $keywords->replace($this->zirkonyum, "zirkonyum\nkaplama\nimplant üstü zirkonyum");
        foreach (['implant fiyatları', 'tek diş implantı', 'implant kaplama', 'zirkonyum implant', 'implant üstü zirkonyum fiyatı', 'diş beyazlatma', 'implant ağrısı'] as $text) {
            $this->source($this->gsc, $text, '2026-08-01', impressions: 10, clicks: 1);
        }
        app(QueryPipeline::class)->run(import: true);
        $service = fn (string $text): ?int => Query::query()->where('text', $text)->value('service_id');

        $this->assertSame($this->implant->id, $service('implant fiyatları'));
        $this->assertSame($this->implant->id, $service('tek diş implantı'), 'suffix tolerant');
        $this->assertNull($service('implant kaplama'), 'two services, neither keyword contains the other: conflict');
        $this->assertNull($service('zirkonyum implant'), 'not nested: a longer keyword of another service does not win, conflict');
        $this->assertSame($this->zirkonyum->id, $service('implant üstü zirkonyum fiyatı'), 'nested: "implant" ⊂ "implant üstü zirkonyum", the longer keyword wins');
        $this->assertNull($service('diş beyazlatma'));

        Query::query()->where('text', 'implant ağrısı')->update(['service_id' => $this->zirkonyum->id, 'assignment' => 'manual', 'locked' => true]);
        $review = app(QueryRescanner::class)->scan(null);
        $this->assertFalse($review->items()->where('query_id', Query::query()->where('text', 'implant ağrısı')->value('id'))->exists(), 'manual assignment is never proposed for change');
    }

    public function test_approved_rescan_moves_a_query_out_of_its_unlocked_cluster_but_locked_clusters_keep_theirs(): void
    {
        app(ServiceKeywordService::class)->replace($this->implant, 'implant');
        $this->source($this->gsc, 'implant fiyatı', '2026-08-01', impressions: 10, clicks: 1);
        $this->source($this->gsc, 'implant ağrısı', '2026-08-01', impressions: 10, clicks: 1);
        app(QueryPipeline::class)->run(import: true);
        $open = Cluster::query()->create(['sector_id' => $this->dental->id, 'service_id' => $this->implant->id, 'name' => 'Fiyat']);
        $locked = Cluster::query()->create(['sector_id' => $this->dental->id, 'service_id' => $this->implant->id, 'name' => 'Ağrı', 'locked' => true]);
        ClusterQuery::query()->create(['cluster_id' => $open->id, 'query_id' => Query::query()->where('text', 'implant fiyatı')->value('id')]);
        ClusterQuery::query()->create(['cluster_id' => $locked->id, 'query_id' => Query::query()->where('text', 'implant ağrısı')->value('id')]);

        app(ServiceKeywordService::class)->replace($this->implant, 'dental implant');
        $rescanner = app(QueryRescanner::class);
        $review = $rescanner->scan(null);
        $this->assertSame(1, $review->changes, 'the locked cluster query is not proposed');
        $rescanner->apply($review->items()->pluck('id')->all());

        $this->assertSame(0, $open->clusterQueries()->count());
        $this->assertSame(1, $locked->clusterQueries()->count());
        $this->assertNull(Query::query()->where('text', 'implant fiyatı')->value('service_id'));
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
                'reporting_date' => $date, 'query' => 'İmplant fiyatı', 'page' => 'https://klinik.example/implant/', 'clicks' => $clicks, 'impressions' => $impressions,
                'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
                'metadata' => json_encode(['provider_average_position' => $position]),
            ]);
        }
        $this->source($this->gsc, 'İmplant fiyatı', '2026-08-01', impressions: 100, clicks: 10);
        $this->source($this->gsc, 'İmplant fiyatı', '2026-09-01', impressions: 400, clicks: 30);

        app(QueryPipeline::class)->run(import: true);

        $row = DB::table('brand_queries')->sole();
        $this->assertSame($this->brand->id, (int) $row->brand_id);
        $this->assertSame(Query::query()->where('text', 'implant fiyatı')->value('id'), (int) $row->query_id);
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

        Queue::assertPushed(ProcessQueriesJob::class, fn (ProcessQueriesJob $job): bool => ! $job->import);
        $this->artisan('moxdop:queries:process')->assertSuccessful();
        $this->assertSame(0, QueryReviewItem::query()->count());
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
