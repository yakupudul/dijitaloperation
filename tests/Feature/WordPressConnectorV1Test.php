<?php

namespace Tests\Feature;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Events\Collection\CollectionRunCompleted;
use App\Livewire\Operator\Integrations\SiteConnectorShow;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\User;
use App\Services\Analysis\Adapters\WordPressCollectedFactsEvaluator;
use App\Services\Collection\Providers\Website\WebsiteRequestFamilyCatalog;
use App\Services\Collection\Providers\Website\WordPressConnectorDatasetExecutor;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Collection\Website\WebsiteCollectionOrchestrator;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use App\Services\Integrations\WordPress\WordPressEventReconciliation;
use App\Services\Website\Pages\PageStore;
use App\Support\Integrations\WordPress\WordPressConnectorCanonicalJson;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use MoxDop\Website\Discovery\PublicUrlSafety;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WordPressConnectorV1Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DigitalAsset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'cms' => 'wordpress',
            'domain' => 'example.com',
            'primary_url' => 'https://example.com/',
        ]);
    }

    #[Test]
    public function admin_can_issue_and_site_can_complete_one_time_pairing(): void
    {
        $issued = app(WordPressConnectorPairingService::class)->issue($this->asset, $this->admin);
        $connection = $issued['connection'];

        $this->assertStringStartsWith('MXD-'.$connection->id.'-', $issued['code']);
        $this->assertStringNotContainsString($issued['code'], json_encode($connection->config, JSON_THROW_ON_ERROR));
        $this->assertNull($connection->credential);

        $payload = $this->pairingPayload($issued['code']);
        $response = $this->postJson('/api/connectors/wordpress/pair', $payload);
        $response->assertCreated()->assertJsonPath('data.signature_algorithm', 'hmac-sha256');
        $secret = (string) $response->json('data.shared_secret');
        $this->assertGreaterThanOrEqual(40, strlen($secret));

        $connection->refresh()->load('credential');
        $this->assertTrue($connection->enabled);
        $this->assertSame(WordPressConnectorPairingService::PAIRED, $connection->config['pairing_state']);
        $this->assertSame($secret, $connection->credential->encrypted_payload['shared_secret']);
        $this->assertArrayNotHasKey('encrypted_payload', $connection->credential->toArray());

        $this->postJson('/api/connectors/wordpress/pair', $payload)->assertUnprocessable();
    }

    #[Test]
    public function pairing_rejects_domain_or_endpoint_substitution(): void
    {
        $issued = app(WordPressConnectorPairingService::class)->issue($this->asset, $this->admin);

        $wrongHost = $this->pairingPayload($issued['code']);
        $wrongHost['site_url'] = 'https://attacker.example/';
        $this->postJson('/api/connectors/wordpress/pair', $wrongHost)->assertUnprocessable();

        $wrongPath = $this->pairingPayload($issued['code']);
        $wrongPath['snapshot_url'] = 'https://example.com/wp-json/other/v1/snapshot';
        $this->postJson('/api/connectors/wordpress/pair', $wrongPath)->assertUnprocessable();
    }

    #[Test]
    public function team_member_cannot_issue_pairing_credentials(): void
    {
        $member = User::factory()->create();
        $member->assignRole(Roles::TEAM_MEMBER);

        $this->expectException(InvalidArgumentException::class);
        app(WordPressConnectorPairingService::class)->issue($this->asset, $member);
    }

    #[Test]
    public function issuing_a_rotation_does_not_interrupt_the_live_credential(): void
    {
        $pairing = app(WordPressConnectorPairingService::class);
        $first = $pairing->issue($this->asset, $this->admin);
        $pairing->complete($this->pairingPayload($first['code']));

        $second = $pairing->issue($this->asset, $this->admin);
        $connection = $second['connection']->fresh('credential');

        $this->assertTrue($connection->enabled);
        $this->assertNotNull($connection->credential);
        $this->assertSame(WordPressConnectorPairingService::PAIRED, $connection->config['pairing_state']);
        $this->assertTrue($connection->config['pairing_rotation_pending']);
    }

    #[Test]
    public function admin_can_revoke_a_pairing_and_member_cannot(): void
    {
        $pairing = app(WordPressConnectorPairingService::class);
        $issued = $pairing->issue($this->asset, $this->admin);
        $pairing->complete($this->pairingPayload($issued['code']));

        $member = User::factory()->create();
        $member->assignRole(Roles::TEAM_MEMBER);
        try {
            $pairing->revoke($this->asset, $member);
            $this->fail('A Team Member revoked a connector credential.');
        } catch (InvalidArgumentException) {
            $this->assertNotNull($issued['connection']->fresh('credential')->credential);
        }

        $pairing->revoke($this->asset, $this->admin);
        $connection = $issued['connection']->fresh('credential');
        $this->assertFalse($connection->enabled);
        $this->assertNull($connection->credential);
        $this->assertSame(WordPressConnectorPairingService::DISCONNECTED, $connection->config['pairing_state']);
    }

    #[Test]
    public function client_signs_request_and_rejects_unsigned_response(): void
    {
        $pairing = app(WordPressConnectorPairingService::class);
        $issued = $pairing->issue($this->asset, $this->admin);
        $credentials = $pairing->complete($this->pairingPayload($issued['code']));
        $connection = CoreConnection::query()->with('credential')->findOrFail($issued['connection']->id);
        $canonicalJson = new WordPressConnectorCanonicalJson;

        $signResponses = true;
        Http::fake(function (Request $request) use ($credentials, $canonicalJson, &$signResponses) {
            if (! $signResponses) {
                return Http::response(['data' => [], 'meta' => ['server_time' => now()->timestamp, 'request_nonce' => 'wrong', 'signature' => str_repeat('0', 64)]]);
            }
            $nonce = $request->header(WordPressConnectorClient::HEADER_NONCE)[0] ?? '';
            $data = ['schema_version' => 1, 'plugin_version' => '1.1.0', 'wordpress_version' => '6.8', 'read_only' => true, 'capabilities' => ['drafts', 'indexnow', 'Bad Value!']];
            $serverTime = now()->timestamp;
            $signature = hash_hmac('sha256', implode("\n", [
                (string) $serverTime,
                $nonce,
                hash('sha256', $canonicalJson->encode($data)),
            ]), $credentials['shared_secret']);

            return Http::response(['data' => $data, 'meta' => [
                'server_time' => $serverTime,
                'request_nonce' => $nonce,
                'signature' => $signature,
            ]]);
        });

        $client = new WordPressConnectorClient(
            $canonicalJson,
            new PublicUrlSafety(fn (string $host): array => ['93.184.216.34']),
        );
        $this->assertSame('6.8', $client->status($connection)['wordpress_version']);
        $this->assertDatabaseHas('website_connector_delivery', [
            'connection_id' => $connection->id, 'plugin_version' => '1.1.0',
            'last_received_at' => null, 'last_inventory_at' => null,
        ]);
        $this->assertSame('1.1.0', $connection->fresh()->config['plugin_version']);
        // The IndexNow standard reads the signed capability list; malformed entries are dropped.
        $this->assertSame(['drafts', 'indexnow'], $connection->fresh()->config['capabilities']);
        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader(WordPressConnectorClient::HEADER_SIGNATURE)
                && $request->hasHeader(WordPressConnectorClient::HEADER_CLIENT)
                && $request->url() === 'https://example.com/wp-json/moxdop/v1/status';
        });

        // Stubs accumulate, so the same fake switches to an unsigned response instead of registering a second one.
        $signResponses = false;
        $this->expectException(\RuntimeException::class);
        $client->status($connection->fresh('credential'));
    }

    #[Test]
    public function scheduler_bootstraps_inventory_without_any_site_delivery(): void
    {
        Queue::fake();
        $pairing = app(WordPressConnectorPairingService::class);
        $issued = $pairing->issue($this->asset, $this->admin);
        $pairing->complete($this->pairingPayload($issued['code']));
        DB::table('website_connector_delivery')->where('connection_id', $issued['connection']->id)->delete();

        app(WordPressEventReconciliation::class)->tick();

        $state = DB::table('website_connector_delivery')->where('connection_id', $issued['connection']->id)->first();
        $this->assertNotNull($state);
        $this->assertNull($state->last_received_at);
        $this->assertNotNull($state->collection_run_id);
        $run = CollectionRun::query()->findOrFail($state->collection_run_id);
        $this->assertSame('wordpress', data_get($run->request_context, 'context.collection_scope'));
        $this->assertSame([WebsiteRequestFamilyCatalog::FAMILY_WP_REST], $run->datasetRuns()->pluck('request_family_id')->unique()->values()->all());
        $this->assertSame(0, DB::table('website_connector_events')->count());
        // The automatic inventory really collects (not planned as not eligible) and the first one brings the page crawl after it.
        $this->assertSame(['queued'], $run->datasetRuns()->pluck('status')->map(fn ($status) => $status->value)->unique()->values()->all());
        $this->assertContains(WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL, data_get($run->request_context, 'context.chain_after_wordpress'));
        $this->assertFalse(data_get($run->request_context, 'context.refetch_unchanged'));
    }

    #[Test]
    public function recent_manual_inventory_is_reused_without_restarting_or_consuming_pending_content_events(): void
    {
        Queue::fake();
        $pairing = app(WordPressConnectorPairingService::class);
        $issued = $pairing->issue($this->asset, $this->admin);
        $pairing->complete($this->pairingPayload($issued['code']));
        $connectionId = $issued['connection']->id;
        $run = app(WebsiteCollectionOrchestrator::class)->start(
            asset: $this->asset,
            requestFamilyIds: [WebsiteRequestFamilyCatalog::FAMILY_WP_REST],
            context: ['collection_scope' => 'wordpress'],
        );
        $run->datasetRuns()->update(['status' => 'completed', 'finished_at' => now()]);
        $run->resourceRuns()->update(['status' => 'completed']);
        $run->update(['status' => CollectionRunStatus::Completed, 'started_at' => now(), 'finished_at' => now()]);
        $service = app(WordPressEventReconciliation::class);
        $service->tick();
        $state = DB::table('website_connector_delivery')->where('connection_id', $connectionId)->first();
        $this->assertNotNull($state->last_inventory_at);
        $this->assertNull($state->collection_run_id);
        $this->assertSame(1, CollectionRun::query()->where('digital_asset_id', $this->asset->id)->count());

        $eventId = DB::table('website_connector_events')->insertGetId([
            'connection_id' => $connectionId, 'digital_asset_id' => $this->asset->id,
            'event_id' => (string) Str::uuid(), 'type' => 'content.updated',
            'object_type' => 'post', 'object_id' => '42', 'origin' => 'wordpress',
            'payload' => '{}', 'occurred_at' => now(), 'received_at' => now(),
        ]);
        DB::table('website_connector_delivery')->where('connection_id', $connectionId)->update([
            'latest_event_id' => $eventId, 'plugin_version' => '1.1.0', 'next_reconcile_at' => now(),
        ]);
        $service->tick();
        $state = DB::table('website_connector_delivery')->where('connection_id', $connectionId)->first();
        $next = CollectionRun::query()->findOrFail($state->collection_run_id);
        $this->assertSame('changes', data_get($next->request_context, 'context.collection_scope'));
        $this->assertSame([42], data_get($next->request_context, 'context.wordpress_object_ids'));
        $this->assertSame(0, (int) $state->reconciled_event_id);
    }

    #[Test]
    public function small_changes_are_refreshed_within_a_minute_even_while_full_inventories_hold_their_slots(): void
    {
        Queue::fake();
        $pairing = app(WordPressConnectorPairingService::class);
        $issued = $pairing->issue($this->asset, $this->admin);
        $pairing->complete($this->pairingPayload($issued['code']));
        $connectionId = $issued['connection']->id;
        DB::table('website_connector_delivery')->where('connection_id', $connectionId)->update(['last_inventory_at' => now(), 'plugin_version' => '1.4.1']);

        // Two other sites are running full inventories: both full slots are taken.
        $other = DigitalAsset::factory()->create(['type' => 'website', 'domain' => 'other.example', 'primary_url' => 'https://other.example/']);
        $busyRun = app(WebsiteCollectionOrchestrator::class)->start(asset: $other, requestFamilyIds: [WebsiteRequestFamilyCatalog::FAMILY_WP_REST], context: ['collection_scope' => 'wordpress']);
        foreach ([1, 2] as $i) {
            $busy = CoreConnection::factory()->create(['digital_asset_id' => $other->id, 'type' => 'wordpress_connector', 'enabled' => true, 'config' => ['pairing_state' => 'paired']]);
            DB::table('website_connector_delivery')->insert(['connection_id' => $busy->id, 'collection_run_id' => $busyRun->id, 'collection_is_full' => true]);
        }

        $event = fn (): int => DB::table('website_connector_events')->insertGetId([
            'connection_id' => $connectionId, 'digital_asset_id' => $this->asset->id,
            'event_id' => (string) Str::uuid(), 'type' => 'content.updated', 'object_type' => 'page', 'object_id' => '42', 'origin' => 'wordpress_user',
            'payload' => json_encode(['url' => 'https://example.com/hizmet/']), 'occurred_at' => now(), 'received_at' => now(),
        ]);
        DB::table('website_connector_delivery')->where('connection_id', $connectionId)->update(['latest_event_id' => $event()]);
        app(WordPressEventReconciliation::class)->tick();

        $state = DB::table('website_connector_delivery')->where('connection_id', $connectionId)->first();
        $this->assertNotNull($state->collection_run_id, 'a changed-object refresh does not wait for a full slot');
        $run = CollectionRun::query()->findOrFail($state->collection_run_id);
        $this->assertSame('changes', data_get($run->request_context, 'context.collection_scope'));
        $this->assertSame(['https://example.com/hizmet/'], data_get($run->request_context, 'context.targeted_verification.urls'));

        // Finished: the next small batch is looked at a minute later (not ten).
        $run->update(['status' => CollectionRunStatus::Completed, 'finished_at' => now()]);
        app(WordPressEventReconciliation::class)->tick();
        $state = DB::table('website_connector_delivery')->where('connection_id', $connectionId)->first();
        $this->assertNull($state->collection_run_id);
        $this->assertLessThanOrEqual(now()->addMinute()->getTimestamp(), strtotime((string) $state->next_reconcile_at));
        $this->assertStringContainsString("everyMinute()->withoutOverlapping(10)->name('wordpress-event-reconciliation')", (string) preg_replace('/\s+/', '', (string) file_get_contents(base_path('routes/console.php'))));
    }

    #[Test]
    public function access_only_events_do_not_trigger_another_full_inventory(): void
    {
        Queue::fake();
        $pairing = app(WordPressConnectorPairingService::class);
        $issued = $pairing->issue($this->asset, $this->admin);
        $pairing->complete($this->pairingPayload($issued['code']));
        $connectionId = $issued['connection']->id;
        $eventId = DB::table('website_connector_events')->insertGetId([
            'connection_id' => $connectionId, 'digital_asset_id' => $this->asset->id,
            'event_id' => (string) Str::uuid(), 'type' => 'access.role_changed',
            'object_type' => 'user', 'object_id' => '42', 'origin' => 'wordpress',
            'payload' => '{}', 'occurred_at' => now(), 'received_at' => now(),
        ]);
        DB::table('website_connector_delivery')->where('connection_id', $connectionId)->update([
            'last_inventory_at' => now(), 'latest_event_id' => $eventId, 'next_reconcile_at' => now(),
        ]);
        app(WordPressEventReconciliation::class)->tick();
        $this->assertDatabaseHas('website_connector_delivery', [
            'connection_id' => $connectionId, 'collection_run_id' => null, 'reconciled_event_id' => $eventId,
        ]);
        $this->assertSame(0, CollectionRun::query()->where('digital_asset_id', $this->asset->id)->count());
    }

    #[Test]
    public function theme_change_marks_pages_changed_and_deleted_objects_leave_pages_without_a_full_inventory(): void
    {
        Queue::fake();
        $pairing = app(WordPressConnectorPairingService::class);
        $issued = $pairing->issue($this->asset, $this->admin);
        $pairing->complete($this->pairingPayload($issued['code']));
        $connectionId = $issued['connection']->id;
        foreach ([['a', 5], ['b', 6]] as [$slug, $postId]) {
            Page::query()->create(['website_asset_id' => $this->asset->id, 'url' => 'https://example.com/'.$slug.'/', 'url_hash' => PageStore::urlHash('https://example.com/'.$slug.'/'),
                'path' => '/'.$slug.'/', 'wp_post_id' => $postId, 'changed_at' => now()->subYear()]);
        }
        $event = fn (string $type, string $objectType, string $objectId): int => DB::table('website_connector_events')->insertGetId([
            'connection_id' => $connectionId, 'digital_asset_id' => $this->asset->id,
            'event_id' => (string) Str::uuid(), 'type' => $type, 'object_type' => $objectType, 'object_id' => $objectId,
            'origin' => 'wordpress_user', 'payload' => '{}', 'occurred_at' => now(), 'received_at' => now(),
        ]);
        $event('maintenance.theme_changed', 'theme', 'astra');
        $last = $event('content.deleted', 'page', '6');
        DB::table('website_connector_delivery')->where('connection_id', $connectionId)->update([
            'last_inventory_at' => now(), 'latest_event_id' => $last, 'next_reconcile_at' => now(), 'plugin_version' => '1.5.0',
        ]);

        app(WordPressEventReconciliation::class)->tick();

        $this->assertSame([5], Page::query()->pluck('wp_post_id')->all(), 'the deleted page left at once');
        $this->assertTrue(Page::query()->sole()->changed_at->isToday(), 'theme change → every page marked changed');
        $state = DB::table('website_connector_delivery')->where('connection_id', $connectionId)->first();
        $run = CollectionRun::query()->findOrFail($state->collection_run_id);
        $this->assertSame('changes', data_get($run->request_context, 'context.collection_scope'), 'no full inventory for a theme change');
        $this->assertSame([6], data_get($run->request_context, 'context.wordpress_object_ids'));
    }

    #[Test]
    public function connector_inventory_fills_pages_with_main_content_and_seo_fields(): void
    {
        Queue::fake();
        $pairing = app(WordPressConnectorPairingService::class);
        $issued = $pairing->issue($this->asset, $this->admin);
        $credentials = $pairing->complete($this->pairingPayload($issued['code']));
        $canonicalJson = new WordPressConnectorCanonicalJson;
        $this->app->instance(WordPressConnectorClient::class, new WordPressConnectorClient($canonicalJson, new PublicUrlSafety(fn (string $host): array => ['93.184.216.34'])));
        $records = [
            'content' => [
                ['object_type' => 'page', 'object_id' => '10', 'status' => 'publish', 'permalink' => 'https://example.com/implant/', 'title' => 'İmplant',
                    'modified_at' => '2026-09-01T10:00:00+00:00', 'language' => 'tr', 'content_rendered' => '<h2>Nedir?</h2><p>Kayıp diş yerine yapay kök.</p>'],
                ['object_type' => 'post', 'object_id' => '11', 'status' => 'draft', 'permalink' => 'https://example.com/?p=11', 'title' => 'Taslak', 'content_rendered' => '<p>x</p>'],
            ],
            'seo' => [
                ['object_type' => 'page', 'object_id' => '10', 'permalink' => 'https://example.com/implant/', 'seo_provider' => 'yoast',
                    'seo_title' => 'İmplant Tedavisi Ankara', 'meta_description' => 'Ankara implant.', 'canonical_url' => 'https://example.com/implant/', 'robots' => '', 'language' => 'tr'],
            ],
        ];
        Http::fake(function (Request $request) use ($credentials, $canonicalJson, $records) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $nonce = $request->header(WordPressConnectorClient::HEADER_NONCE)[0] ?? '';
            $data = ['schema_version' => 1, 'plugin_version' => '1.5.0', 'section' => $query['section'] ?? '', 'object_ids' => [],
                'records' => $records[$query['section'] ?? ''] ?? [], 'has_more' => false];
            $time = now()->timestamp;

            return Http::response(['data' => $data, 'meta' => ['server_time' => $time, 'request_nonce' => $nonce,
                'signature' => hash_hmac('sha256', implode("\n", [(string) $time, $nonce, hash('sha256', $canonicalJson->encode($data))]), $credentials['shared_secret'])]]);
        });

        $run = app(WebsiteCollectionOrchestrator::class)->start(asset: $this->asset, requestFamilyIds: [WebsiteRequestFamilyCatalog::FAMILY_WP_REST], context: ['collection_scope' => 'wordpress']);
        // SEO first (no page yet: nothing to update), then content (pages created with the stored SEO), then SEO again.
        foreach (['website_cms_seo_snapshot', 'website_cms_object_snapshot', 'website_cms_seo_snapshot'] as $datasetId) {
            $datasetRun = $run->datasetRuns()->where('dataset_contract_id', $datasetId)->firstOrFail();
            $checkpoint = [];
            for ($guard = 0; $guard < 10; $guard++) {
                $result = app(WordPressConnectorDatasetExecutor::class)->execute(new DatasetExecutionContext(
                    collectionRun: $run->fresh(), resourceRun: $datasetRun->resourceRun, datasetRun: $datasetRun->fresh(),
                    checkpoint: $checkpoint, registryDataset: [], registryRequestFamily: [], attemptNumber: 1,
                ));
                $checkpoint = $result->checkpoint ?? [];
                if ($result->outcome !== DatasetExecutionOutcome::Continue) {
                    break;
                }
            }
            $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome, $datasetId.': '.$result->errorMessage);
        }

        $page = Page::query()->where('website_asset_id', $this->asset->id)->sole();
        $this->assertSame([10, 'https://example.com/implant/', 'tr'], [$page->wp_post_id, $page->url, $page->language]);
        $this->assertSame('İmplant Tedavisi Ankara', $page->title);
        $this->assertSame('Ankara implant.', $page->meta_description);
        $this->assertSame('İmplant', $page->h1);
        $this->assertSame([['level' => 2, 'text' => 'Nedir?']], $page->headings);
        $this->assertStringContainsString('Kayıp diş yerine yapay kök.', $page->content_text);
        $this->assertNull($page->category);
    }

    #[Test]
    public function a_busy_site_is_asked_again_after_retry_after_with_small_content_pages_and_a_pause_between_pages(): void
    {
        Queue::fake();
        Storage::fake('raw_ingestion');
        config(['moxdop-wordpress.page_delay_seconds' => 2]);
        $pairing = app(WordPressConnectorPairingService::class);
        $issued = $pairing->issue($this->asset, $this->admin);
        $credentials = $pairing->complete($this->pairingPayload($issued['code']));
        $canonicalJson = new WordPressConnectorCanonicalJson;
        $this->app->instance(WordPressConnectorClient::class, new WordPressConnectorClient($canonicalJson, new PublicUrlSafety(fn (string $host): array => ['93.184.216.34'])));
        $answers = ['busy', 'busy', 'ok', 'ok'];
        Http::fake(function (Request $request) use ($credentials, $canonicalJson, &$answers) {
            if (array_shift($answers) === 'busy') {
                return Http::response(['code' => 'moxdop_busy'], 429, ['Retry-After' => '30']);
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $nonce = $request->header(WordPressConnectorClient::HEADER_NONCE)[0] ?? '';
            $data = ['schema_version' => 1, 'plugin_version' => '1.5.1', 'section' => $query['section'] ?? '', 'object_ids' => [],
                'records' => [], 'has_more' => ($query['section'] ?? '') === 'content'];
            $time = now()->timestamp;

            return Http::response(['data' => $data, 'meta' => ['server_time' => $time, 'request_nonce' => $nonce,
                'signature' => hash_hmac('sha256', implode("\n", [(string) $time, $nonce, hash('sha256', $canonicalJson->encode($data))]), $credentials['shared_secret'])]]);
        });

        $run = app(WebsiteCollectionOrchestrator::class)->start(asset: $this->asset, requestFamilyIds: [WebsiteRequestFamilyCatalog::FAMILY_WP_REST], context: ['collection_scope' => 'wordpress']);
        $datasetRun = $run->datasetRuns()->where('dataset_contract_id', 'website_cms_object_snapshot')->firstOrFail();
        $step = fn (array $checkpoint) => app(WordPressConnectorDatasetExecutor::class)->execute(new DatasetExecutionContext(
            collectionRun: $run->fresh(), resourceRun: $datasetRun->resourceRun, datasetRun: $datasetRun->fresh(),
            checkpoint: $checkpoint, registryDataset: [], registryRequestFamily: [], attemptNumber: 1,
        ));

        $first = $step([]);
        $this->assertSame(DatasetExecutionOutcome::Continue, $first->outcome, (string) $first->errorMessage);
        $this->assertSame(30, $first->backoffSeconds, 'Retry-After is honoured');
        $this->assertSame(1, $first->checkpoint['busy_count']);
        $this->assertSame(1, $first->checkpoint['page'] ?? 1, 'the same page is asked again');

        $second = $step($first->checkpoint);
        $this->assertSame(300, $second->backoffSeconds, 'a site that stays busy gets a longer break');

        $third = $step($second->checkpoint);
        $this->assertSame(DatasetExecutionOutcome::Continue, $third->outcome, (string) $third->errorMessage);
        $this->assertSame(2, $third->backoffSeconds, 'a short pause between two snapshot pages');
        $this->assertArrayNotHasKey('busy_count', $third->checkpoint);

        $snapshots = collect(Http::recorded())->map(fn (array $pair) => $pair[0])->filter(fn (Request $request) => str_contains($request->url(), '/snapshot'));
        $this->assertNotEmpty($snapshots);
        foreach ($snapshots as $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->assertSame('25', $query['per_page'], 'content pages are small');
        }
    }

    #[Test]
    public function initializing_existing_delivery_preserves_pause_and_event_cursor(): void
    {
        $pairing = app(WordPressConnectorPairingService::class);
        $issued = $pairing->issue($this->asset, $this->admin);
        $pairing->complete($this->pairingPayload($issued['code']));
        DB::table('website_connector_delivery')->where('connection_id', $issued['connection']->id)->update([
            'automation_enabled' => false, 'inventory_interval_days' => 3, 'reconciled_event_id' => 50,
        ]);
        $service = app(WordPressEventReconciliation::class);
        $service->initialize($issued['connection']->fresh('credential'));
        $service->tick();
        $this->assertDatabaseHas('website_connector_delivery', [
            'connection_id' => $issued['connection']->id, 'automation_enabled' => false,
            'inventory_interval_days' => 3, 'reconciled_event_id' => 50, 'collection_run_id' => null,
        ]);
    }

    #[Test]
    public function operator_page_exposes_real_package_and_pairing_flow(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(SiteConnectorShow::class, ['connector' => 'wordpress'])
            ->set('selectedAssetId', $this->asset->id)
            ->assertSee('moxdop-wordpress-connector-'.config('moxdop-wordpress.connector_version').'.zip')
            ->assertDontSee('DEMO CONNECTOR PACKAGE')
            ->call('issuePairingCode')
            ->assertSet('messageTone', 'success');
    }

    #[Test]
    public function paired_wordpress_collection_keeps_public_discovery_and_adds_connector_family(): void
    {
        Queue::fake();
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'type' => WordPressConnectorPairingService::CONNECTION_TYPE,
            'enabled' => true,
            'last_success_at' => now(),
            'config' => ['pairing_state' => WordPressConnectorPairingService::PAIRED],
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => ['client_id' => fake()->uuid(), 'shared_secret' => str_repeat('a', 43)],
        ]);

        $run = app(WebsiteCollectionOrchestrator::class)->start($this->asset, $this->admin);
        $families = $run->fresh('datasetRuns')->datasetRuns->pluck('request_family_id')->all();
        $wordpressDatasets = $run->datasetRuns
            ->where('request_family_id', WebsiteRequestFamilyCatalog::FAMILY_WP_REST)
            ->pluck('dataset_contract_id')
            ->sort()
            ->values()
            ->all();

        // WordPress first: the inventory runs alone, the page crawl waits for it.
        $this->assertSame([WebsiteRequestFamilyCatalog::FAMILY_WP_REST], array_values(array_unique($families)));
        $chained = data_get($run->request_context, 'context.chain_after_wordpress');
        $this->assertContains(WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL, $chained);
        $this->assertContains(WebsiteRequestFamilyCatalog::FAMILY_HTTP_HTML_DIAGNOSIS, $chained);
        $this->assertSame([
            'website_cms_extension_snapshot',
            'website_cms_object_snapshot',
            'website_cms_seo_snapshot',
            'website_cms_site_snapshot',
            'website_cms_taxonomy_snapshot',
        ], $wordpressDatasets);
        $this->assertTrue($run->datasetRuns->every(fn (CollectionDatasetRun $dataset): bool => $dataset->status === CollectionRunStatus::Queued));

        $run->update(['status' => CollectionRunStatus::Completed, 'finished_at' => now()]);
        CollectionRunCompleted::dispatch($run->fresh());

        $crawl = CollectionRun::query()->where('digital_asset_id', $this->asset->id)->whereKeyNot($run->id)->sole();
        $crawlFamilies = $crawl->datasetRuns()->pluck('request_family_id')->unique()->values()->all();
        $this->assertContains(WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL, $crawlFamilies);
        $this->assertContains(WebsiteRequestFamilyCatalog::FAMILY_HTTP_HTML_DIAGNOSIS, $crawlFamilies);
        $this->assertNotContains(WebsiteRequestFamilyCatalog::FAMILY_WP_REST, $crawlFamilies);
        $this->assertSame($run->id, data_get($crawl->request_context, 'context.chained_from_run_id'));

        // The completion event is idempotent: a second delivery does not start another crawl.
        CollectionRunCompleted::dispatch($run->fresh());
        $this->assertSame(2, CollectionRun::query()->where('digital_asset_id', $this->asset->id)->count());
    }

    #[Test]
    public function a_cancelled_wordpress_inventory_does_not_start_the_page_crawl(): void
    {
        Queue::fake();
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'type' => WordPressConnectorPairingService::CONNECTION_TYPE,
            'enabled' => true,
            'last_success_at' => now(),
            'config' => ['pairing_state' => WordPressConnectorPairingService::PAIRED],
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => ['client_id' => fake()->uuid(), 'shared_secret' => str_repeat('a', 43)],
        ]);
        $run = app(WebsiteCollectionOrchestrator::class)->start($this->asset, $this->admin);

        $run->update(['status' => CollectionRunStatus::Cancelled, 'finished_at' => now()]);
        CollectionRunCompleted::dispatch($run->fresh());

        $this->assertSame(1, CollectionRun::query()->where('digital_asset_id', $this->asset->id)->count());
    }

    #[Test]
    public function connector_and_public_snapshots_produce_deterministic_parity_matches(): void
    {
        $collection = CollectionRun::factory()->create([
            'brand_id' => $this->asset->brand_id,
            'digital_asset_id' => $this->asset->id,
        ]);
        $connectorResource = CollectionResourceRun::factory()->create([
            'collection_run_id' => $collection->id,
            'provider_or_source' => 'WORDPRESS_SITE_CONNECTOR',
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => null,
            'status' => CollectionRunStatus::Completed,
        ]);
        $connectorRun = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $collection->id,
            'collection_resource_run_id' => $connectorResource->id,
            'provider_or_source' => 'WORDPRESS_SITE_CONNECTOR',
            'dataset_contract_id' => 'website_cms_site_snapshot',
            'request_family_id' => WebsiteRequestFamilyCatalog::FAMILY_WP_REST,
            'status' => CollectionRunStatus::Completed,
        ]);
        $publicResource = CollectionResourceRun::factory()->create([
            'collection_run_id' => $collection->id,
            'provider_or_source' => 'WEBSITE_DIRECT',
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => null,
            'status' => CollectionRunStatus::Completed,
        ]);
        $publicRun = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $collection->id,
            'collection_resource_run_id' => $publicResource->id,
            'provider_or_source' => 'WEBSITE_DIRECT',
            'dataset_contract_id' => 'website_metadata_snapshot',
            'request_family_id' => WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL,
            'status' => CollectionRunStatus::Completed,
        ]);
        $observedAt = '2026-08-29 22:00:00';
        $provenance = [
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => null,
            'observed_at' => $observedAt,
            'contract_version' => 1,
            'last_collection_run_id' => $collection->id,
            'last_dataset_run_id' => $connectorRun->id,
            'first_collected_at' => $observedAt,
            'last_collected_at' => $observedAt,
            'source_timezone' => 'UTC',
            'record_fingerprint' => hash('sha256', 'connector-fixture'),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('website_cms_site_snapshot')->insert(array_merge($provenance, [
            'cms' => 'wordpress', 'site_key' => 'install-1', 'site_url' => 'https://example.com/',
            'home_url' => 'https://example.com/', 'wordpress_version' => '6.8', 'php_version' => '8.3',
            'locale' => 'en_US', 'timezone' => 'UTC', 'active_theme' => 'twentytwentyfive',
            'is_multisite' => false, 'rest_state' => 'reachable', 'cron_state' => 'enabled',
            'metadata' => json_encode(['core_update_available' => false]),
        ]));
        DB::table('website_cms_extension_snapshot')->insert(array_merge($provenance, [
            'cms' => 'wordpress', 'extension_type' => 'plugin', 'extension_id' => 'seo/seo.php',
            'name' => 'SEO Plugin', 'version' => '1.0', 'status' => 'active', 'update_available' => true,
            'available_version' => '1.1', 'auto_update' => false, 'record_fingerprint' => hash('sha256', 'extension-fixture'),
            'metadata' => json_encode(['update_checked_at' => '2026-08-29T21:55:00Z']),
        ]));
        DB::table('website_cms_seo_snapshot')->insert(array_merge($provenance, [
            'cms' => 'wordpress', 'object_type' => 'page', 'object_id' => '10',
            'permalink' => 'https://example.com/', 'seo_provider' => 'yoast', 'seo_title' => 'Configured title',
            'meta_description' => 'Configured description', 'canonical_url' => 'https://example.com/',
            'robots' => null, 'language' => 'en', 'record_fingerprint' => hash('sha256', 'seo-fixture'),
            'metadata' => json_encode([]),
        ]));
        DB::table('website_metadata_snapshot')->insert([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => null,
            'url' => 'https://example.com/',
            'observed_at' => $observedAt,
            'contract_version' => 1,
            'last_collection_run_id' => $collection->id,
            'last_dataset_run_id' => $publicRun->id,
            'first_collected_at' => $observedAt,
            'last_collected_at' => $observedAt,
            'source_timezone' => 'UTC',
            'record_fingerprint' => hash('sha256', 'public-fixture'),
            'metadata' => json_encode(['title_present' => false, 'meta_description_present' => false, 'canonical_hrefs' => []]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(WordPressCollectedFactsEvaluator::class)->evaluate($this->asset);
        $fingerprints = array_map(fn ($match): string => $match->fingerprint, $result['matches']);
        $this->assertTrue($result['evaluated']);
        $this->assertTrue(collect($fingerprints)->contains(fn (string $value): bool => str_starts_with($value, WordPressCollectedFactsEvaluator::RULE_PLUGIN_UPDATE)));
        $this->assertTrue(collect($fingerprints)->contains(fn (string $value): bool => str_starts_with($value, WordPressCollectedFactsEvaluator::RULE_SEO_DESCRIPTION_PARITY)));
        $this->assertTrue(collect($fingerprints)->contains(fn (string $value): bool => str_starts_with($value, WordPressCollectedFactsEvaluator::RULE_SEO_TITLE_PARITY)));
    }

    /** @return array<string, string> */
    private function pairingPayload(string $code): array
    {
        return [
            'pairing_code' => $code,
            'site_url' => 'https://example.com/',
            'home_url' => 'https://example.com/',
            'status_url' => 'https://example.com/wp-json/moxdop/v1/status',
            'snapshot_url' => 'https://example.com/wp-json/moxdop/v1/snapshot',
            'installation_id' => '550e8400-e29b-41d4-a716-446655440000',
            'plugin_version' => '1.0.0',
        ];
    }
}
