<?php

namespace Tests\Feature\Website;

use App\Enums\Collection\CollectionRunStatus;
use App\Livewire\Operator\Integrations\WebsiteIntegrationIndex;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreConnection;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\Providers\Website\WebsiteCrawlPoliteness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WebsiteResetCollectionCommandTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('raw_ingestion');
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id, 'type' => 'website', 'domain' => 'klinik.example', 'primary_url' => 'https://klinik.example/',
        ]);
    }

    #[Test]
    public function it_cancels_active_website_runs_and_leaves_other_providers_running(): void
    {
        $website = $this->collectionRun('WEBSITE_DIRECT', 'queued');
        $between = $this->collectionRun('WEBSITE_DIRECT', 'running');
        $other = $this->collectionRun('GA4', 'queued');
        $connection = CoreConnection::factory()->create(['digital_asset_id' => $this->asset->id, 'type' => 'wordpress_connector']);
        DB::table('website_connector_delivery')->insert(['connection_id' => $connection->id, 'collection_run_id' => $between->id, 'next_reconcile_at' => now()->addHour()]);

        $this->artisan('moxdop:website:reset-collection')->assertSuccessful();
        $this->assertSame('running', $between->fresh()->status->value, 'without --force nothing changes');

        $this->artisan('moxdop:website:reset-collection', ['--force' => true])->assertSuccessful();

        $this->assertSame(CollectionRunStatus::Cancelled, $website->fresh()->status);
        $this->assertSame(CollectionRunStatus::Cancelled, $between->fresh()->status);
        $this->assertSame(CollectionRunStatus::Cancelled, $between->datasetRuns()->sole()->status);
        $this->assertFalse($other->fresh()->status->isTerminal());
        $delivery = DB::table('website_connector_delivery')->where('connection_id', $connection->id)->first();
        $this->assertNull($delivery->collection_run_id);
        $this->assertNull($delivery->next_reconcile_at);
    }

    #[Test]
    public function a_step_that_is_executing_is_left_to_stop_at_its_safe_boundary(): void
    {
        $run = $this->collectionRun('WEBSITE_DIRECT', 'running', locked: true);

        $this->artisan('moxdop:website:reset-collection', ['--force' => true])->assertSuccessful();

        $this->assertSame(CollectionRunStatus::CancellationRequested, $run->fresh()->status);
        $this->assertSame(CollectionRunStatus::Running, $run->datasetRuns()->sole()->status);
    }

    #[Test]
    public function the_stop_button_cancels_only_this_sites_collection_so_it_can_be_restarted(): void
    {
        $run = $this->collectionRun('WEBSITE_DIRECT', 'running');
        $otherSite = DigitalAsset::factory()->create(['brand_id' => $this->asset->brand_id, 'type' => 'website', 'domain' => 'baska.example']);
        $otherRun = CollectionRun::factory()->create(['digital_asset_id' => $otherSite->id, 'brand_id' => $otherSite->brand_id, 'status' => CollectionRunStatus::Queued]);
        CollectionDatasetRun::factory()->create(['collection_run_id' => $otherRun->id, 'provider_or_source' => 'WEBSITE_DIRECT', 'status' => CollectionRunStatus::Queued]);
        $politeness = app(WebsiteCrawlPoliteness::class);
        $politeness->backOff('klinik.example', 'timeout', 6, 'cURL error 28: Operation timed out');

        Livewire::actingAs(User::factory()->create())->test(WebsiteIntegrationIndex::class, ['assetId' => $this->asset->id])
            ->assertSeeHtml('data-collection-stop')
            ->call('stopCollection', $this->asset->id)
            ->assertSet('messageTone', 'success')
            ->assertDontSeeHtml('data-collection-stop');

        $this->assertSame(CollectionRunStatus::Cancelled, $run->fresh()->status);
        $this->assertSame(CollectionRunStatus::Cancelled, $run->datasetRuns()->sole()->status);
        $this->assertSame(CollectionRunStatus::Queued, $otherRun->fresh()->status, 'other sites keep collecting');
        $this->assertSame('normal', $politeness->view('klinik.example')['mode'], 'a restart starts without the old wait');
    }

    #[Test]
    public function the_crawl_pace_line_names_the_real_fetch_error(): void
    {
        $politeness = app(WebsiteCrawlPoliteness::class);
        $this->assertSame('cURL error 28: Operation timed out after 20001 ms', $politeness->distressDetail([
            'https://klinik.example/' => ['status_code' => 0, 'error' => 'timeout_or_connection: cURL error 28: Operation timed out after 20001 ms'],
        ]));
        $this->assertSame('HTTP 503', $politeness->distressDetail(['https://klinik.example/' => ['status_code' => 503, 'error' => null]]));

        $politeness->backOff('klinik.example', 'timeout', 6, 'cURL error 28: Operation timed out after 20001 ms');
        $this->assertSame('cURL error 28: Operation timed out after 20001 ms', $politeness->view('klinik.example')['detail']);
    }

    #[Test]
    public function it_keeps_only_the_latest_row_per_page_and_deletes_link_edges(): void
    {
        foreach (['2026-08-01 00:00:00', '2026-08-02 00:00:00', '2026-08-03 00:00:00'] as $observedAt) {
            $this->snapshot('website_http_snapshot', 'https://klinik.example/', $observedAt);
            $this->snapshot('website_metadata_snapshot', 'https://klinik.example/', $observedAt);
            $this->snapshot('website_crawl_issue_snapshot', 'https://klinik.example/', $observedAt, ['issue_code' => 'MISSING_H1', 'severity' => 'warning', 'message' => 'H1 yok']);
            $this->edge($observedAt);
        }
        $this->snapshot('website_crawl_issue_snapshot', 'https://klinik.example/', '2026-08-01 00:00:00', ['issue_code' => 'TITLE_LONG', 'severity' => 'info', 'message' => 'Uzun başlık']);
        $this->snapshot('website_http_snapshot', 'https://klinik.example/iletisim', '2026-08-01 00:00:00');
        $otherSite = DigitalAsset::factory()->create(['brand_id' => $this->asset->brand_id, 'type' => 'website', 'domain' => 'baska.example']);
        $this->snapshot('website_http_snapshot', 'https://klinik.example/', '2026-08-01 00:00:00', [], $otherSite->id);

        $oldRaw = $this->rawObject('old');
        $sharedRaw = $this->rawObject('shared');
        $this->snapshot('website_html_snapshot', 'https://klinik.example/', '2026-08-01 00:00:00', $this->html($oldRaw));
        $this->snapshot('website_html_snapshot', 'https://klinik.example/', '2026-08-02 00:00:00', $this->html($sharedRaw));
        $this->snapshot('website_html_snapshot', 'https://klinik.example/', '2026-08-03 00:00:00', $this->html($sharedRaw));

        $this->artisan('moxdop:website:reset-collection', ['--force' => true])
            ->expectsOutputToContain('website_http_snapshot')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('website_link_edge')->count());
        $latestHome = DB::table('website_http_snapshot')->where('digital_asset_id', $this->asset->id)->where('url', 'https://klinik.example/')->pluck('observed_at')->all();
        $this->assertCount(1, $latestHome);
        $this->assertStringStartsWith('2026-08-03', (string) $latestHome[0]);
        $this->assertSame(2, DB::table('website_http_snapshot')->where('digital_asset_id', $this->asset->id)->count(), 'each page keeps one row');
        $this->assertSame(1, DB::table('website_http_snapshot')->where('digital_asset_id', $otherSite->id)->count(), 'other sites keep their latest row');
        $this->assertSame(1, DB::table('website_metadata_snapshot')->count());
        $this->assertEqualsCanonicalizing(['MISSING_H1', 'TITLE_LONG'], DB::table('website_crawl_issue_snapshot')->pluck('issue_code')->all());
        $this->assertSame(1, DB::table('website_html_snapshot')->count());
        $this->assertSame((string) $sharedRaw, (string) DB::table('website_html_snapshot')->value('raw_ingestion_object_id'));
        $this->assertFalse(DB::table('raw_ingestion_objects')->where('id', $oldRaw)->exists(), 'the HTML copy only a deleted row used is removed');
        $this->assertTrue(DB::table('raw_ingestion_objects')->where('id', $sharedRaw)->exists());
        Storage::disk('raw_ingestion')->assertMissing('website/old.html.gz');
        Storage::disk('raw_ingestion')->assertExists('website/shared.html.gz');
    }

    private function collectionRun(string $provider, string $status, bool $locked = false): CollectionRun
    {
        $run = CollectionRun::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'brand_id' => $this->asset->brand_id,
            'status' => $status === 'queued' ? CollectionRunStatus::Queued : CollectionRunStatus::Running,
            'request_context' => ['provider_sources' => [$provider]],
        ]);
        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id,
            'provider_or_source' => $provider,
            'digital_asset_id' => $this->asset->id,
            'status' => $status === 'queued' ? CollectionRunStatus::Queued : CollectionRunStatus::Running,
        ]);
        CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resourceRun->id,
            'provider_or_source' => $provider,
            'status' => $status === 'queued' ? CollectionRunStatus::Queued : CollectionRunStatus::Running,
            'dispatch_lock_token' => $locked ? (string) Str::uuid() : null,
            'dispatch_locked_at' => $locked ? now() : null,
        ]);

        return $run;
    }

    /** @param array<string, mixed> $extra */
    private function snapshot(string $table, string $url, string $observedAt, array $extra = [], ?int $assetId = null): void
    {
        DB::table($table)->insert(array_merge([
            'digital_asset_id' => $assetId ?? $this->asset->id, 'url' => $url, 'observed_at' => $observedAt,
            'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', $table.$url.$observedAt.json_encode($extra)), 'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function edge(string $observedAt): void
    {
        DB::table('website_link_edge')->insert([
            'digital_asset_id' => $this->asset->id, 'edge_key' => hash('sha256', 'edge'), 'source_url' => 'https://klinik.example/',
            'target_url' => 'https://klinik.example/iletisim', 'normalized_target_url' => 'https://klinik.example/iletisim',
            'is_internal' => true, 'nofollow' => false, 'observed_at' => $observedAt, 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', $observedAt),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function rawObject(string $name): int
    {
        Storage::disk('raw_ingestion')->put('website/'.$name.'.html.gz', 'html');

        return (int) DB::table('raw_ingestion_objects')->insertGetId([
            'uuid' => (string) Str::uuid(), 'dataset_id' => 'website_html_snapshot', 'batch_key' => $name,
            'provider_or_source' => 'WEBSITE_DIRECT', 'storage_disk' => 'raw_ingestion', 'object_key' => 'website/'.$name.'.html.gz',
            'byte_size' => 4, 'sha256' => hash('sha256', $name), 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function html(int $rawId): array
    {
        return ['html_hash' => hash('sha256', (string) $rawId), 'change_state' => 'changed', 'html_bytes' => 4, 'raw_ingestion_object_id' => $rawId];
    }
}
