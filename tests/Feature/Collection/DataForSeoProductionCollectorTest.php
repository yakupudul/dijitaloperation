<?php

namespace Tests\Feature\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\CollectionPlanner;
use App\Services\Collection\Providers\DataForSeo\DataForSeoRequestFamilyCatalog;
use App\Services\Collection\Support\StartCollectionRequest;
use App\Services\Integrations\DataForSeo\DataForSeoProviderCredentialService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DataForSeoProductionCollectorTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private DigitalAsset $asset;

    private CoreIntegration $integration;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);

        Storage::fake('raw_ingestion');
        config([
            'moxdop.dataforseo.login' => null,
            'moxdop.dataforseo.password' => null,
            'moxdop.dataforseo.base_url' => 'https://api.dataforseo.com',
            'moxdop.seo_intelligence.ranked_keywords.ttl_days' => 5,
            'moxdop.seo_intelligence.ranked_keywords.limit' => 100,
            'cache.default' => 'array',
            'moxdop-collection.queue_connection' => 'database',
            'moxdop-collection.require_queue_connection' => false,
            'moxdop-data-pool.raw_disk' => 'raw_ingestion',
            'filesystems.disks.raw_ingestion' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/raw_ingestion'),
            ],
        ]);
        Cache::flush();

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);

        $customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'website',
            'module_id' => 'website',
            'status' => DigitalAssetStatus::Active,
            'domain' => 'https://www.moximu.com/',
            'primary_url' => 'https://www.moximu.com/',
            'seo_market_location_code' => 2792,
            'seo_market_location_name' => 'Turkey',
            'seo_market_language_code' => 'tr',
            'seo_market_language_name' => 'Turkish',
        ]);

        $this->integration = CoreIntegration::factory()->dataforseo()->create();
        app(DataForSeoProviderCredentialService::class)->save($this->integration, [
            'login' => 'agency@example.com',
            'password' => 'dfs-secret-password',
        ], $this->admin);
    }

    #[Test]
    public function incremental_trigger_marks_dataforseo_not_eligible(): void
    {
        $plan = app(CollectionPlanner::class)->plan(new StartCollectionRequest(
            digitalAsset: $this->asset,
            triggerType: CollectionTriggerType::Incremental,
            providerSources: ['DATAFORSEO'],
            requestFamilyIds: [DataForSeoRequestFamilyCatalog::FAMILY_FREE_USER],
        ));
        $this->assertSame(CollectionRunStatus::NotEligible->value, $plan['datasets'][0]['planned_status']);
    }
}
