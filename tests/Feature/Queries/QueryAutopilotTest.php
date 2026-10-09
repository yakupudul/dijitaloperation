<?php

namespace Tests\Feature\Queries;

use App\Ai\Agents\QueryTriageAgent;
use App\Jobs\Queries\ClusterQueriesJob;
use App\Jobs\Queries\QueryAutopilotJob;
use App\Livewire\Operator\Library\QueriesPage;
use App\Models\ClusterQuery;
use App\Models\FilterTerm;
use App\Models\PendingQuery;
use App\Models\Query;
use App\Models\QueryReviewItem;
use App\Models\ServiceCategory;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Queries\QueryAutopilot;
use App\Services\Queries\QueryClusterQueue;
use App\Services\Queries\QueryNormalizer;
use App\Services\Queries\QueryPipeline;
use App\Services\Queries\QueryRescanner;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Site\SiteTestCase;

/** Sorgu otomatik pilotu: triage once per query, filter learning, Bekleyenler, daily clustering, hourly clean-up. */
final class QueryAutopilotTest extends SiteTestCase
{
    private function libraryQuery(string $text, ?int $serviceId = null): Query
    {
        return Query::query()->create(['text' => $text, 'text_hash' => QueryNormalizer::hash($text), 'sector_id' => $this->dental->id,
            'service_id' => $serviceId, 'assignment' => $serviceId === null ? 'none' : 'rule', 'impressions' => 10]);
    }

