<?php

namespace Tests\Feature\Queries;

use App\Ai\Agents\Brain\QueryServiceClassifierAgent;
use App\Enums\CustomerStatus;
use App\Jobs\Queries\AssignQuerySectorsJob;
use App\Jobs\Queries\ClassifyUnmatchedQueriesJob;
use App\Jobs\Queries\ClusterQueriesJob;
use App\Jobs\Queries\ClusterServiceJob;
use App\Jobs\Queries\FinishQueryPipelineJob;
use App\Jobs\Queries\IngestQuerySourcesJob;
use App\Jobs\Queries\MatchQueriesJob;
use App\Jobs\Queries\ResearchQueryClustersJob;
use App\Jobs\Queries\RunQueryPipelineJob;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\SearchQueryLibraryItem;
use App\Models\User;
use App\Services\Queries\QueryClusterer;
use App\Services\Queries\QueryPipeline;
use App\Services\SearchDemand\ServiceCatalogService;
use App\Services\SearchDemand\ServiceKeywordService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Production: RunQueryPipelineJob failed with MaxAttemptsExceededException — one job ran every account and all AI
 * calls with a 3 500 s timeout on a queue whose retry_after is 900 s, so Redis handed it to a second worker. The
 * pipeline is now a chain of short, bounded, idempotent steps on the heavy queue.
 */
final class QueryPipelineQueueTest extends TestCase
{
    use RefreshDatabase;

    private CoreIntegration $google;

    private DigitalAsset $site;

