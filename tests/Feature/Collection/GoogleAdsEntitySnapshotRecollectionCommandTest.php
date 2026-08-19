<?php

namespace Tests\Feature\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\Google\GoogleAdsKeywordGrainProof;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsRequestFamilyCatalog;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GoogleAdsEntitySnapshotRecollectionCommandTest extends TestCase
{
    use RefreshDatabase;

    private CoreAssetBinding $binding;

    private DigitalAsset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        Queue::fake();

        config([
            'moxdop.google.client_id' => 'cid',
            'moxdop.google.client_secret' => 'csecret',
            'moxdop.google.developer_token' => 'must-never-appear',
            'moxdop-collection.queue_connection' => 'database',
            'moxdop-collection.require_queue_connection' => false,
        ]);

        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);

        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'google_ads',
            'status' => DigitalAssetStatus::Active,
        ]);

        $integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => [
                'granted_scopes' => [GoogleScopes::ADWORDS],
            ],
        ]);

        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => [
                'client_id' => 'cid',
                'client_secret' => 'csecret',
                'developer_token' => 'must-never-appear',
            ],
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => [
                'access_token' => 'ads-access-token',
                'refresh_token' => 'ads-refresh-token',
                'scope' => GoogleScopes::ADWORDS,
            ],
            'expires_at' => now()->addHour(),
        ]);

        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => 'google',
            'resource_type' => 'google_ads',
            'external_id' => '1112223333',
            'display_name' => 'Example Ads',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $this->binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $resource->id,
            'capability' => 'google_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    #[Test]
    public function refuses_without_binding_id_even_when_one_ads_binding_exists(): void
    {
        $this->artisan('moxdop:google-ads:recollect-entity-snapshot')
            ->assertFailed()
            ->expectsOutputToContain('--binding-id is required');

        $this->assertSame(0, CollectionRun::query()->count());
    }

    #[Test]
    public function refuses_non_staging_without_allow_flag(): void
    {
        $this->app['env'] = 'local';

        $this->artisan('moxdop:google-ads:recollect-entity-snapshot', [
            '--binding-id' => (string) $this->binding->id,
        ])->assertFailed()
            ->expectsOutputToContain('APP_ENV=staging');

        $this->assertSame(0, CollectionRun::query()->count());
    }

    #[Test]
    public function refuses_production_even_with_allow_non_staging(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('moxdop:google-ads:recollect-entity-snapshot', [
            '--binding-id' => (string) $this->binding->id,
            '--allow-non-staging' => true,
        ])->assertFailed()
            ->expectsOutputToContain('production');

        $this->assertSame(0, CollectionRun::query()->count());
    }

    #[Test]
    public function refuses_non_google_ads_binding(): void
    {
        $this->binding->forceFill(['capability' => 'ga4'])->save();

        $this->artisan('moxdop:google-ads:recollect-entity-snapshot', [
            '--binding-id' => (string) $this->binding->id,
        ])->assertFailed()
            ->expectsOutputToContain('capability google_ads');
    }

    #[Test]
    public function dry_run_plans_entity_snapshot_without_starting(): void
    {
        $this->artisan('moxdop:google-ads:recollect-entity-snapshot', [
            '--binding-id' => (string) $this->binding->id,
            '--dry-run' => true,
            '--json' => true,
        ])->assertSuccessful()
            ->expectsOutputToContain(GoogleAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT);

        $this->assertSame(0, CollectionRun::query()->count());
    }

    #[Test]
    public function starts_manual_force_refresh_entity_snapshot_for_the_named_binding(): void
    {
        $this->artisan('moxdop:google-ads:recollect-entity-snapshot', [
            '--binding-id' => (string) $this->binding->id,
            '--json' => true,
        ])->assertSuccessful()
            ->doesntExpectOutputToContain('must-never-appear')
            ->doesntExpectOutputToContain('ads-access-token')
            ->doesntExpectOutputToContain('ads-refresh-token');

        $run = CollectionRun::query()->first();
        $this->assertNotNull($run);
        $this->assertSame(CollectionTriggerType::Manual, $run->trigger_type);
        $this->assertTrue((bool) data_get($run->request_context, 'force_refresh'));
        $this->assertSame(
            [GoogleAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT],
            data_get($run->request_context, 'request_family_ids'),
        );
        $this->assertFalse((bool) data_get($run->request_context, 'context.allow_multi_asset_bindings'));
        $this->assertSame($this->asset->id, $run->digital_asset_id);
        $this->assertSame(1, $run->resourceRuns()->count());
        $this->assertSame($this->binding->id, $run->resourceRuns()->value('core_asset_binding_id'));
        $this->assertSame(
            GoogleAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT,
            $run->datasetRuns()->value('request_family_id'),
        );
        $this->assertSame(CollectionRunStatus::Queued, $run->datasetRuns()->first()?->status);
    }

    #[Test]
    public function report_run_uuid_rejects_a_run_that_does_not_include_the_binding(): void
    {
        $this->artisan('moxdop:google-ads:recollect-entity-snapshot', [
            '--binding-id' => (string) $this->binding->id,
        ])->assertSuccessful();

        $foreign = CollectionRun::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'trigger_type' => CollectionTriggerType::Manual,
            'status' => CollectionRunStatus::Completed,
        ]);

        $this->artisan('moxdop:google-ads:recollect-entity-snapshot', [
            '--binding-id' => (string) $this->binding->id,
            '--report-run-uuid' => $foreign->uuid,
        ])->assertFailed()
            ->expectsOutputToContain('does not include this Google Ads binding');
    }

    #[Test]
    public function grain_proof_hashes_criterion_ids_and_preserves_composite_counts(): void
    {
        $now = now();
        DB::table('google_ads_keyword_snapshot')->insert([
            [
                'digital_asset_id' => $this->asset->id,
                'customer_id' => '1112223333',
                'ad_group_id' => '22',
                'criterion_id' => '999',
                'contract_version' => 1,
                'first_collected_at' => $now,
                'last_collected_at' => $now,
                'record_fingerprint' => str_repeat('a', 64),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'digital_asset_id' => $this->asset->id,
                'customer_id' => '1112223333',
                'ad_group_id' => '23',
                'criterion_id' => '999',
                'contract_version' => 1,
                'first_collected_at' => $now,
                'last_collected_at' => $now,
                'record_fingerprint' => str_repeat('b', 64),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $proof = app(GoogleAdsKeywordGrainProof::class)->prove($this->asset->id);

        $this->assertTrue($proof['grain_matches_current_schema']);
        $this->assertSame(2, $proof['row_count']);
        $this->assertSame(2, $proof['distinct_composite_count']);
        $this->assertSame(2, $proof['non_null_ad_group_id_count']);
        $this->assertSame(0, $proof['rows_missing_ad_group_id']);
        $this->assertSame(1, $proof['criterion_ids_in_multiple_ad_groups']);
        $this->assertSame(
            GoogleAdsKeywordGrainProof::hashIdentifier('999'),
            $proof['repeated_criterion_samples'][0]['criterion_hash'],
        );
        $this->assertSame(2, $proof['repeated_criterion_samples'][0]['ad_group_count']);
        $encoded = json_encode($proof);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('1112223333', $encoded);
        $this->assertStringNotContainsString('"999"', $encoded);
    }
}
