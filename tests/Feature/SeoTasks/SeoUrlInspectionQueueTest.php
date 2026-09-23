<?php

namespace Tests\Feature\SeoTasks;

use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Services\SeoTasks\SeoUrlInspectionQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Faz 2: URL inspection is pointed at the pages that matter, once per site per week, within quota.
 */
final class SeoUrlInspectionQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_targets_are_queued_once_per_week_within_quota_and_only_for_bound_sites(): void
    {
        Queue::fake();
        config(['moxdop-seo-tasks.indexing.inspection_max_targets' => 3]);
        $site = DigitalAsset::factory()->create(['type' => 'website', 'domain' => 'example.com', 'primary_url' => 'https://example.com/', 'module_id' => 'website']);
        $queue = app(SeoUrlInspectionQueue::class);

        $this->assertSame('not_bound', $queue->queue($site, ['https://example.com/'])['status']);
        $this->assertSame('nothing_to_inspect', $queue->queue($site, [])['status']);

        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $resource = CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => 'search_console', 'external_id' => 'sc-domain:example.com', 'metadata' => ['site_url' => 'sc-domain:example.com']]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $site->id, 'external_resource_id' => $resource->id, 'capability' => 'search_console']);

        $targets = ['https://example.com/implant/', 'https://example.com/', 'https://example.com/a/', 'https://example.com/b/', 'not-a-url'];
        $first = $queue->queue($site, $targets);
        $this->assertSame('queued', $first['status'], $first['message'] ?? '');
        $this->assertSame(3, $first['targets']);
        $run = CollectionRun::query()->findOrFail($first['run_id']);
        $this->assertSame(['https://example.com/implant/', 'https://example.com/', 'https://example.com/a/'], data_get($run->request_context, 'context.url_inspection_targets'));

        $this->assertSame($first['run_id'], $queue->queue($site, $targets)['run_id'], 'one run per site per week');
    }
}
