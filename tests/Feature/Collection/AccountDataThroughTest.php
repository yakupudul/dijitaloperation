<?php

namespace Tests\Feature\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ResourceAutomation;
use App\Models\Run;
use App\Services\Collection\GoogleAds\GoogleAdsCentralCollectionService;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsCentralRequestFamilyCatalog;
use App\Services\Integrations\Google\GoogleBusinessProfileBoundCollector;
use App\Services\Integrations\ResourceAutomationService;
use App\Services\Operator\BrandWorkspaceReadService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The account's "veri sonu" (resource_automations.data_through, read by System health, diagnostics, get-brand and the
 * brand page): written for Business Profile locations from their stored performance days, and never pulled back by a
 * Google Ads repair of an old history period.
 */
final class AccountDataThroughTest extends TestCase
{
    use RefreshDatabase;

    private const string NOW = '2026-10-06 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::NOW, 'UTC'));
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_completed_repair_of_an_old_period_keeps_the_newer_data_through(): void
    {
        $resource = CoreExternalResource::factory()->create(['resource_type' => 'google_ads']);
        $automation = $this->automation($resource, '2026-10-04');
        [$run] = $this->adsRun($resource, CollectionRunStatus::Completed, ['history_01' => ['2025-06-01', '2025-10-31', CollectionRunStatus::Completed]]);

        $this->reconcile($automation, $run);

        $automation->refresh();
        $this->assertSame('2026-10-04', substr((string) $automation->data_through, 0, 10));
        $this->assertSame('current', $automation->collection_status);
    }

    public function test_repair_of_a_partial_initial_import_counts_the_periods_the_initial_import_completed(): void
    {
        $resource = CoreExternalResource::factory()->create(['resource_type' => 'google_ads']);
        $automation = $this->automation($resource, null);
        [, $initial] = $this->adsRun($resource, CollectionRunStatus::Partial, [
            'history_01' => ['2025-06-01', '2025-10-31', CollectionRunStatus::Failed],
            'history_02' => ['2026-08-01', '2026-10-04', CollectionRunStatus::Completed],
        ], ['collection_intent' => 'google_ads_central_initial', 'history_policy_version' => GoogleAdsCentralCollectionService::HISTORY_POLICY_VERSION]);

        // The planner repairs only the failed period and points the repair run at the run it continues.
        $plan = (new ReflectionMethod(GoogleAdsCentralCollectionService::class, 'smartPlan'))->invoke(app(GoogleAdsCentralCollectionService::class), $resource->fresh());
        $this->assertSame('google_ads_central_repair', $plan['intent']);
        $this->assertSame((int) $initial->id, $plan['resumed_from_resource_run_id']);
        $this->assertSame([['start' => '2025-06-01', 'end' => '2025-10-31']], array_column($plan['families'], 'date_range'));

        [$repair] = $this->adsRun($resource, CollectionRunStatus::Completed, ['history_01' => ['2025-06-01', '2025-10-31', CollectionRunStatus::Completed]],
            ['collection_intent' => 'google_ads_central_repair', 'resumed_from_resource_run_id' => (int) $initial->id]);
        $this->reconcile($automation, $repair);

        $this->assertSame('2026-10-04', substr((string) $automation->fresh()->data_through, 0, 10));
    }

    public function test_a_newer_collection_still_moves_data_through_forward(): void
    {
        $resource = CoreExternalResource::factory()->create(['resource_type' => 'google_ads']);
        $automation = $this->automation($resource, '2026-09-28');
        [$run] = $this->adsRun($resource, CollectionRunStatus::Completed, ['recent' => ['2026-10-03', '2026-10-05', CollectionRunStatus::Completed]]);

        $this->reconcile($automation, $run);

        $this->assertSame('2026-10-05', substr((string) $automation->fresh()->data_through, 0, 10));
    }

    public function test_business_profile_collection_writes_data_through_and_the_brand_checklist_sees_its_data(): void
    {
        [$automation, $brand] = $this->businessProfile();
        $this->fakeBusinessProfileCollector('completed', '2026-10-03');

        app(ResourceAutomationService::class)->collect($automation->id);

        $automation->refresh();
        $this->assertSame('current', $automation->collection_status);
        $this->assertSame('2026-10-03', substr((string) $automation->data_through, 0, 10));
        $workspace = app(BrandWorkspaceReadService::class);
        $item = collect($workspace->checklist($brand, $workspace->assets($brand), [])['items'])->firstWhere('key', 'google_business_profile');
        $this->assertTrue($item['done']);
        $this->assertStringNotContainsString('veri henüz gelmedi', $item['detail']);
    }