    public function test_ai_assigns_learns_filter_terms_safely_and_never_asks_a_query_twice(): void
    {
        $this->enableAi();
        Queue::fake([ClusterQueriesJob::class]);
        app(ServiceKeywordService::class)->replace($this->implant, 'implant');
        app(ServiceKeywordService::class)->replace($this->zirkonyum, 'zirkonyum');
        $this->assertSame('off', app(QueryAutopilot::class)->tick(), 'not before the first import');
        QueryPipeline::markImported();

        $kaplama = $this->libraryQuery('porselen diş kaplama');
        $doctor = $this->libraryQuery('dr ahmet yılmaz diş');
        $product = $this->libraryQuery('straumann ankara');
        $generic = $this->libraryQuery('diş fiyatları ankara');
        $exam = $this->libraryQuery('kpss sonuçları');
        $vague = $this->libraryQuery('implant ne kadar sürer');
        $calls = [];
        QueryTriageAgent::fake(function (string $prompt) use (&$calls, $kaplama, $doctor, $product, $generic, $exam, $vague): array {
            $calls[] = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['decisions' => [
                ['query_id' => $kaplama->id, 'service_id' => $this->zirkonyum->id, 'filter_term' => null, 'filter_reason' => 'none'],
                ['query_id' => $doctor->id, 'service_id' => null, 'filter_term' => 'dr ahmet yılmaz', 'filter_reason' => 'person_name'],
                ['query_id' => $product->id, 'service_id' => null, 'filter_term' => 'straumann', 'filter_reason' => 'brand_name'],
                ['query_id' => $vague->id, 'service_id' => null, 'filter_term' => 'implant', 'filter_reason' => 'irrelevant'],
                ['query_id' => $generic->id, 'service_id' => null, 'filter_term' => 'ankara', 'filter_reason' => 'place'],
                ['query_id' => $exam->id, 'service_id' => null, 'filter_term' => 'kpss', 'filter_reason' => 'irrelevant'],
            ], 'keywords' => [['service_id' => $this->zirkonyum->id, 'keyword' => 'diş kaplama'], ['service_id' => $this->zirkonyum->id, 'keyword' => 'fiyat']]];
        });

        $this->assertSame('clustering', app(QueryAutopilot::class)->tick());

        $this->assertCount(1, $calls);
        $this->assertSame('Diş sağlığı', $calls[0]['sector']['name']);
        $this->assertSame([$this->zirkonyum->id, 'ai', true], [$kaplama->fresh()->service_id, $kaplama->fresh()->assignment, $kaplama->fresh()->locked]);
        $this->assertEqualsCanonicalizing(['dr ahmet yılmaz', 'straumann', 'ankara', 'kpss'], FilterTerm::query()->where('source', 'ai')->pluck('term')->all(),
            'person, product brand, place, off-topic word in; a service word never');
        $this->assertSame($this->dental->id, FilterTerm::query()->where('term', 'kpss')->value('sector_id'));
        $this->assertTrue($this->zirkonyum->fresh()->matchingKeywords->contains('label', 'diş kaplama'));
        $this->assertFalse($this->zirkonyum->fresh()->matchingKeywords->contains('label', 'fiyat'), 'generic keyword dropped');
        $this->assertSame(0, Query::query()->whereNull('ai_checked_at')->whereNull('service_id')->count());
        $this->assertTrue(Query::query()->whereKey($doctor->id)->exists(), 'deleting waits for the hourly clean-up');
        Queue::assertPushed(ClusterQueriesJob::class);
        $this->assertSame('running', QueryClusterQueue::state()['status']);

        // Nothing new: no second AI call; clustering only once a day.
        cache()->forget(QueryClusterQueue::KEY);
        $this->assertSame('idle', app(QueryAutopilot::class)->tick());
        $this->assertCount(1, $calls);

        // Bekleyenler comes in when the library is done; its new unplaced query is asked in the next round.
        PendingQuery::query()->create(['text' => 'zirkonyum fiyat', 'text_hash' => QueryNormalizer::hash('zirkonyum fiyat'), 'status' => PendingQuery::PENDING, 'sector_id' => $this->dental->id]);
        PendingQuery::query()->create(['text' => 'gülüş tasarımı', 'text_hash' => QueryNormalizer::hash('gülüş tasarımı'), 'status' => PendingQuery::PENDING, 'sector_id' => $this->dental->id]);
        $this->assertSame('imported', app(QueryAutopilot::class)->tick());
        $this->assertSame($this->zirkonyum->id, Query::query()->where('text', 'zirkonyum fiyat')->value('service_id'));
        $this->assertNull(Query::query()->where('text', 'gülüş tasarımı')->value('ai_checked_at'));

        // Paused: nothing happens.
        QueryAutopilot::setPaused(true);
        $this->assertSame('off', app(QueryAutopilot::class)->tick());
        $this->assertCount(1, $calls);
    }

    public function test_clustering_starts_once_a_day_even_while_triage_still_has_queries(): void
    {
        $this->enableAi();
        Queue::fake([ClusterQueriesJob::class]);
        QueryPipeline::markImported();
        $this->libraryQuery('implant fiyatları', $this->implant->id);
        $this->libraryQuery('porselen diş kaplama');
        QueryTriageAgent::fake(fn (): array => throw new \RuntimeException('provider down'));

        $this->assertSame('more', app(QueryAutopilot::class)->tick(), 'triage is not finished');

        Queue::assertPushed(ClusterQueriesJob::class, fn (ClusterQueriesJob $job): bool => $job->serviceId === $this->implant->id);
        $this->assertSame('running', QueryClusterQueue::state()['status']);
    }

    public function test_the_hourly_clean_up_applies_filter_deletions_and_keeps_what_the_operator_kept(): void
    {
        QueryPipeline::markImported();
        $exam = $this->libraryQuery('kpss sonuçları');
        $kept = $this->libraryQuery('kpss tercih');
        FilterTerm::query()->create(['sector_id' => $this->dental->id, 'term' => 'kpss', 'source' => 'ai']);
        app(QueryRescanner::class)->scan(null);
        app(QueryRescanner::class)->keep(QueryReviewItem::query()->where('query_id', $kept->id)->pluck('id')->all());

        $result = app(QueryAutopilot::class)->clean();

        $this->assertSame(1, $result['deleted']);
        $this->assertFalse(Query::query()->whereKey($exam->id)->exists());
        $this->assertTrue(Query::query()->whereKey($kept->id)->exists(), '"Tut" lines stay');
        $this->assertSame(PendingQuery::DELETED, PendingQuery::query()->where('text_hash', $exam->text_hash)->value('status'));
    }

