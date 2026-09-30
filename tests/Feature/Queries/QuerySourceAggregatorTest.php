<?php

namespace Tests\Feature\Queries;

use App\Enums\Collection\CollectionRunStatus;
use App\Events\Collection\CollectionRunCompleted;
use App\Jobs\Queries\AggregateQuerySourcesJob;
use App\Listeners\Collection\AggregateQuerySourcesAfterCollection;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreExternalResource;
use App\Models\ResourceAutomation;
use App\Services\Operations\Diagnostics\PortfolioDiagnostics;
use App\Services\Queries\QuerySourceAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\InsertsFacts;
use Tests\TestCase;

/** MoxDOP v2 Faz 1: the raw query layer `query_sources` — per account × raw query × month, idempotent. */
final class QuerySourceAggregatorTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    public function test_search_console_query_page_rows_become_monthly_query_rows_with_weighted_position(): void
    {
        $gsc = CoreExternalResource::factory()->searchConsole()->create();
        $this->gsc($gsc->id, '2026-08-03', 'ankara implant', '/implant/', impressions: 100, clicks: 5, position: 4.0);
        $this->gsc($gsc->id, '2026-08-20', 'ankara implant', '/fiyat/', impressions: 300, clicks: 3, position: 8.0);
        $this->gsc($gsc->id, '2026-08-21', 'diş beyazlatma', '/beyazlatma/', impressions: 50, clicks: 2, position: 2.5);
        $this->gsc($gsc->id, '2026-09-02', 'ankara implant', '/implant/', impressions: 70, clicks: 7, position: 3.0);
        $this->gsc($gsc->id, '2026-08-05', 'implant foto', '/implant/', impressions: 900, clicks: 1, position: 1.0, searchType: 'image');

        $stats = app(QuerySourceAggregator::class)->aggregate($gsc, '2026-08-01', '2026-09-30');

        $this->assertSame(['months' => 2, 'rows' => 3], $stats);
        $august = DB::table('query_sources')->where('month', '2026-08-01')->where('raw_query', 'ankara implant')->sole();
        $this->assertSame('gsc', $august->source);
        $this->assertSame(400, (int) $august->impressions);
        $this->assertSame(8, (int) $august->clicks);
        $this->assertEqualsWithDelta(7.0, (float) $august->position, 0.001, '(4×100 + 8×300) / 400');
        $this->assertNull($august->cost);
        $this->assertSame(70, (int) DB::table('query_sources')->where('month', '2026-09-01')->where('raw_query', 'ankara implant')->value('impressions'));
        $this->assertFalse(DB::table('query_sources')->where('raw_query', 'implant foto')->exists(), 'image search is not a query source');
    }

    public function test_aggregation_is_idempotent_and_drops_queries_that_left_the_month(): void
    {
        $gsc = CoreExternalResource::factory()->searchConsole()->create();
        $this->gsc($gsc->id, '2026-08-03', 'kanal tedavisi', '/kanal/', impressions: 10, clicks: 1, position: 5.0);
        $this->gsc($gsc->id, '2026-08-04', 'zirkonyum', '/zirkonyum/', impressions: 20, clicks: 2, position: 6.0);
        $aggregator = app(QuerySourceAggregator::class);
        $aggregator->aggregate($gsc, '2026-08-01', '2026-08-31');
        DB::table('query_sources')->where('raw_query', 'kanal tedavisi')->update(['query_id' => null]);
        $before = DB::table('query_sources')->orderBy('raw_query')->get(['id', 'raw_query', 'impressions', 'clicks', 'position'])->toArray();

        $aggregator->aggregate($gsc, '2026-08-01', '2026-08-31');
        $this->assertEquals($before, DB::table('query_sources')->orderBy('raw_query')->get(['id', 'raw_query', 'impressions', 'clicks', 'position'])->toArray(), 'same rows, same ids');

        if (DB::getDriverName() === 'pgsql') {
            // On PostgreSQL gsc_query_page_daily is a view over the compact fact table.
            DB::table('gsc_f_query_page')->whereIn('d1', DB::table('fact_dims')->where('value', 'zirkonyum')->select('id'))->delete();
        } else {
            DB::table('gsc_query_page_daily')->where('query', 'zirkonyum')->delete();
        }
        $aggregator->aggregate($gsc, '2026-08-01', '2026-08-31');
        $this->assertSame(['kanal tedavisi'], DB::table('query_sources')->pluck('raw_query')->all());
    }

    public function test_google_ads_search_terms_and_business_profile_keywords_are_aggregated_monthly(): void
    {
        $ads = CoreExternalResource::factory()->create(['resource_type' => 'google_ads', 'external_id' => '1234567890']);
        $this->searchTerm($ads->id, '2026-07-10', 'implant fiyatları', impressions: 40, clicks: 4, cost: 12.5, conversions: 1);
        $this->searchTerm($ads->id, '2026-07-11', 'implant fiyatları', impressions: 60, clicks: 6, cost: 7.5, conversions: 2);
        $gbp = CoreExternalResource::factory()->create(['resource_type' => 'google_business_profile', 'external_id' => 'locations/1']);
        foreach ([['diş hekimi ankara', 120], ['panorama diş', null]] as [$keyword, $impressions]) {
            DB::table('gbp_search_keywords_monthly')->insert([
                'digital_asset_id' => 1, 'external_resource_id' => $gbp->id, 'run_id' => 1, 'location_name' => 'locations/1',
                'month_start' => '2026-07-01', 'search_keyword' => $keyword, 'search_keyword_hash' => hash('sha256', $keyword),
                'impressions' => $impressions, 'threshold' => $impressions === null ? 15 : null, 'collected_at' => now(),
            ]);
        }
        $aggregator = app(QuerySourceAggregator::class);

        $aggregator->aggregate($ads, '2026-07-01', '2026-07-31');
        $aggregator->aggregate($gbp, '2026-07-01', '2026-07-31');

        $term = DB::table('query_sources')->where('external_resource_id', $ads->id)->sole();
        $this->assertSame(['google_ads', 'implant fiyatları', 100, 10], [$term->source, $term->raw_query, (int) $term->impressions, (int) $term->clicks]);
        $this->assertEqualsWithDelta(20.0, (float) $term->cost, 0.001);
        $this->assertEqualsWithDelta(3.0, (float) $term->conversions, 0.001);
        $keywords = DB::table('query_sources')->where('external_resource_id', $gbp->id)->pluck('impressions', 'raw_query')->map(fn ($v): int => (int) $v)->all();
        $this->assertSame(['diş hekimi ankara' => 120, 'panorama diş' => 0], $keywords, 'a value under the threshold has no number');
        $this->assertSame(['gbp'], DB::table('query_sources')->where('external_resource_id', $gbp->id)->distinct()->pluck('source')->all());
    }

    public function test_backfill_command_covers_every_discovered_query_account(): void
    {
        $gsc = CoreExternalResource::factory()->searchConsole()->create();
        $ga4 = CoreExternalResource::factory()->create(['resource_type' => 'ga4']);
        $this->gsc($gsc->id, now()->subMonths(2)->startOfMonth()->addDays(3)->toDateString(), 'diş kliniği', '/', impressions: 5, clicks: 1, position: 3.0);

        $this->artisan('moxdop:queries:sources', ['--all' => true, '--months' => 16])
            ->expectsOutputToContain('Toplam 1 hesap')
            ->assertSuccessful();
        $this->assertSame(1, DB::table('query_sources')->where('external_resource_id', $gsc->id)->count());
        $this->assertSame(0, DB::table('query_sources')->where('external_resource_id', $ga4->id)->count());

        $this->artisan('moxdop:queries:sources', ['--resource' => [$gsc->id]])->assertSuccessful();
        $this->assertSame(1, DB::table('query_sources')->count(), 'idempotent');
        $this->artisan('moxdop:queries:sources')->assertExitCode(2);
    }

    public function test_a_completed_collection_queues_the_months_it_wrote(): void
    {
        Queue::fake();
        $gsc = CoreExternalResource::factory()->searchConsole()->create();
        $run = CollectionRun::factory()->create(['status' => CollectionRunStatus::Completed]);
        $resourceRun = CollectionResourceRun::factory()->create(['collection_run_id' => $run->id, 'provider_or_source' => 'SEARCH_CONSOLE',
            'external_resource_id' => $gsc->id, 'digital_asset_id' => null, 'status' => CollectionRunStatus::Completed]);
        foreach ([['gsc_query_page_daily', '2026-06-10', '2026-07-20', 'web'], ['gsc_query_page_daily', '2026-07-21', '2026-08-02', 'image'], ['gsc_property_daily', '2025-01-01', '2026-08-02', 'web']] as [$dataset, $start, $end, $variant]) {
            CollectionDatasetRun::factory()->create(['collection_run_id' => $run->id, 'collection_resource_run_id' => $resourceRun->id, 'execution_variant' => $variant,
                'provider_or_source' => 'SEARCH_CONSOLE', 'dataset_contract_id' => $dataset, 'request_family_id' => 'GSC_CENTRAL_RF_SEARCH_ANALYTICS',
                'status' => CollectionRunStatus::Completed, 'metadata' => ['date_range' => ['start' => $start, 'end' => $end]]]);
        }

        CollectionRunCompleted::dispatch($run->fresh());

        Queue::assertPushed(AggregateQuerySourcesJob::class, 1);
        Queue::assertPushed(AggregateQuerySourcesJob::class, fn (AggregateQuerySourcesJob $job): bool => $job->externalResourceId === $gsc->id
            && $job->from === '2026-06-10' && $job->to === '2026-08-02');
    }

    public function test_business_profile_keywords_feed_query_sources_and_the_listener_is_registered_once(): void
    {
        $listeners = collect(app('events')->getRawListeners()[CollectionRunCompleted::class] ?? [])
            ->filter(fn ($listener): bool => $listener === AggregateQuerySourcesAfterCollection::class);
        $this->assertCount(1, $listeners, 'explicit registration only, no auto-discovery duplicate');
        $this->assertSame(['product_brands'], array_keys((array) config('moxdop-queries')));

        Queue::fake();
        $gbp = CoreExternalResource::factory()->create(['resource_type' => 'google_business_profile', 'external_id' => 'locations/123']);
        $run = CollectionRun::factory()->create(['status' => CollectionRunStatus::Completed]);
        $resourceRun = CollectionResourceRun::factory()->create(['collection_run_id' => $run->id, 'provider_or_source' => 'GOOGLE_BUSINESS_PROFILE',
            'external_resource_id' => $gbp->id, 'digital_asset_id' => null, 'status' => CollectionRunStatus::Completed]);
        CollectionDatasetRun::factory()->create(['collection_run_id' => $run->id, 'collection_resource_run_id' => $resourceRun->id,
            'provider_or_source' => 'GOOGLE_BUSINESS_PROFILE', 'dataset_contract_id' => 'gbp_search_keywords_monthly', 'request_family_id' => 'GBP_SEARCH_KEYWORDS',
            'status' => CollectionRunStatus::Completed, 'metadata' => ['date_range' => ['start' => '2026-07-01', 'end' => '2026-08-31']]]);

        CollectionRunCompleted::dispatch($run->fresh());

        Queue::assertPushed(AggregateQuerySourcesJob::class, 1);
        Queue::assertPushed(AggregateQuerySourcesJob::class, fn (AggregateQuerySourcesJob $job): bool => $job->externalResourceId === $gbp->id
            && $job->from === '2026-07-01' && $job->to === '2026-08-31');
    }

    public function test_diagnose_reports_query_sources_and_every_discovered_account(): void
    {
        $gsc = CoreExternalResource::factory()->searchConsole()->create(['display_name' => 'Bağsız mülk']);
        ResourceAutomation::query()->create(['external_resource_id' => $gsc->id, 'collection_status' => 'current', 'last_collection_success_at' => now()]);
        $this->gsc($gsc->id, '2026-08-03', 'ankara implant', '/implant/', impressions: 100, clicks: 5, position: 4.0);
        app(QuerySourceAggregator::class)->aggregate($gsc, '2026-08-01', '2026-08-31');

        $lines = implode("\n", app(PortfolioDiagnostics::class)->run(['sections' => ['collection']])['sections']['collection']['lines']);

        $this->assertStringContainsString('Sorgu kaynakları gsc: 1 satır · 1 hesap · son ay 2026-08', $lines);
        $this->assertStringContainsString('#'.$gsc->id.' Bağsız mülk (search_console): 1 sorgu×ay · son ay 2026-08', $lines);
        $this->assertStringContainsString('Hesaplar search_console bağsız: current=1', $lines);
        $this->assertStringContainsString('Katalog GA4 (4): ga4_property_metadata, ga4_property_daily, ga4_landing_source_daily, ga4_key_event_daily', $lines);
    }

    private function gsc(int $resourceId, string $date, string $query, string $path, int $impressions, int $clicks, float $position, string $searchType = 'web'): void
    {
        $this->insertFacts('gsc_query_page_daily', [
            'digital_asset_id' => null, 'external_resource_id' => $resourceId, 'site_url' => 'sc-domain:klinik.example', 'search_type' => $searchType,
            'reporting_date' => $date, 'query' => $query, 'page' => 'https://klinik.example'.$path, 'clicks' => $clicks, 'impressions' => $impressions,
            'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
            'metadata' => json_encode(['provider_average_position' => $position]),
        ]);
    }

    private function searchTerm(int $resourceId, string $date, string $term, int $impressions, int $clicks, float $cost, int $conversions): void
    {
        $this->insertFacts('google_ads_search_term_daily', [
            'digital_asset_id' => null, 'external_resource_id' => $resourceId, 'customer_id' => '1234567890', 'reporting_date' => $date,
            'search_term' => $term, 'impressions' => $impressions, 'clicks' => $clicks, 'cost_micros' => (int) ($cost * 1_000_000),
            'conversions' => $conversions, 'cost_amount' => $cost, 'currency' => 'TRY', 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
        ]);
    }
}
