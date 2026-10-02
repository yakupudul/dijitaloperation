<?php

namespace Tests\Feature\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
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
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GoogleAdsEntitySnapshotRecollectionCommandTest extends TestCase
{
    use RefreshDatabase;

    private CoreAssetBinding $binding;

    private DigitalAsset $asset;

    private CoreExternalResource $resource;

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

        $this->resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => 'google',
            'resource_type' => 'google_ads',
            'external_id' => '1112223333',
            'display_name' => 'Example Ads',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $this->binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'capability' => 'google_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    #[Test]
    public function refuses_without_binding_id_even_when_one_ads_binding_exists(): void
    {
        $this->artisan('moxdop:google-ads:recollect-entity-snapshot')
            ->expectsOutputToContain('--binding-id is required')
            ->assertFailed();

        $this->assertSame(0, CollectionRun::query()->count());
    }

    #[Test]
    public function refuses_non_staging_without_allow_flag(): void
    {
        $this->app['env'] = 'local';

        $this->artisan('moxdop:google-ads:recollect-entity-snapshot', [
            '--binding-id' => (string) $this->binding->id,
        ])->expectsOutputToContain('APP_ENV=staging')
            ->assertFailed();

        $this->assertSame(0, CollectionRun::query()->count());
    }

    #[Test]
    public function refuses_production_even_with_allow_non_staging(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('moxdop:google-ads:recollect-entity-snapshot', [
            '--binding-id' => (string) $this->binding->id,
            '--allow-non-staging' => true,
        ])->expectsOutputToContain('production')
            ->assertFailed();

        $this->assertSame(0, CollectionRun::query()->count());
    }

    #[Test]
    public function refuses_non_google_ads_binding(): void
    {
        $this->binding->forceFill(['capability' => 'ga4'])->save();

        $this->artisan('moxdop:google-ads:recollect-entity-snapshot', [
            '--binding-id' => (string) $this->binding->id,
        ])->expectsOutputToContain('capability google_ads')
            ->assertFailed();
    }

    #[Test]
    public function dry_run_plans_entity_snapshot_without_starting(): void
    {
        $this->artisan('moxdop:google-ads:recollect-entity-snapshot', [
            '--binding-id' => (string) $this->binding->id,
            '--dry-run' => true,
            '--json' => true,
        ])->expectsOutputToContain(GoogleAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT)
            ->assertSuccessful();

        $this->assertSame(0, CollectionRun::query()->count());
    }

    #[Test]
    public function starts_manual_force_refresh_entity_snapshot_for_the_named_binding(): void
    {
        $this->artisan('moxdop:google-ads:recollect-entity-snapshot', [
            '--binding-id' => (string) $this->binding->id,
            '--json' => true,
        ])->doesntExpectOutputToContain('must-never-appear')
            ->doesntExpectOutputToContain('ads-access-token')
            ->doesntExpectOutputToContain('ads-refresh-token')
            ->assertSuccessful();

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
        ])->expectsOutputToContain('does not include this Google Ads binding')
            ->assertFailed();
    }

    #[Test]
    public function exact_resource_scope_excludes_historical_rows_from_another_resource_on_the_same_asset(): void
    {
        $otherResource = CoreExternalResource::factory()->create([
            'integration_id' => $this->resource->integration_id,
            'provider' => 'google',
            'resource_type' => 'google_ads',
            'external_id' => '9998887777',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $this->insertKeywordRow(
            externalResourceId: (int) $this->resource->id,
            adGroupId: '22',
            criterionId: '999',
            lastDatasetRunId: 50,
        );
        $this->insertKeywordRow(
            externalResourceId: (int) $this->resource->id,
            adGroupId: '23',
            criterionId: '999',
            lastDatasetRunId: 50,
        );
        $this->insertKeywordRow(
            externalResourceId: (int) $otherResource->id,
            customerId: '9998887777',
            adGroupId: '22',
            criterionId: '999',
            lastDatasetRunId: 7,
        );
        $this->insertKeywordRow(
            externalResourceId: (int) $otherResource->id,
            customerId: '9998887777',
            adGroupId: '88',
            criterionId: '111',
            lastDatasetRunId: 7,
        );

        $proof = app(GoogleAdsKeywordGrainProof::class)->prove($this->grainScope(), 50);

        $this->assertSame(2, $proof['row_count']);
        $this->assertSame(2, $proof['distinct_composite_count']);
        $this->assertSame(1, $proof['criterion_ids_in_multiple_ad_groups']);
        $this->assertSame(2, $proof['rows_last_written_by_dataset_run']);
        $this->assertSame(0, $proof['rows_not_touched_by_dataset_run']);
        $this->assertTrue($proof['grain_matches_current_schema']);
        $this->assertTrue($proof['current_run_grain_proven']);
        $this->assertSame((int) $this->resource->id, $proof['external_resource_id']);
        $encoded = json_encode($proof);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('1112223333', $encoded);
        $this->assertStringNotContainsString('9998887777', $encoded);
        $this->assertStringNotContainsString('"999"', $encoded);
    }

    #[Test]
    public function current_run_proof_fails_when_exact_resource_has_untouched_leftovers(): void
    {
        $this->insertKeywordRow(
            externalResourceId: (int) $this->resource->id,
            adGroupId: '22',
            criterionId: '999',
            lastDatasetRunId: 50,
        );
        $this->insertKeywordRow(
            externalResourceId: (int) $this->resource->id,
            adGroupId: '23',
            criterionId: '999',
            lastDatasetRunId: 50,
        );
        $this->insertKeywordRow(
            externalResourceId: (int) $this->resource->id,
            adGroupId: '24',
            criterionId: '888',
            lastDatasetRunId: 39,
        );

        $proof = app(GoogleAdsKeywordGrainProof::class)->prove($this->grainScope(), 50);

        $this->assertSame(3, $proof['row_count']);
        $this->assertSame(3, $proof['distinct_composite_count']);
        $this->assertTrue($proof['grain_matches_current_schema']);
        $this->assertSame(2, $proof['rows_last_written_by_dataset_run']);
        $this->assertSame(1, $proof['rows_not_touched_by_dataset_run']);
        $this->assertFalse($proof['current_run_grain_proven']);
        $this->assertContains(
            'in-scope leftovers were not touched by this dataset run',
            $proof['current_run_proof_reasons'],
        );
        $this->assertSame(3, DB::table('google_ads_keyword_snapshot')->count());
    }

    #[Test]
    public function report_run_fails_closed_when_dataset_completed_but_leftovers_remain(): void
    {
        $seeded = $this->seedCompletedEntitySnapshotRun();
        $this->insertKeywordRow(
            externalResourceId: (int) $this->resource->id,
            adGroupId: '22',
            criterionId: '999',
            lastDatasetRunId: $seeded['dataset']->id,
        );
        $this->insertKeywordRow(
            externalResourceId: (int) $this->resource->id,
            adGroupId: '23',
            criterionId: '999',
            lastDatasetRunId: 39,
        );

        $exit = Artisan::call('moxdop:google-ads:recollect-entity-snapshot', [
            '--binding-id' => (string) $this->binding->id,
            '--report-run-uuid' => $seeded['run']->uuid,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertIsArray($payload);
        $this->assertTrue($payload['acceptance']['dataset_completed']);
        $this->assertFalse($payload['acceptance']['current_run_grain_proven']);
        $this->assertFalse($payload['acceptance']['ok']);
        $this->assertSame(2, $payload['grain_after']['row_count']);
        $this->assertSame(1, $payload['grain_after']['rows_not_touched_by_dataset_run']);
        $this->assertSame(2, DB::table('google_ads_keyword_snapshot')->count());
    }

    #[Test]
    public function report_run_succeeds_only_when_exact_resource_current_run_proof_is_clean(): void
    {
        $otherResource = CoreExternalResource::factory()->create([
            'integration_id' => $this->resource->integration_id,
            'provider' => 'google',
            'resource_type' => 'google_ads',
            'external_id' => '5554443333',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        $seeded = $this->seedCompletedEntitySnapshotRun();
        $this->insertKeywordRow(
            externalResourceId: (int) $this->resource->id,
            adGroupId: '22',
            criterionId: '999',
            lastDatasetRunId: $seeded['dataset']->id,
        );
        $this->insertKeywordRow(
            externalResourceId: (int) $this->resource->id,
            adGroupId: '23',
            criterionId: '999',
            lastDatasetRunId: $seeded['dataset']->id,
        );
        $this->insertKeywordRow(
            externalResourceId: (int) $otherResource->id,
            customerId: '5554443333',
            adGroupId: '22',
            criterionId: '999',
            lastDatasetRunId: 7,
        );

        $exit = Artisan::call('moxdop:google-ads:recollect-entity-snapshot', [
            '--binding-id' => (string) $this->binding->id,
            '--report-run-uuid' => $seeded['run']->uuid,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertIsArray($payload);
        $this->assertSame(2, $payload['grain_after']['row_count']);
        $this->assertSame(2, $payload['grain_after']['distinct_composite_count']);
        $this->assertSame(1, $payload['grain_after']['criterion_ids_in_multiple_ad_groups']);
        $this->assertTrue($payload['grain_after']['current_run_grain_proven']);
        $this->assertTrue($payload['acceptance']['ok']);
        $this->assertSame((int) $this->resource->id, $payload['grain_after']['external_resource_id']);
        $this->assertStringNotContainsString('5554443333', Artisan::output());
        $this->assertSame(3, DB::table('google_ads_keyword_snapshot')->count());
    }

    #[Test]
    public function second_run_inventory_must_not_multiply_exact_resource_composite_rows(): void
    {
        $first = $this->seedCompletedEntitySnapshotRun();
        $this->insertKeywordRow(
            externalResourceId: (int) $this->resource->id,
            adGroupId: '22',
            criterionId: '999',
            lastDatasetRunId: $first['dataset']->id,
        );
        $this->insertKeywordRow(
            externalResourceId: (int) $this->resource->id,
            adGroupId: '23',
            criterionId: '999',
            lastDatasetRunId: $first['dataset']->id,
        );

        $firstProof = app(GoogleAdsKeywordGrainProof::class)->prove($this->grainScope(), $first['dataset']->id);
        $this->assertTrue($firstProof['current_run_grain_proven']);

        $second = $this->seedCompletedEntitySnapshotRun();
        DB::table('google_ads_keyword_snapshot')
            ->where('digital_asset_id', $this->asset->id)
            ->where('external_resource_id', $this->resource->id)
            ->update(['last_dataset_run_id' => $second['dataset']->id]);

        $secondProof = app(GoogleAdsKeywordGrainProof::class)->prove($this->grainScope(), $second['dataset']->id);

        $this->assertTrue($secondProof['current_run_grain_proven']);
        $this->assertSame($firstProof['row_count'], $secondProof['row_count']);
        $this->assertSame($firstProof['distinct_composite_count'], $secondProof['distinct_composite_count']);
        $this->assertSame(2, $secondProof['row_count']);
        $this->assertSame(0, $secondProof['rows_not_touched_by_dataset_run']);
    }

    /**
     * @return array{digital_asset_id: int, external_resource_id: int, ads_customer_id: string}
     */
    private function grainScope(): array
    {
        return [
            'digital_asset_id' => (int) $this->asset->id,
            'external_resource_id' => (int) $this->resource->id,
            'ads_customer_id' => (string) $this->resource->external_id,
        ];
    }

    /**
     * @return array{run: CollectionRun, dataset: CollectionDatasetRun}
     */
    private function seedCompletedEntitySnapshotRun(): array
    {
        $run = CollectionRun::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'brand_id' => $this->asset->brand_id,
            'customer_id' => $this->asset->brand?->customer_id,
            'trigger_type' => CollectionTriggerType::Manual,
            'status' => CollectionRunStatus::Completed,
            'request_context' => [
                'force_refresh' => true,
                'request_family_ids' => [GoogleAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT],
            ],
        ]);

        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id,
            'provider_or_source' => 'GOOGLE_ADS',
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'core_asset_binding_id' => $this->binding->id,
            'status' => CollectionRunStatus::Completed,
        ]);

        $dataset = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resourceRun->id,
            'provider_or_source' => 'GOOGLE_ADS',
            'dataset_contract_id' => 'google_ads_account_snapshot',
            'request_family_id' => GoogleAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT,
            'status' => CollectionRunStatus::Completed,
            'attempt_count' => 1,
            'rows_received' => 2,
            'rows_written' => 2,
        ]);

        return ['run' => $run, 'dataset' => $dataset];
    }

    private function insertKeywordRow(
        int $externalResourceId,
        string $adGroupId,
        string $criterionId,
        ?int $lastDatasetRunId,
        string $customerId = '1112223333',
    ): void {
        $now = now();
        DB::table('google_ads_keyword_snapshot')->insert([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $externalResourceId,
            'customer_id' => $customerId,
            'ad_group_id' => $adGroupId,
            'criterion_id' => $criterionId,
            'contract_version' => 1,
            'last_dataset_run_id' => $lastDatasetRunId,
            'first_collected_at' => $now,
            'last_collected_at' => $now,
            'record_fingerprint' => hash('sha256', $externalResourceId.'|'.$adGroupId.'|'.$criterionId),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
