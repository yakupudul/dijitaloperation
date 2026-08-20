<?php

namespace Tests\Feature\Collection;

use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\CollectionPlanner;
use App\Services\Collection\DataContractRegistryLoader;
use App\Services\Collection\DatasetExecutorResolver;
use App\Services\Collection\Providers\MetaAds\MetaAdsDatasetExecutor;
use App\Services\Collection\Providers\MetaAds\MetaAdsRequestFamilyCatalog;
use App\Services\Collection\Support\StartCollectionRequest;
use App\Services\DataPool\DataPoolStorageRegistry;
use App\Services\DataPool\Freshness\DataFreshnessPolicyLoader;
use App\Support\Integrations\Meta\MetaConnectorRegistry;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetaAdsContractRuntimeClosureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Executor match() arms in MetaAdsDatasetExecutor::execute — do not expand deferred families.
     *
     * @var list<string>
     */
    private const SUPPORTED_KINDS = [
        'ad_account_meta',
        'entity_snapshot',
        'insights_sync',
        'insights_daily',
        'typed_actions',
        'insights_breakdown',
    ];

    #[Test]
    public function every_collection_ready_meta_family_is_wired_to_executor_storage_and_freshness(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $registry = app(DataContractRegistryLoader::class);
        $registry->load();
        $storage = app(DataPoolStorageRegistry::class);
        $freshness = app(DataFreshnessPolicyLoader::class);
        $executor = app(MetaAdsDatasetExecutor::class);
        $resolver = app(DatasetExecutorResolver::class);

        $readyFamilies = collect($registry->requestFamilies())
            ->filter(static fn (array $family): bool => ($family['provider_or_source'] ?? '') === 'META_ADS'
                && ! in_array((string) ($family['status'] ?? ''), ['DEFERRED', 'UNSUPPORTED', 'UNAVAILABLE', 'DEMO_ONLY'], true))
            ->values();

        $readyIds = $readyFamilies->pluck('id')->all();
        $this->assertEqualsCanonicalizing(MetaAdsRequestFamilyCatalog::supportedFamilies(), array_values($readyIds));
        $this->assertNotContains('RF_META_ASYNC_INSIGHTS', $readyIds);

        $deferred = collect($registry->requestFamilies())
            ->first(static fn (array $family): bool => ($family['id'] ?? '') === 'RF_META_ASYNC_INSIGHTS');
        $this->assertNotNull($deferred);
        $this->assertSame('DEFERRED', $deferred['status'] ?? null);
        $this->assertNotContains('RF_META_ASYNC_INSIGHTS', MetaAdsRequestFamilyCatalog::supportedFamilies());

        $executorSource = file_get_contents(app_path('Services/Collection/Providers/MetaAds/MetaAdsDatasetExecutor.php'));
        $this->assertIsString($executorSource);

        foreach ($readyFamilies as $family) {
            $familyId = (string) $family['id'];
            $this->assertContains($familyId, $executor->supportedRequestFamilies(), $familyId.' must be registered on MetaAdsDatasetExecutor');

            $resolved = $resolver->resolve(CollectionDatasetRun::factory()->make([
                'request_family_id' => $familyId,
                'provider_or_source' => 'META_ADS',
            ]));
            $this->assertInstanceOf(MetaAdsDatasetExecutor::class, $resolved);

            $definition = MetaAdsRequestFamilyCatalog::definition($familyId);
            $this->assertContains($definition['kind'], self::SUPPORTED_KINDS, $familyId.' kind must have an executor arm');
            $this->assertStringContainsString("'".$definition['kind']."' =>", $executorSource);
            $this->assertNotSame('', $definition['kind']);
            $this->assertNotEmpty($definition['dataset_ids']);

            foreach ($definition['dataset_ids'] as $datasetId) {
                $dataset = $registry->dataset($datasetId);
                $this->assertNotNull($dataset, $datasetId.' must exist in the data contract registry');
                $this->assertSame('META_ADS', $dataset['provider_or_source'] ?? null);
                $this->assertSame('COLLECTION_READY', $dataset['status'] ?? null);
                $this->assertTrue($storage->hasPhysicalTable($datasetId), $datasetId.' must map to PHYSICAL_TABLE');

                $physical = $storage->physicalDataset($datasetId);
                $this->assertSame($datasetId, $physical['table']);
                $disposition = $storage->disposition($datasetId);
                $this->assertNotNull($disposition, $datasetId.' must have a storage disposition');
                $this->assertSame('PHYSICAL_TABLE', $disposition['disposition'] ?? null);
                $this->assertNotEmpty($physical['natural_key']);
                $this->assertContains('digital_asset_id', $physical['natural_key']);
                $this->assertContains('account_id', $physical['natural_key']);

                $policy = $freshness->policy($datasetId);
                $this->assertNotNull($policy, $datasetId.' must have a freshness/backfill policy');
                $this->assertSame('META_ADS', $policy['provider_or_source'] ?? null);
                $this->assertNotEmpty($policy['collection_mode'] ?? null);
            }
        }
    }

    #[Test]
    public function planner_queues_ready_families_and_defers_async_insights_without_noop_kinds(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
            'status' => DigitalAssetStatus::Active,
        ]);
        $integration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => [
                'auth_method' => 'oauth',
                'auth_status' => 'connected',
                'connection_status' => 'connected',
                'credential_status' => 'valid',
                'granted_permissions' => ['ads_read', 'business_management'],
            ],
        ]);
        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => [
                'access_token' => 'EAAG-synthetic-meta-token-never-real',
                'granted_permissions' => ['ads_read', 'business_management'],
            ],
        ]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => 'meta',
            'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_11110001',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['timezone_name' => 'Europe/Berlin'],
        ]);
        $binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => MetaConnectorRegistry::META_ADS,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        $plan = app(CollectionPlanner::class)->plan(new StartCollectionRequest(
            digitalAsset: $asset,
            bindingIds: [$binding->id],
            dateRange: ['start' => '2026-08-01', 'end' => '2026-08-02'],
        ));

        $queued = collect($plan['datasets'])
            ->where('provider_or_source', 'META_ADS')
            ->pluck('request_family_id')
            ->unique()
            ->values()
            ->all();
        foreach (MetaAdsRequestFamilyCatalog::supportedFamilies() as $familyId) {
            $this->assertContains($familyId, $queued, $familyId.' must be planned, not a silent no-op');
            $row = collect($plan['datasets'])->firstWhere('request_family_id', $familyId);
            $this->assertContains(
                $row['dataset_contract_id'],
                MetaAdsRequestFamilyCatalog::definition($familyId)['dataset_ids'],
            );
            $this->assertNotSame($familyId, $row['dataset_contract_id'], $familyId.' must map to a physical dataset, not the family id');
            if (MetaAdsRequestFamilyCatalog::definition($familyId)['requires_date_range']) {
                $this->assertNotEmpty($row['date_range']['start'] ?? null, $familyId.' must receive a historical/backfill date range');
                $this->assertNotEmpty($row['date_range']['end'] ?? null);
            }
        }

        $deferred = collect($plan['dispositions'])->firstWhere('request_family_id', 'RF_META_ASYNC_INSIGHTS');
        $this->assertNotNull($deferred);
        $this->assertNotContains('RF_META_ASYNC_INSIGHTS', $queued);
    }
}
