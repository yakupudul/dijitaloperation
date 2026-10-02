<?php

namespace Tests\Feature;

use App\Enums\CustomerStatus;
use App\Enums\DigitalAssetStatus;
use App\Jobs\IntelligenceProjection\RebuildWebsiteProjectionJob;
use App\Models\Brand;
use App\Models\Cluster;
use App\Models\ClusterQuery;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Query;
use App\Models\ResourceAutomation;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Integrations\ResourceAutomationService;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Services\Verification\LiveVerifier;
use App\Support\Operator\CollectionErrorExplainer;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Operator decision (2026-11-25): an asset that is no longer used is marked "Kullanılmıyor" instead of being unbound.
 * Its accounts are collected once more, then automatic collection pauses until the asset is active again. A closed /
 * inaccessible account stops at once with its real reason instead of being retried as an "unexpected error".
 */
final class UnusedAssetCollectionTest extends TestCase
{
    use RefreshDatabase;

    private ResourceAutomationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ResourceAutomationService::class);
    }

    public function test_an_unused_assets_account_is_collected_once_then_parked_until_active_again(): void
    {
        [$asset, $automation] = $this->account(DigitalAssetStatus::Inactive, 'unsupported_test_type');

        $this->assertNull($this->service->portfolioGate($automation), 'the one-time collection after the mark is allowed');

        // The marker is taken before the collection starts, so a failing attempt still counts as the one-time pull.
        $automation->update(['collection_status' => 'planning', 'collection_enabled' => true]);
        try {
            $this->service->collect($automation->id);
            $this->fail('the unsupported test type throws once the collection starts');
        } catch (RuntimeException) {
        }
        $automation->refresh();
        $this->assertNotNull($automation->inactive_collection_at);
        $this->assertSame('asset_inactive', $this->service->portfolioGate($automation), 'no further automatic collection');

        $automation->update(['collection_status' => 'attention', 'collection_error' => 'asset_inactive', 'next_collection_at' => null]);
        $asset->update(['status' => DigitalAssetStatus::Active->value]);
        $automation->refresh();
        $this->assertNull($automation->inactive_collection_at, 'active again: the mark is cleared');
        $this->assertSame('waiting', $automation->collection_status);
        $this->assertTrue($automation->next_collection_at->lte(now()), 'and the account is due at once');
        $this->assertNull($this->service->portfolioGate($automation));
    }

    public function test_marking_an_asset_unused_keeps_its_binding_and_makes_a_stopped_account_due_once(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $operator = User::factory()->create(['is_active' => true]);
        $operator->assignRole(Roles::ADMIN);
        [$asset, $automation, $resource] = $this->account(DigitalAssetStatus::Active);
        $automation->update(['collection_status' => 'attention', 'collection_error' => 'account_unavailable', 'collection_failures' => 1, 'next_collection_at' => null]);

        $this->service->markAssetInactive($asset->id, $operator);

        $this->assertSame(DigitalAssetStatus::Inactive, $asset->fresh()->status);
        $this->assertTrue(CoreAssetBinding::query()->where('external_resource_id', $resource->id)->where('status', CoreAssetBinding::STATUS_ACTIVE)->exists(), 'the binding stays');
        $automation->refresh();
        $this->assertSame('waiting', $automation->collection_status);
        $this->assertNull($automation->collection_error);
        $this->assertTrue($automation->next_collection_at->lte(now()));
        $this->assertFalse($this->service->isOperationallyBound($resource->fresh()), 'an unused asset never pages the operator');
    }

    public function test_collect_now_takes_one_more_collection_of_a_parked_account(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $operator = User::factory()->create(['is_active' => true]);
        $operator->assignRole(Roles::ADMIN);
        [, $automation] = $this->account(DigitalAssetStatus::Inactive);
        $automation->update(['collection_status' => 'attention', 'collection_error' => 'asset_inactive', 'inactive_collection_at' => now()->subDay()]);

        $this->service->runNow($automation->id, $operator);

        $this->assertNull($this->service->portfolioGate($automation->fresh()));
    }

    public function test_a_closed_google_ads_account_stops_at_once_and_resumes_after_a_good_live_check(): void
    {
        $this->assertSame('account_unavailable', ResourceAutomationService::stopReasonFor(new RuntimeException(
            'Google Ads authorization failed: The caller does not have permission | authorizationError:CUSTOMER_NOT_ENABLED')));
        $this->assertSame('reconnect', ResourceAutomationService::stopReasonFor(new RuntimeException('Google Ads authentication failed.')));
        $this->assertNull(ResourceAutomationService::stopReasonFor(new RuntimeException('Something else broke.')));

        [, $automation, $resource] = $this->account(DigitalAssetStatus::Active);
        $this->service->fail($automation->id, 'account_unavailable');
        $automation->refresh();
        $this->assertSame(['attention', 'account_unavailable'], [$automation->collection_status, $automation->collection_error]);
        $this->assertNull($automation->next_collection_at, 'stopped at the first failure, no 30 min / 3 h retries');

        $this->travel(21)->hours();
        $this->service->retryStopped();
        $this->assertSame('account_unavailable', $automation->fresh()->collection_error, 'not retried daily like a transient failure');

        DB::table('live_checks')->insert(['check_key' => 'google:google_ads:external_resource:'.$resource->id, 'provider' => 'google', 'capability' => 'google_ads',
            'subject_type' => 'external_resource', 'subject_id' => $resource->id, 'label' => 'Google Ads · X', 'status' => LiveVerifier::OK, 'message' => 'Hesap okunabiliyor.', 'checked_at' => now()->addMinute()]);
        $this->travel(2)->minutes();
        $this->service->retryStopped();
        $this->assertSame('waiting', $automation->fresh()->collection_status, 'readable again: collection resumes');

        $explained = CollectionErrorExplainer::explain('account_unavailable', 'google');
        $this->assertSame('unused', $explained['kind']);
        $this->assertStringContainsString('Kullanılmıyor', $explained['fix']);
    }

    public function test_cluster_memberships_skip_queries_deleted_meanwhile(): void
    {
        $kept = Query::query()->create(['text' => 'diş implantı', 'text_hash' => hash('sha256', 'diş implantı')]);
        $deleted = Query::query()->create(['text' => 'implant fiyat', 'text_hash' => hash('sha256', 'implant fiyat')]);
        $sector = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş sağlığı', 'normalized_key' => 'dis sagligi']);
        $service = app(ServiceCatalogService::class)->resolveOrCreate('Diş İmplantı', 'dental', actor: User::factory()->create(['is_active' => true]))['service'];
        $cluster = Cluster::query()->create(['sector_id' => $sector->id, 'service_id' => $service->id, 'name' => 'İmplant']);
        $deletedId = (int) $deleted->id;
        $deleted->delete();

        $now = now();
        $inserted = ClusterQuery::insertExisting([
            ['cluster_id' => $cluster->id, 'query_id' => (int) $kept->id, 'is_suggested' => false, 'created_at' => $now, 'updated_at' => $now],
            ['cluster_id' => $cluster->id, 'query_id' => $deletedId, 'is_suggested' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $this->assertSame(1, $inserted);
        $this->assertSame([(int) $kept->id], ClusterQuery::query()->pluck('query_id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_wordpress_envelope_tolerates_a_bom_and_a_notice_before_the_json(): void
    {
        $json = '{"data":{"ok":true},"meta":{"nonce":"n"}}';

        $this->assertSame(['ok' => true], WordPressConnectorClient::decodeEnvelope("\xEF\xBB\xBF".$json)['data']);
        $this->assertSame(['ok' => true], WordPressConnectorClient::decodeEnvelope("Notice: Undefined index in plugin.php on line 3\n".$json)['data']);
        $this->assertNull(WordPressConnectorClient::decodeEnvelope('<html>maintenance</html>'));
    }

    public function test_the_website_projection_rebuild_runs_on_the_long_running_queue(): void
    {
        Queue::fake();
        config(['queue.heavy_queue' => 'heavy']);

        RebuildWebsiteProjectionJob::dispatch(1);

        Queue::assertPushedOn('heavy', RebuildWebsiteProjectionJob::class);
    }

    /** @return array{0: DigitalAsset, 1: ResourceAutomation, 2: CoreExternalResource} */
    private function account(DigitalAssetStatus $status, string $resourceType = 'ga4'): array
    {
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'status' => $status->value]);
        $resource = CoreExternalResource::factory()->create(['resource_type' => $resourceType]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => 'ga4']);
        $automation = ResourceAutomation::query()->create(['external_resource_id' => $resource->id]);

        return [$asset, $automation->fresh(), $resource];
    }
}