    public function test_a_sector_filter_term_deletes_only_that_sectors_queries(): void
    {
        QueryPipeline::markImported();
        $scrap = ServiceCategory::query()->firstOrCreate(['code' => 'recycling'], ['name' => 'Geri dönüşüm', 'normalized_key' => 'geri donusum']);
        // Diş sağlığı filed "hurda" as irrelevant; Geri dönüşüm lives on it, a general term applies everywhere.
        FilterTerm::query()->create(['sector_id' => $this->dental->id, 'term' => 'hurda', 'source' => 'ai']);
        FilterTerm::query()->create(['sector_id' => null, 'term' => 'forum', 'source' => 'manual']);
        $dentalScrap = $this->libraryQuery('hurda diş teli');
        $dentalForum = $this->libraryQuery('implant forum');
        $izmir = Query::query()->create(['text' => 'hurda fiyatları bugün', 'text_hash' => QueryNormalizer::hash('hurda fiyatları bugün'), 'sector_id' => $scrap->id, 'assignment' => 'none']);
        $scrapForum = Query::query()->create(['text' => 'hurda forum', 'text_hash' => QueryNormalizer::hash('hurda forum'), 'sector_id' => $scrap->id, 'assignment' => 'none']);

        $normalizer = new QueryNormalizer;
        $this->assertSame('hurda', $normalizer->matchingTerm('hurda diş teli', $this->dental->id));
        $this->assertNull($normalizer->matchingTerm('hurda fiyatları bugün', $scrap->id), 'another sector\'s term never applies');
        $this->assertNull($normalizer->matchingTerm('hurda fiyatları bugün', null), 'a query without a sector meets general terms only');
        $this->assertSame('forum', $normalizer->matchingTerm('hurda forum', $scrap->id));

        app(QueryAutopilot::class)->clean();

        $this->assertFalse(Query::query()->whereKey($dentalScrap->id)->exists());
        $this->assertFalse(Query::query()->whereKey($dentalForum->id)->exists());
        $this->assertFalse(Query::query()->whereKey($scrapForum->id)->exists(), 'general terms apply to every sector');
        $this->assertTrue(Query::query()->whereKey($izmir->id)->exists(), 'the scrap queries of Geri dönüşüm stay');
    }

    public function test_triage_never_files_a_service_word_of_another_sector(): void
    {
        $this->enableAi();
        Queue::fake([ClusterQueriesJob::class]);
        QueryPipeline::markImported();
        ServiceCategory::query()->firstOrCreate(['code' => 'recycling'], ['name' => 'Geri dönüşüm', 'normalized_key' => 'geri donusum']);
        app(ServiceCatalogService::class)->resolveOrCreate('Hurda', 'recycling', actor: $this->admin);
        $scrap = $this->libraryQuery('hurda diş teli');
        $exam = $this->libraryQuery('kpss diş');
        QueryTriageAgent::fake(fn (): array => ['decisions' => [
            ['query_id' => $scrap->id, 'service_id' => null, 'filter_term' => 'hurda', 'filter_reason' => 'irrelevant'],
            ['query_id' => $exam->id, 'service_id' => null, 'filter_term' => 'kpss', 'filter_reason' => 'irrelevant'],
        ], 'keywords' => []]);

        app(QueryAutopilot::class)->tick();

        $this->assertSame(['kpss'], FilterTerm::query()->where('source', 'ai')->pluck('term')->all(), '"hurda" is a service of Geri dönüşüm');
    }

