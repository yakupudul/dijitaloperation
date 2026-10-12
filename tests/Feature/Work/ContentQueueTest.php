<?php

namespace Tests\Feature\Work;

use App\Ai\Agents\Site\WriteArticleAgent;
use App\Jobs\Site\RunSiteOperationJob;
use App\Models\AiTask;
use App\Models\Suggestion;
use App\Services\Site\SiteOperations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Site\SiteTestCase;

/**
 * yakup, 2026-10-12 ("İçerik fikri, üretimi gönderimi vs. hiçbir zaman kuyruğa girmesin"): content work has its own
 * queue, so long site jobs never hold it, and work that waited elsewhere is started again there.
 */
final class ContentQueueTest extends SiteTestCase
{
    private const array ARTICLE = [
        'title' => 'İmplant sonrası ilk hafta', 'slug' => 'implant-sonrasi-ilk-hafta', 'meta_title' => 'İmplant sonrası ilk hafta',
        'meta_description' => 'İlk hafta nelere dikkat edilir?', 'excerpt' => 'İlk hafta rehberi.', 'html' => '<h2>İlk 24 saat</h2><p>Soğuk uygulama yapılır.</p>',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableAi();
        config(['queue.content_queue' => 'content', 'queue.heavy_queue' => 'heavy']);
    }

    public function test_content_operations_run_on_their_own_queue(): void
    {
        foreach ([SiteOperations::WRITE_ARTICLE, SiteOperations::WEEKLY_CONTENT, SiteOperations::DISCOVERY] as $operation) {
            $this->assertSame('content', (new RunSiteOperationJob($this->site->id, $operation, []))->queue, $operation);
        }
        $this->assertSame('heavy', (new RunSiteOperationJob($this->site->id, SiteOperations::CLUSTER_PAGES, []))->queue);
    }

    public function test_rescue_starts_waiting_articles_again_on_the_content_queue(): void
    {
        $idea = $this->idea();
        $waiting = AiTask::query()->create(['operation' => 'site.write_article', 'resume_key' => hash('sha256', 'run'), 'sequence' => 1, 'input_hash' => 'h',
            'status' => AiTask::PENDING, 'instructions' => 'x', 'input' => 'DATA_JSON {}', 'output_schema' => [], 'resume' => serialize(new RunSiteOperationJob($this->site->id, SiteOperations::WRITE_ARTICLE, ['suggestion_id' => $idea->id]))]);
        Queue::fake();

        $this->artisan('moxdop:content:rescue')->assertSuccessful();

        $this->assertSame(AiTask::CONSUMED, $waiting->fresh()->status);
        Queue::assertPushedOn('content', RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->operation === SiteOperations::WRITE_ARTICLE
            && $job->params === ['suggestion_id' => $idea->id]);
    }

    public function test_the_old_copy_left_on_the_heavy_queue_does_nothing_once_moved(): void
    {
        $idea = $this->idea();
        WriteArticleAgent::fake([self::ARTICLE]);
        $old = (new RunSiteOperationJob($this->site->id, SiteOperations::WRITE_ARTICLE, ['suggestion_id' => $idea->id]))->onQueue('heavy');
        Cache::put(RunSiteOperationJob::movedKey($old->uniqueId()), true, now()->addDay());

        $old->handle(app(SiteOperations::class));
        WriteArticleAgent::assertNeverPrompted();

        (new RunSiteOperationJob($this->site->id, SiteOperations::WRITE_ARTICLE, ['suggestion_id' => $idea->id]))->handle(app(SiteOperations::class));
        $this->assertSame('İmplant sonrası ilk hafta', $idea->fresh()->action['article']['title']);
    }

    private function idea(): Suggestion
    {
        $this->page('/implant/', 'Ankara İmplant Tedavisi', ['category' => 'hizmet']);

        return Suggestion::query()->create([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => hash('sha256', 'rescue'),
            'material_hash' => hash('sha256', 'x'), 'title' => 'İmplant sonrası ilk hafta', 'reason' => 'Kümenin uygun sayfası yok.', 'priority' => 2,
            'action_type' => 'content', 'status' => Suggestion::APPROVED, 'evidence' => [],
            'action' => ['site_id' => $this->site->id, 'kind' => 'new', 'page_type' => 'blog', 'outline' => ['İlk 24 saat'], 'questions' => [],
                'target_url' => 'https://panorama.com.tr/blog/implant-sonrasi-ilk-hafta/'],
        ]);
    }
}