    private int $dental;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->dental = (int) DB::table('service_categories')->where('code', 'dental')->value('id');
        $implant = app(ServiceCatalogService::class)->resolveOrCreate('İmplant Tedavisi', 'dental', actor: $admin)['service'];
        app(ServiceKeywordService::class)->append($implant, ['implant']);
        $this->google = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Panorama Ankara']);
        $brand->sectors()->attach($this->dental);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'primary_url' => 'https://panorama.test/', 'domain' => 'panorama.test']);
        Http::preventStrayRequests();
    }

    public function test_orchestrator_queues_bounded_chunks_then_the_ai_steps_on_the_heavy_queue(): void
    {
        config(['queue.heavy_queue' => 'heavy', 'moxdop-queries.sources_per_job' => 10]);
        for ($i = 0; $i < 23; $i++) {
            $this->resource('google_ads', (string) (5550000000 + $i), 'Hesap '.$i);
        }
        Bus::fake();

        (new RunQueryPipelineJob)->handle(app(QueryPipeline::class));

        Bus::assertChained([
            IngestQuerySourcesJob::class, IngestQuerySourcesJob::class, IngestQuerySourcesJob::class,
            AssignQuerySectorsJob::class, MatchQueriesJob::class, ClassifyUnmatchedQueriesJob::class, FinishQueryPipelineJob::class,
        ]);
        $this->assertSame('heavy', (new IngestQuerySourcesJob('run', []))->queue);
        $this->assertSame('heavy', (new RunQueryPipelineJob)->queue);
        $retryAfter = (int) config('queue.connections.redis.retry_after');
        foreach ([new RunQueryPipelineJob, new IngestQuerySourcesJob('r', []), new AssignQuerySectorsJob('r'), new MatchQueriesJob('r'),
            new ClassifyUnmatchedQueriesJob('r', 10), new FinishQueryPipelineJob('r', null, 'o', 0)] as $job) {
            $this->assertLessThan($retryAfter - 60, $job->timeout, $job::class.' must finish well before the queue hands it to another worker');
        }
        $this->assertFalse(Cache::lock(QueryPipeline::lockKey(null), 10)->get(), 'the pipeline lock is held until the chain finishes');
    }

    public function test_empty_search_console_still_completes_cleanly_and_reports_zero_new_queries(): void
    {
        $gsc = $this->resource('search_console', 'sc-domain:panorama.test', 'panorama.test');
        CoreAssetBinding::query()->create(['digital_asset_id' => $this->site->id, 'external_resource_id' => $gsc->id, 'capability' => 'search_console', 'status' => 'active', 'configuration' => []]);

        RunQueryPipelineJob::dispatch($gsc->id);

        $last = QueryPipeline::lastRun($gsc->id);
        $this->assertNotNull($last, 'the chain reached its last step');
        $this->assertStringStartsWith('0 yeni sorgu', $last['summary']);
        $this->assertSame(1, $last['ingest']['sources']);
        $this->assertTrue(Cache::lock(QueryPipeline::lockKey($gsc->id), 10)->get(), 'lock released at the end');
        $this->artisan('moxdop:queries:pipeline')->expectsOutputToContain('0 yeni sorgu')->assertSuccessful();
    }

    public function test_5000_search_console_queries_are_ingested_with_a_bounded_number_of_statements(): void
    {
        $gsc = $this->resource('search_console', 'sc-domain:panorama.test', 'panorama.test');
        $rows = [];
        for ($i = 0; $i < 5000; $i++) {
            $rows[] = ['digital_asset_id' => $this->site->id, 'external_resource_id' => $gsc->id, 'site_url' => 'sc-domain:panorama.test',
                'reporting_date' => now()->subDays(5 + $i % 30)->toDateString(), 'query' => 'implant sorgu '.$i, 'clicks' => $i % 3, 'impressions' => 10 + $i % 90,
                'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
                'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('gsc_query_daily')->insert($chunk);
        }

        DB::enableQueryLog();
        RunQueryPipelineJob::dispatch();
        $statements = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(5000, SearchQueryLibraryItem::query()->count());
        $this->assertStringStartsWith('5000 yeni sorgu', QueryPipeline::lastRun()['summary']);
        // Per core query only its INSERT remains; metrics / filing / matching run per chunk of 300–500.
        $this->assertLessThan(5000 + 800, $statements);

        // Unchanged facts: the second run skips the account by its fingerprint and creates nothing.
        RunQueryPipelineJob::dispatch();
        $this->assertStringStartsWith('0 yeni sorgu', QueryPipeline::lastRun()['summary']);
        $this->assertSame(0, QueryPipeline::lastRun()['ingest']['ingested']);
    }

    public function test_ai_classification_is_capped_per_job_and_continues_in_the_chain(): void
    {
        config(['moxdop.openai.api_key' => 'fake', 'moxdop.anthropic.api_key' => 'fake', 'moxdop-queries.classify_per_job' => 10]);
        $gsc = $this->resource('search_console', 'sc-domain:panorama.test', 'panorama.test');
        CoreAssetBinding::query()->create(['digital_asset_id' => $this->site->id, 'external_resource_id' => $gsc->id, 'capability' => 'search_console', 'status' => 'active', 'configuration' => []]);
        for ($i = 0; $i < 25; $i++) {
            DB::table('gsc_query_daily')->insert(['digital_asset_id' => $this->site->id, 'external_resource_id' => $gsc->id, 'site_url' => 'sc-domain:panorama.test',
                'reporting_date' => now()->subDays(3)->toDateString(), 'query' => 'hava durumu '.$i, 'clicks' => 0, 'impressions' => 10 + $i,
                'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20), 'created_at' => now(), 'updated_at' => now()]);
        }
        $batches = [];
        QueryServiceClassifierAgent::fake(function ($prompt) use (&$batches): array {
            $input = json_decode(Str::after((string) $prompt, "INPUT_JSON\n"), true);
            $batches[] = count($input['queries']);

            return ['items' => array_map(fn (array $q): array => ['query_id' => $q['id'], 'service_id' => null, 'intent' => 'informational', 'reason' => 'test'], $input['queries'])];
        });

        RunQueryPipelineJob::dispatch($gsc->id);

        $this->assertSame([10, 10, 5], $batches, 'at most classify_per_job queries per job; the next job continues');
        $this->assertSame(25, QueryPipeline::lastRun($gsc->id)['ai']['irrelevant']);
    }

    public function test_clustering_is_split_into_one_job_per_service_on_the_heavy_queue(): void
    {
        config(['queue.heavy_queue' => 'heavy']);
        Bus::fake();

        (new ClusterQueriesJob(42))->handle(app(QueryClusterer::class));

        Bus::assertChained([ClusterServiceJob::class, ResearchQueryClustersJob::class]);
        $this->assertLessThan((int) config('queue.connections.redis.retry_after') - 60, (new ClusterServiceJob(1))->timeout);
    }

    private function resource(string $type, string $externalId, string $name): CoreExternalResource
    {
        return CoreExternalResource::factory()->create([
            'integration_id' => $this->google->id, 'provider' => 'google', 'resource_type' => $type, 'external_id' => $externalId,
            'display_name' => $name, 'metadata' => $type === 'search_console' ? ['site_url' => $externalId] : [], 'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
    }
}
