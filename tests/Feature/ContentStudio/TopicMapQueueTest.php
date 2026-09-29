<?php

namespace Tests\Feature\ContentStudio;

use App\Enums\CustomerStatus;
use App\Jobs\BuildTopicMapJob;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\IntelligenceProjection\WebsiteIntelligenceProjectionRun;
use App\Models\TopicMapBuild;
use App\Services\ContentStudio\TopicMapBuilder;
use App\Services\IntelligenceProjection\Website\WebsiteProjectionAdapterSupport;
use App\Services\IntelligenceProjection\Website\WebsiteProjectionRebuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Production: "Topic map: queued, never built" and "Last projection: running" — jobs that waited behind hour-long
 * jobs on the default queue or whose worker died left their rows open forever.
 */
final class TopicMapQueueTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $site;

    protected function setUp(): void
    {
        parent::setUp();
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'status' => 'active',
            'primary_url' => 'https://panorama.test/', 'domain' => 'panorama.test']);
    }

    public function test_topic_map_build_runs_on_the_heavy_queue_and_a_lost_build_is_closed(): void
    {
        config(['queue.heavy_queue' => 'heavy']);
        Queue::fake();
        $lost = TopicMapBuild::query()->create(['brand_id' => $this->site->brand_id, 'digital_asset_id' => $this->site->id, 'status' => 'queued', 'trigger' => 'manual']);
        $lost->forceFill(['created_at' => now()->subHours(3)])->save();

        $build = app(TopicMapBuilder::class)->queue($this->site);

        $this->assertNotSame($lost->id, $build->id);
        $this->assertSame('failed', $lost->fresh()->status, 'a build queued 3 hours ago was lost; it no longer shows as queued');
        Queue::assertPushedOn('heavy', BuildTopicMapJob::class, fn (BuildTopicMapJob $job): bool => $job->buildId === $build->id);
        $this->assertSame($build->id, app(TopicMapBuilder::class)->queue($this->site)->id, 'one pending build per website');
    }

    public function test_a_failed_or_timed_out_job_closes_its_build_and_a_second_build_does_not_wait(): void
    {
        $build = TopicMapBuild::query()->create(['brand_id' => $this->site->brand_id, 'digital_asset_id' => $this->site->id, 'status' => 'running', 'trigger' => 'manual']);
        (new BuildTopicMapJob($build->id))->failed(new RuntimeException('Job has timed out.'));
        $this->assertSame(['failed', 'Job has timed out.'], [$build->fresh()->status, $build->fresh()->error]);

        $second = TopicMapBuild::query()->create(['brand_id' => $this->site->brand_id, 'digital_asset_id' => $this->site->id, 'status' => 'queued', 'trigger' => 'manual']);
        $lock = Cache::lock('topic-map-build:'.$this->site->id, 60);
        $lock->get();
        app(TopicMapBuilder::class)->run($second->id);
        $this->assertSame('failed', $second->fresh()->status, 'another build of the same site is running: this one ends at once');
        $lock->release();
    }

    public function test_projection_rebuild_closes_a_run_left_running_by_a_dead_worker(): void
    {
        $stale = WebsiteIntelligenceProjectionRun::query()->create([
            'uuid' => (string) Str::uuid(), 'website_asset_id' => $this->site->id, 'trigger' => 'collection_completed', 'status' => WebsiteIntelligenceProjectionRun::STATUS_RUNNING,
            'schema_version' => 1, 'intelligence_registry_version' => 1, 'period_start' => now()->subDays(90), 'period_end' => now()->subDay(), 'started_at' => now()->subHours(5),
        ]);

        $run = app(WebsiteProjectionRebuilder::class)->rebuild($this->site, 'test');

        $this->assertNotNull($run);
        $this->assertSame([WebsiteIntelligenceProjectionRun::STATUS_FAILED, 'ABANDONED'], [$stale->fresh()->status, $stale->fresh()->error_code]);
        $this->assertNotSame(WebsiteIntelligenceProjectionRun::STATUS_RUNNING, $run->status);

        // Snapshot histories are streamed (cursor / generator), newest first; the first row per key wins.
        $rows = (function (): \Generator {
            yield (object) ['url' => 'https://panorama.test/a', 'v' => 2];
            yield (object) ['url' => 'https://panorama.test/a', 'v' => 1];
        })();
        $latest = app(WebsiteProjectionAdapterSupport::class)->latestBy($rows, fn (object $r): string => $r->url);
        $this->assertSame(2, $latest['https://panorama.test/a']->v);
    }
}