    public function test_a_failed_business_profile_collection_leaves_data_through_alone(): void
    {
        [$automation] = $this->businessProfile('2026-09-30');
        $this->fakeBusinessProfileCollector('failed', '2026-10-03');

        app(ResourceAutomationService::class)->collect($automation->id);

        $automation->refresh();
        $this->assertSame('attention', $automation->collection_status);
        $this->assertSame('2026-09-30', substr((string) $automation->data_through, 0, 10));
    }

    // ---------------------------------------------------------------- helpers

    private function automation(CoreExternalResource $resource, ?string $dataThrough): ResourceAutomation
    {
        return ResourceAutomation::query()->create([
            'external_resource_id' => $resource->id, 'collection_enabled' => true, 'collection_status' => 'collecting',
            'last_collection_success_at' => now()->subDays(2), 'data_through' => $dataThrough,
        ]);
    }

    /**
     * A provider-resource-first Google Ads run with account-daily datasets: variant => [start, end, status].
     *
     * @param  array<string, array{0: string, 1: string, 2: CollectionRunStatus}>  $datasets
     * @param  array<string, mixed>  $metadata
     * @return array{0: CollectionRun, 1: CollectionResourceRun}
     */
    private function adsRun(CoreExternalResource $resource, CollectionRunStatus $status, array $datasets, array $metadata = []): array
    {
        $run = CollectionRun::factory()->create(['status' => $status]);
        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id, 'provider_or_source' => 'GOOGLE_ADS', 'external_resource_id' => $resource->id,
            'digital_asset_id' => null, 'core_asset_binding_id' => null, 'status' => $status,
            'metadata' => $metadata + ['collection_scope' => 'provider_resource_first'],
        ]);
        foreach ($datasets as $variant => [$start, $end, $datasetStatus]) {
            CollectionDatasetRun::factory()->create([
                'collection_run_id' => $run->id, 'collection_resource_run_id' => $resourceRun->id, 'provider_or_source' => 'GOOGLE_ADS',
                'dataset_contract_id' => 'google_ads_account_daily', 'request_family_id' => GoogleAdsCentralRequestFamilyCatalog::ACCOUNT_DAILY,
                'execution_variant' => $variant, 'status' => $datasetStatus,
                'metadata' => ['collection_scope' => 'provider_resource_first', 'date_range' => ['start' => $start, 'end' => $end]],
            ]);
        }

        return [$run, $resourceRun];
    }

    private function reconcile(ResourceAutomation $automation, CollectionRun $run): void
    {
        $automation->update(['collection_run_id' => $run->id, 'collection_status' => 'collecting']);
        (new ReflectionMethod(ResourceAutomationService::class, 'reconcile'))->invoke(app(ResourceAutomationService::class), $automation->fresh());
    }

    /** @return array{0: ResourceAutomation, 1: Brand} */
    private function businessProfile(?string $dataThrough = null): array
    {
        config(['moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret']);
        $resource = CoreExternalResource::factory()->create(['resource_type' => 'google_business_profile', 'external_id' => 'locations/1', 'display_name' => 'Klinik Konum']);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $resource->integration_id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret']]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $resource->integration_id, 'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => 'https://www.googleapis.com/auth/business.manage'], 'expires_at' => now()->addHour()]);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_business_profile', 'status' => DigitalAssetStatus::Active]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id,
            'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $automation = ResourceAutomation::query()->create(['external_resource_id' => $resource->id, 'collection_enabled' => true,
            'collection_status' => 'planning', 'data_through' => $dataThrough]);

        return [$automation, $brand];
    }

    /** Replaces the Business Profile collector: one step that stores a performance day and finishes the run. */
    private function fakeBusinessProfileCollector(string $status, string $reportingDate): void
    {
        app()->instance(GoogleBusinessProfileBoundCollector::class, new class($status, $reportingDate)
        {
            public function __construct(private string $status, private string $reportingDate) {}

            public function collectResourceStep(CoreExternalResource $resource, Run $run): Run
            {
                DB::table('gbp_performance_daily')->insert([
                    'digital_asset_id' => null, 'external_resource_id' => $resource->id, 'run_id' => $run->id,
                    'location_name' => (string) $resource->external_id, 'reporting_date' => $this->reportingDate,
                    'metric' => 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH', 'value' => 12, 'collected_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $available = $this->status === 'completed' ? 'available' : 'unavailable';
                $run->update(['status' => $this->status, 'metadata' => array_merge($run->metadata ?? [], ['datasets' => [
                    'gbp_location' => ['status' => $available], 'gbp_performance_daily' => ['status' => $available],
                ]])]);

                return $run->fresh();
            }
        });
    }
}
