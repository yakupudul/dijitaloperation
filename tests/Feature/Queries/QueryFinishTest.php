<?php

namespace Tests\Feature\Queries;

use App\Ai\Agents\QueryTriageAgent;
use App\Jobs\Queries\ClusterQueriesJob;
use App\Jobs\Queries\FinishQueriesJob;
use App\Jobs\Site\RunSiteOperationJob;
use App\Livewire\Operator\Library\QueriesPage;
use App\Models\Query;
use App\Services\Ai\AiAssignments;
use App\Services\Prompts\PromptRegistry;
use App\Services\Queries\QueryAutopilot;
use App\Services\Queries\QueryClusterQueue;
use App\Services\Queries\QueryFinish;
use App\Services\Queries\QueryNormalizer;
use App\Services\Queries\QueryPipeline;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Site\SiteTestCase;

/** moxdop:queries:finish: one queued chain from catalog clean-up to the check shown on the queries page. */
final class QueryFinishTest extends SiteTestCase
{
    public function test_the_command_only_queues_the_chain(): void
    {
        Queue::fake();

        $this->artisan('moxdop:queries:finish')->expectsOutputToContain('kuyruğa alındı')->assertSuccessful();

        Queue::assertPushed(FinishQueriesJob::class, fn (FinishQueriesJob $job): bool => $job->stage === 'clean');
        $this->assertSame('queued', QueryFinish::state()['stage']);
    }

    public function test_the_chain_asks_seen_unassigned_queries_again_clusters_and_records_the_check(): void
    {
        $this->enableAi();
        config(['moxdop-ai-pricing.claude_api_window.until' => now('Europe/Istanbul')->addDays(10)->toDateString()]);
        Queue::fake([ClusterQueriesJob::class, RunSiteOperationJob::class]);
        QueryPipeline::markImported();
        $seen = Query::query()->create(['text' => 'zirkonyum kaplama fiyatı', 'text_hash' => QueryNormalizer::hash('zirkonyum kaplama fiyatı'),
            'sector_id' => $this->dental->id, 'assignment' => 'none', 'ai_checked_at' => now()->subWeek(), 'impressions' => 40]);
        $calls = 0;
        QueryTriageAgent::fake(function () use (&$calls, $seen): array {
            $calls++;

            return ['decisions' => [['query_id' => $seen->id, 'service_id' => $this->zirkonyum->id, 'filter_term' => null, 'filter_reason' => null]], 'keywords' => []];
        });

        FinishQueriesJob::dispatch('clean');

        $this->assertSame(1, $calls, 'the query AI had seen is asked again once');
        $this->assertSame($this->zirkonyum->id, (int) $seen->fresh()->service_id);
        $this->assertSame(AiAssignments::HAIKU, app(PromptRegistry::class)->current(AiRouteKeys::QUERIES_TRIAGE)->model, 'triage on the free Claude credit');
        Queue::assertPushed(ClusterQueriesJob::class, fn (ClusterQueriesJob $job): bool => $job->serviceId === $this->zirkonyum->id);
        $this->assertSame('done', QueryFinish::state()['stage']);
        $check = QueryFinish::lastCheck();
        $this->assertSame(0, $check['unassigned_unchecked']);
        $this->assertSame(0, $check['stale_queues'], 'the clustering queue moved recently: not stale');
        $this->assertArrayHasKey('panorama.com.tr', $check['idea_pool']);
        $this->assertSame(0, QueryAutopilot::queue()->count());
        $this->assertTrue(QueryClusterQueue::running());

        Livewire::test(QueriesPage::class)->assertSeeHtml('data-finish-check')->assertSee('Son kontrol');
    }
}