    public function test_a_query_clustering_looked_at_is_not_queued_again_and_the_screen_shows_the_pilot(): void
    {
        $query = $this->libraryQuery('implant fiyatları', $this->implant->id);
        $this->assertSame([$this->implant->id], app(QueryClusterQueue::class)->servicesToCluster());
        $query->forceFill(['cluster_checked_at' => now()])->save();
        $this->assertSame([], app(QueryClusterQueue::class)->servicesToCluster());

        QueryPipeline::markImported();
        Queue::fake();
        Livewire::test(QueriesPage::class)->assertSee('Otomatik pilot')->assertSee('açık')
            ->call('toggleAutopilot')->assertSee('durduruldu');
        $this->assertTrue(QueryAutopilot::paused());
    }

    public function test_the_clean_up_deletes_unlocked_clusters_left_without_queries_and_keeps_locked_ones(): void
    {
        QueryPipeline::markImported();
        $emptied = $this->cluster($this->implant, 'KPSS implant', ['kpss implant', 'kpss implant fiyat']);
        $suggested = Query::query()->create(['text' => 'kpss implant rehberi', 'text_hash' => QueryNormalizer::hash('kpss implant rehberi'), 'sector_id' => $this->dental->id,
            'service_id' => $this->implant->id, 'assignment' => 'ai', 'is_suggested' => true]);
        ClusterQuery::query()->create(['cluster_id' => $emptied->id, 'query_id' => $suggested->id, 'is_suggested' => true]);
        $locked = $this->cluster($this->implant, 'KPSS elle', ['kpss diş']);
        $locked->forceFill(['locked' => true])->save();
        $kept = $this->cluster($this->implant, 'İmplant fiyatları', ['implant fiyatları', 'kpss implant tedavisi']);
        Query::query()->where('text', 'implant fiyatları')->update(['locked' => true]);
        FilterTerm::query()->create(['sector_id' => $this->dental->id, 'term' => 'kpss', 'source' => 'ai']);

        app(QueryAutopilot::class)->clean();

        $this->assertNull($emptied->fresh(), 'only a suggested query left: deleted');
        $this->assertFalse(Query::query()->whereKey($suggested->id)->exists(), 'its suggested query goes too');
        $this->assertNotNull($locked->fresh(), 'locked clusters stay even when empty');
        $this->assertNotNull($kept->fresh());
        $this->assertSame(['implant fiyatları'], Query::query()->whereIn('id', $kept->clusterQueries()->select('query_id'))->pluck('text')->all());
    }

    public function test_fifty_new_filter_terms_start_the_clean_up_at_once_and_the_next_one_waits_for_fifty_more(): void
    {
        QueryPipeline::markImported();
        Queue::fake([QueryAutopilotJob::class]);
        $add = function (int $count, string $prefix): void {
            foreach (range(1, $count) as $i) {
                FilterTerm::query()->create(['sector_id' => $this->dental->id, 'term' => $prefix.$i, 'source' => 'ai']);
            }
        };
        app(QueryAutopilot::class)->clean();
        $add(49, 'terim');
        $this->assertFalse(app(QueryAutopilot::class)->cleanIfDue());
        $add(1, 'son');
        $this->assertSame(50, QueryAutopilot::newFilterTerms());
        $this->assertTrue(app(QueryAutopilot::class)->cleanIfDue());
        Queue::assertPushed(QueryAutopilotJob::class, fn (QueryAutopilotJob $job): bool => $job->clean);
        $this->assertSame(0, QueryAutopilot::newFilterTerms());
        $add(10, 'yeni');
        $this->assertFalse(app(QueryAutopilot::class)->cleanIfDue(), 'next forced clean-up after 50 more');
        Queue::assertPushed(QueryAutopilotJob::class, 1);

        // The hourly clean-up also restarts the count.
        app(QueryAutopilot::class)->clean();
        $this->assertSame(0, QueryAutopilot::newFilterTerms());
    }
}
