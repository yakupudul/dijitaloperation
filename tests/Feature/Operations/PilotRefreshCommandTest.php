<?php

namespace Tests\Feature\Operations;

use App\Enums\CustomerStatus;
use App\Jobs\PilotRefreshStepJob;
use App\Jobs\Queries\AssignQuerySectorsJob;
use App\Jobs\Queries\ClassifyUnmatchedQueriesJob;
use App\Jobs\Queries\ClusterDueServicesJob;
use App\Jobs\Queries\FinishQueryPipelineJob;
use App\Jobs\Queries\IngestQuerySourcesJob;
use App\Jobs\Queries\MatchQueriesJob;
use App\Jobs\RunChannelAnalystJob;
use App\Models\AnalystRun;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\SeoPlan;
use App\Models\TopicMapBuild;
use App\Models\WebsiteUrlAudit;
use App\Services\Analyst\AnalystRegistry;
use App\Services\Operations\PilotRefresh;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** moxdop:pilot:refresh --brand=Panorama: the whole analysis chain of one brand, in dependency order, on the heavy queue. */
final class PilotRefreshCommandTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private DigitalAsset $site;

    protected function setUp(): void
    {
        parent::setUp();
        config(['moxdop-seo-tasks.llm.enabled' => false]);
        Http::preventStrayRequests();
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Panorama Ankara']);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'status' => 'active',
            'primary_url' => 'https://panorama.test/', 'domain' => 'panorama.test']);
        $google = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $gsc = CoreExternalResource::factory()->create(['integration_id' => $google->id, 'provider' => 'google', 'resource_type' => 'search_console',
            'external_id' => 'sc-domain:panorama.test', 'display_name' => 'panorama.test', 'metadata' => ['site_url' => 'sc-domain:panorama.test'], 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::query()->create(['digital_asset_id' => $this->site->id, 'external_resource_id' => $gsc->id, 'capability' => 'search_console', 'status' => 'active', 'configuration' => []]);
    }

    public function test_dry_run_lists_the_steps_in_order_and_queues_nothing(): void
    {
        Bus::fake();

        $this->artisan('moxdop:pilot:refresh', ['--brand' => 'panorama'])
            ->expectsOutputToContain('Marka #'.$this->brand->id.' Panorama Ankara')
            ->expectsOutputToContain('Kuru çalıştırma')
            ->assertExitCode(0);

        Bus::assertNothingDispatched();
        $this->assertSame(array_keys(PilotRefresh::STEPS), array_column(app(PilotRefresh::class)->plan($this->brand), 'step'));
    }

    public function test_run_queues_one_chain_in_dependency_order(): void
    {
        config(['queue.heavy_queue' => 'heavy']);
        Bus::fake();

        $this->artisan('moxdop:pilot:refresh', ['--brand' => 'Panorama', '--run' => true])->expectsOutputToContain('zincir')->assertExitCode(0);

        Bus::assertChained([
            IngestQuerySourcesJob::class, AssignQuerySectorsJob::class, MatchQueriesJob::class, ClassifyUnmatchedQueriesJob::class, FinishQueryPipelineJob::class,
            fn (PilotRefreshStepJob $job): bool => $job->step === 'mark:queries',
            ClusterDueServicesJob::class,
            fn (PilotRefreshStepJob $job): bool => $job->step === 'demand',
            fn (PilotRefreshStepJob $job): bool => $job->step === 'topic_map' && $job->siteId === $this->site->id,
            fn (PilotRefreshStepJob $job): bool => $job->step === 'seo_plan',
            fn (PilotRefreshStepJob $job): bool => $job->step === 'url_verdicts',
            fn (PilotRefreshStepJob $job): bool => $job->step === 'analysts',
            fn (PilotRefreshStepJob $job): bool => $job->step === 'finish',
        ]);
        $this->artisan('moxdop:pilot:refresh', ['--brand' => 'Panorama', '--run' => true])->expectsOutputToContain('zaten sürüyor')->assertExitCode(1);
    }

    public function test_the_chain_runs_end_to_end_even_without_any_search_console_rows(): void
    {
        Bus::fake([RunChannelAnalystJob::class]);

        $this->artisan('moxdop:pilot:refresh', ['--brand' => (string) $this->brand->id, '--run' => true])->assertExitCode(0);

        $state = PilotRefresh::state($this->brand->id);
        $this->assertSame('completed', $state['status'], json_encode($state, JSON_UNESCAPED_UNICODE));
        $this->assertStringStartsWith('0 yeni sorgu', $state['steps']['queries']['note']);
        $this->assertSame('done', $state['steps']['demand']['status']);
        $this->assertSame(1, TopicMapBuild::query()->where('digital_asset_id', $this->site->id)->count());
        $this->assertSame(SeoPlan::STATUS_COMPLETED, SeoPlan::query()->where('digital_asset_id', $this->site->id)->latest('id')->value('status'));
        $this->assertSame('completed', WebsiteUrlAudit::query()->where('digital_asset_id', $this->site->id)->value('status'));
        $this->assertSame(count(app(AnalystRegistry::class)->liveChannels()), AnalystRun::query()->where('brand_id', $this->brand->id)->count());
        $this->assertTrue(Cache::lock(PilotRefresh::lockKey($this->brand->id), 5)->get(), 'the pilot lock is released at the end');

        $this->artisan('moxdop:pilot:refresh', ['--brand' => 'Panorama', '--status' => true])->expectsOutputToContain('completed')->assertExitCode(0);
    }
}
