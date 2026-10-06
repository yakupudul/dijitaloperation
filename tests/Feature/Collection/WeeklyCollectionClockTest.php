<?php

namespace Tests\Feature\Collection;

use App\Enums\Collection\ActivityTier;
use App\Enums\Collection\CollectionRunStatus;
use App\Enums\CustomerStatus;
use App\Enums\DataPool\MaterializationStatus;
use App\Enums\DigitalAssetStatus;
use App\Jobs\Async\ResourceCollectionJob;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DigitalAsset;
use App\Models\ResourceActivity;
use App\Models\ResourceAutomation;
use App\Models\User;
use App\Services\Collection\Activity\ActivityTierService;
use App\Services\Collection\GoogleAds\GoogleAdsCentralCollectionService;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsCentralRequestFamilyCatalog;
use App\Services\Integrations\ResourceAutomationService;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\Support\InsertsFacts;
use Tests\TestCase;

/**
 * The weekly clock of idle / dormant accounts starts when their light / check pass succeeded, not when it was planned:
 * a failed pass is retried on the normal backoff, "Şimdi güncelle" collects at once, a failed weekly pass is replanned
 * from the stored coverage through yesterday, and a dormant check leaves no hole.
 */
final class WeeklyCollectionClockTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    private const string NOW = '2026-09-27 10:00:00';

    private CoreIntegration $google;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at(self::NOW);
        Queue::fake();
        config(['moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret']);
        $this->google = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => ['granted_scopes' => [GoogleScopes::ADWORDS]],
        ]);
        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $this->google->id,
            'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret'],
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->google->id,
            'encrypted_payload' => ['access_token' => 'ads-access-token', 'refresh_token' => 'ads-refresh-token', 'scope' => GoogleScopes::ADWORDS],
            'expires_at' => now()->addYear(),
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_failed_weekly_pass_is_admitted_again_on_the_normal_backoff(): void
    {
        $resource = $this->idleAccount();
        $plan = $this->plan($resource);
        $this->assertSame(['google_ads_central_update', 'light'], [$plan['intent'], $plan['activity']['mode']]);
        $this->assertNull($this->lastLightCheck($resource), 'planning does not start the weekly clock');

        $automation = $this->reconcileRun($resource, CollectionRunStatus::Failed, $plan['families'][0]['date_range']);
        $this->assertSame('waiting', $automation->collection_status);
        $this->assertSame('2026-09-27 10:30:00', $automation->next_collection_at->format('Y-m-d H:i:s'), 'fail(): retried in 30 minutes');

        $this->at('2026-09-27 10:31:00');
        $this->admit();

        Queue::assertPushed(ResourceCollectionJob::class, 1);
        $this->assertSame('planning', $automation->fresh()->collection_status);
    }

    public function test_a_successful_weekly_pass_starts_the_weekly_clock(): void
    {
        $resource = $this->idleAccount();
        $automation = $this->reconcileRun($resource, CollectionRunStatus::Completed, ['start' => '2026-09-21', 'end' => '2026-09-26']);

        $this->assertSame(self::NOW, $this->lastLightCheck($resource));
        $this->assertSame('current', $automation->collection_status);
        $this->assertSame('2026-10-04 10:00:00', $automation->next_collection_at->format('Y-m-d H:i:s'));

        // Brought forward on the next day (e.g. by a retry): admission waits for the weekly pass.
        $this->at('2026-09-28 10:00:00');
        $automation->update(['next_collection_at' => now()->subMinute()]);
        $this->admit();

        Queue::assertNothingPushed();
        $this->assertSame('2026-10-04 10:00:00', $automation->fresh()->next_collection_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_run_now_collects_a_weekly_account_on_the_next_tick(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);
        $resource = $this->idleAccount();
        $automation = $this->reconcileRun($resource, CollectionRunStatus::Completed, ['start' => '2026-09-21', 'end' => '2026-09-26']);
        $this->assertNotNull($this->lastLightCheck($resource));

        $this->at('2026-09-27 11:00:00');
        app(ResourceAutomationService::class)->runNow($automation->id, $admin);
        $this->assertNull($this->lastLightCheck($resource));
        $this->admit();

        Queue::assertPushed(ResourceCollectionJob::class, 1);
        $this->assertSame('planning', $automation->fresh()->collection_status);
    }

    public function test_a_failed_weekly_pass_is_replanned_as_an_update_from_its_stored_coverage_through_yesterday(): void
    {
        $resource = $this->idleAccount();
        $this->reconcileRun($resource, CollectionRunStatus::Failed, ['start' => '2026-09-21', 'end' => '2026-09-26']);

        // A week later the old range is not repaired on its own: the update plan continues through yesterday.
        $this->at('2026-10-04 10:00:00');
        $passes = DB::table('collection_activity_passes')->count();
        $plan = $this->plan($resource);

        $this->assertSame('google_ads_central_update', $plan['intent']);
        $this->assertArrayNotHasKey('resumed_from_resource_run_id', $plan);
        $this->assertSame([GoogleAdsCentralRequestFamilyCatalog::ACCOUNT_DAILY], array_column($plan['families'], 'family'));
        $this->assertSame(['start' => '2026-09-21', 'end' => '2026-10-03'], $plan['families'][0]['date_range']);
        $this->assertSame($passes + 1, DB::table('collection_activity_passes')->count(), 'the gate pass is recorded');
    }

    public function test_a_failed_initial_import_and_a_failed_full_update_are_still_repaired(): void
    {
        $new = $this->account('1112229001', spendOn: null, baselineThrough: null);
        $this->failedRun($new, ['collection_intent' => 'google_ads_central_initial', 'history_policy_version' => GoogleAdsCentralCollectionService::HISTORY_POLICY_VERSION],
            ['start' => '2025-10-01', 'end' => '2025-10-31'], 'history_01');
        $initialRepair = $this->plan($new);
        $this->assertSame('google_ads_central_repair', $initialRepair['intent']);
        $this->assertSame(['start' => '2025-10-01', 'end' => '2025-10-31'], $initialRepair['families'][0]['date_range']);

        $active = $this->account('1112229002', spendOn: '2026-09-25', baselineThrough: '2026-09-24');
        $this->failedRun($active, ['collection_intent' => 'google_ads_central_update', 'history_policy_version' => GoogleAdsCentralCollectionService::HISTORY_POLICY_VERSION,
            'activity' => ['mode' => 'full']], ['start' => '2026-09-24', 'end' => '2026-09-26'], 'recent');
        $this->assertSame('google_ads_central_repair', $this->plan($active)['intent']);
    }

    public function test_a_dormant_check_starts_at_the_first_day_its_coverage_misses(): void
    {
        // Without stored coverage: the day after the last completed range (a late or failed week leaves no hole).
        $late = $this->account('1112229101', spendOn: '2026-06-01', baselineThrough: '2026-09-12');
        $this->assertSame(ActivityTier::Dormant, app(ActivityTierService::class)->tierFor((int) $late->id));
        $this->assertSame(['start' => '2026-09-13', 'end' => '2026-09-26'], $this->accountDailyRange($late));

        // On time: the last seven days only.
        $onTime = $this->account('1112229102', spendOn: '2026-06-01', baselineThrough: '2026-09-19');
        $this->assertSame(['start' => '2026-09-20', 'end' => '2026-09-26'], $this->accountDailyRange($onTime));

        // Stored coverage decides: an older hole is filled, …
        $holed = $this->account('1112229103', spendOn: '2026-06-01', baselineThrough: '2026-09-26');
        $this->coverage($holed, [['2025-09-28', '2026-06-30'], ['2026-07-10', '2026-09-26']]);
        $this->assertSame(['start' => '2026-07-01', 'end' => '2026-09-26'], $this->accountDailyRange($holed));

        // … contiguous coverage (zero-row months of the initial import) needs no refetch although the last completed
        // account-daily range ended in an old activity period, …
        $contiguous = $this->account('1112229104', spendOn: '2026-06-01', baselineThrough: '2025-10-31');
        $this->coverage($contiguous, [['2025-09-28', '2026-09-26']]);
        $this->assertSame(['start' => '2026-09-20', 'end' => '2026-09-26'], $this->accountDailyRange($contiguous));

        // … and never earlier than the 13-month granular lookback.
        $ancient = $this->account('1112229105', spendOn: '2026-06-01', baselineThrough: '2026-09-26');
        $this->coverage($ancient, [['2025-01-01', '2025-03-31'], ['2025-10-15', '2026-09-26']]);
        $this->assertSame(['start' => '2025-08-28', 'end' => '2026-09-26'], $this->accountDailyRange($ancient));
    }

    // ---------------------------------------------------------------- helpers

    private function at(string $time): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($time, 'UTC'));
        Carbon::setTestNow(Carbon::parse($time, 'UTC'));
    }

    /** An idle account (last spend 15 days ago) whose account daily totals are covered through 2026-09-20. */
    private function idleAccount(): CoreExternalResource
    {
        $resource = $this->account('1112228001', spendOn: '2026-09-12', baselineThrough: '2026-09-20');
        $this->assertSame(ActivityTier::Idle, app(ActivityTierService::class)->tierFor((int) $resource->id));

        return $resource;
    }

    /** A collected Google Ads account bound to an active brand's asset, with a completed v2 baseline when given. */
    private function account(string $customerId, ?string $spendOn, ?string $baselineThrough): CoreExternalResource
    {
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->google->id, 'provider' => 'google', 'resource_type' => 'google_ads',
            'external_id' => $customerId, 'display_name' => 'Account '.$customerId, 'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['is_manager' => false, 'time_zone' => 'UTC', 'currency_code' => 'TRY'],
        ]);
        ResourceAutomation::query()->create([
            'external_resource_id' => $resource->id, 'collection_enabled' => true,
            'last_collection_success_at' => $baselineThrough !== null ? now()->subDays(7) : null, 'next_collection_at' => now(),
        ]);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_ads', 'status' => DigitalAssetStatus::Active]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id,
            'capability' => 'google_ads', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        if ($spendOn !== null) {
            $this->insertFact('google_ads_account_daily', [
                'external_resource_id' => $resource->id, 'customer_id' => $customerId, 'reporting_date' => $spendOn,
                'impressions' => 100, 'clicks' => 5, 'cost_micros' => 20_000_000, 'conversions' => 0, 'cost_amount' => 20, 'currency' => 'TRY',
                'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
                'record_fingerprint' => hash('sha256', $customerId.$spendOn), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if ($baselineThrough !== null) {
            $run = CollectionRun::factory()->create(['status' => CollectionRunStatus::Completed]);
            $resourceRun = CollectionResourceRun::factory()->create([
                'collection_run_id' => $run->id, 'provider_or_source' => 'GOOGLE_ADS', 'external_resource_id' => $resource->id,
                'digital_asset_id' => null, 'core_asset_binding_id' => null, 'status' => CollectionRunStatus::Completed,
                'metadata' => ['collection_scope' => 'provider_resource_first', 'history_policy_version' => GoogleAdsCentralCollectionService::HISTORY_POLICY_VERSION],
            ]);
            $this->accountDailyDataset($resourceRun, CollectionRunStatus::Completed, ['start' => '2025-09-01', 'end' => $baselineThrough], 'history_01');
        }
        app(ActivityTierService::class)->refreshResource($resource);

        return $resource->fresh();
    }

    /**
     * The account's weekly light run (planned by the gate, resource run metadata.activity) finished with `$status`,
     * reconciled by resource automation.
     *
     * @param  array{start: string, end: string}  $range
     */
    private function reconcileRun(CoreExternalResource $resource, CollectionRunStatus $status, array $range): ResourceAutomation
    {
        $run = CollectionRun::factory()->create(['status' => $status]);
        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id, 'provider_or_source' => 'GOOGLE_ADS', 'external_resource_id' => $resource->id,
            'digital_asset_id' => null, 'core_asset_binding_id' => null, 'status' => $status,
            'metadata' => ['collection_scope' => 'provider_resource_first', 'collection_intent' => 'google_ads_central_update',
                'history_policy_version' => GoogleAdsCentralCollectionService::HISTORY_POLICY_VERSION, 'activity' => ['tier' => 'idle', 'mode' => 'light']],
        ]);
        $this->accountDailyDataset($resourceRun, $status, $range, 'recent');
        $automation = ResourceAutomation::query()->where('external_resource_id', $resource->id)->firstOrFail();
        $automation->update(['collection_run_id' => $run->id, 'collection_status' => 'collecting']);
        (new ReflectionMethod(ResourceAutomationService::class, 'reconcile'))->invoke(app(ResourceAutomationService::class), $automation->fresh());

        return $automation->fresh();
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array{start: string, end: string}  $range
     */
    private function failedRun(CoreExternalResource $resource, array $metadata, array $range, string $variant): void
    {
        $run = CollectionRun::factory()->create(['status' => CollectionRunStatus::Failed]);
        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id, 'provider_or_source' => 'GOOGLE_ADS', 'external_resource_id' => $resource->id,
            'digital_asset_id' => null, 'core_asset_binding_id' => null, 'status' => CollectionRunStatus::Failed,
            'metadata' => $metadata + ['collection_scope' => 'provider_resource_first'],
        ]);
        $this->accountDailyDataset($resourceRun, CollectionRunStatus::Failed, $range, $variant);
    }

    /** @param  array{start: string, end: string}  $range */
    private function accountDailyDataset(CollectionResourceRun $resourceRun, CollectionRunStatus $status, array $range, string $variant): void
    {
        CollectionDatasetRun::factory()->create([
            'collection_run_id' => $resourceRun->collection_run_id, 'collection_resource_run_id' => $resourceRun->id, 'provider_or_source' => 'GOOGLE_ADS',
            'dataset_contract_id' => 'google_ads_account_daily', 'request_family_id' => GoogleAdsCentralRequestFamilyCatalog::ACCOUNT_DAILY,
            'execution_variant' => $variant, 'status' => $status, 'finished_at' => now(),
            'metadata' => ['collection_scope' => 'provider_resource_first', 'date_range' => $range],
        ]);
    }

    /**
     * Central account-daily coverage made of the given inclusive date intervals.
     *
     * @param  list<array{0: string, 1: string}>  $intervals
     */
    private function coverage(CoreExternalResource $resource, array $intervals): void
    {
        $dates = [];
        foreach ($intervals as [$start, $end]) {
            for ($day = CarbonImmutable::parse($start); $day->toDateString() <= $end; $day = $day->addDay()) {
                $dates[] = $day->toDateString();
            }
        }
        DatasetMaterialization::query()->create([
            'dataset_id' => 'google_ads_account_daily', 'digital_asset_id' => null, 'external_resource_id' => $resource->id,
            'provider_or_source' => 'GOOGLE_ADS', 'contract_version' => 1, 'status' => MaterializationStatus::Available,
            'last_collected_at' => now()->subWeek(), 'coverage_start_date' => $dates[0], 'coverage_end_date' => end($dates),
            'row_count_approx' => 1, 'row_count_semantics' => 'approximate_from_batches', 'partial' => count($intervals) > 1,
            'freshness_metadata' => ['successful_coverage_dates' => $dates],
        ]);
    }

    /** @return array{start: string, end: string} */
    private function accountDailyRange(CoreExternalResource $resource): array
    {
        $plan = $this->plan($resource);
        $this->assertSame('check', $plan['activity']['mode']);
        $this->assertSame([GoogleAdsCentralRequestFamilyCatalog::ACCOUNT_DAILY], array_column($plan['families'], 'family'));

        return $plan['families'][0]['date_range'];
    }

    /** @return array<string, mixed> */
    private function plan(CoreExternalResource $resource): array
    {
        return (new ReflectionMethod(GoogleAdsCentralCollectionService::class, 'smartPlan'))
            ->invoke(app(GoogleAdsCentralCollectionService::class), $resource->fresh());
    }

    private function admit(): void
    {
        (new ReflectionMethod(ResourceAutomationService::class, 'admitCollections'))
            ->invoke(app(ResourceAutomationService::class), 'google_ads', 'database');
    }

    private function lastLightCheck(CoreExternalResource $resource): ?string
    {
        return ResourceActivity::query()->where('external_resource_id', $resource->id)->first()?->last_light_check_at?->utc()->format('Y-m-d H:i:s');
    }
}
