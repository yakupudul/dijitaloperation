<?php

namespace Tests\Feature\Observability;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\CollectionRunStatus;
use App\Enums\DataPool\MaterializationStatus;
use App\Enums\Observability\OperationalAlertState;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DigitalAsset;
use App\Models\Observability\OperationalAlert;
use App\Models\ResourceAutomation;
use App\Services\Observability\AlertSubjects;
use App\Services\Observability\OperationalAlertEvaluator;
use App\Services\Observability\OperationalAlertExplainer;
use App\Support\Operator\OperatorMessage;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use Tests\TestCase;

/**
 * "Veri güncel değil": the "Son hata" of a late account is the error of the late dataset itself — not another
 * dataset's failure, not a retry that completed in the end, not a failure a later run fixed.
 */
final class StaleAlertLastErrorTest extends TestCase
{
    use RefreshDatabase;

    private const string NOW = '2026-09-27 10:00:00';

    private const string DEFAULT_ACTION = 'Çekim kendiliğinden yeniden denenir. Hemen güncel veri gerekiyorsa "Şimdi güncelle" ile başlatın.';

    private CoreExternalResource $resource;

    private CoreAssetBinding $binding;

    protected function setUp(): void
    {
        parent::setUp();
        // A software error rings the bell, which looks up the admins.
        $this->seed(RoleAndPermissionSeeder::class);
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::NOW, 'UTC'));
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        [$this->resource, $this->binding] = $this->boundGoogleAdsAccount();
        // google_ads_account_daily last arrived 13 days ago: late.
        $this->materialization('google_ads_account_daily', '2026-09-13', '2026-09-14 03:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_another_datasets_failure_does_not_name_the_cause_of_the_late_one(): void
    {
        $this->datasetRun('google_ads_pmax_asset_daily', CollectionRunStatus::Failed, CollectionErrorCategory::ContractMismatch);

        $message = $this->staleMessage();

        $this->assertStringContainsString('Google Ads günlük harcama ve dönüşümler', $message->what);
        $this->assertStringNotContainsString('yazılım sorunu', $message->what);
        $this->assertStringNotContainsString('Son hata', $message->what);
        $this->assertSame(self::DEFAULT_ACTION, $message->action);
        $this->assertNull($this->staleAlert()->observed['affected'][0]['error_category']);
    }

    public function test_the_late_datasets_own_failure_names_the_cause(): void
    {
        $this->datasetRun('google_ads_account_daily', CollectionRunStatus::Failed, CollectionErrorCategory::ContractMismatch);

        $message = $this->staleMessage();

        $this->assertStringContainsString('Son hata: MoxDOP\'un veri isteği veya kaydı hatalı (yazılım sorunu)', $message->what);
        $this->assertStringContainsString('yazılım ekibine bildirin', $message->action);
        $this->assertSame('contract_mismatch', $this->staleAlert()->observed['affected'][0]['error_category']);
    }

    public function test_a_retried_step_that_completed_does_not_name_the_cause(): void
    {
        // Retried for the daily quota, then completed: the category stays on the row but is history.
        $this->datasetRun('google_ads_account_daily', CollectionRunStatus::Completed, CollectionErrorCategory::Quota);

        $message = $this->staleMessage();

        $this->assertStringNotContainsString('kota', $message->what);
        $this->assertStringNotContainsString('Son hata', $message->what);
        $this->assertSame(self::DEFAULT_ACTION, $message->action);
    }

    public function test_a_failure_a_later_run_fixed_does_not_name_the_cause(): void
    {
        $this->datasetRun('google_ads_account_daily', CollectionRunStatus::Failed, CollectionErrorCategory::ContractMismatch);
        $this->datasetRun('google_ads_account_daily', CollectionRunStatus::Completed, null);

        $message = $this->staleMessage();

        $this->assertStringNotContainsString('Son hata', $message->what);
        $this->assertSame(self::DEFAULT_ACTION, $message->action);
    }

    public function test_a_step_waiting_for_its_retry_names_the_cause(): void
    {
        $this->datasetRun('google_ads_account_daily', CollectionRunStatus::Retrying, CollectionErrorCategory::Quota);

        $this->assertStringContainsString('Son hata: Google günlük istek kotası doldu', $this->staleMessage()->what);
    }

    public function test_the_account_level_error_skips_completed_retries_and_fixed_failures(): void
    {
        $subjects = app(AlertSubjects::class);
        $id = (int) $this->resource->id;
        $this->datasetRun('google_ads_campaign_daily', CollectionRunStatus::Completed, CollectionErrorCategory::Quota);
        $this->assertNull($subjects->lastErrorCategory($id), 'a completed retry is not a current error');

        $this->datasetRun('google_ads_keyword_daily', CollectionRunStatus::Failed, CollectionErrorCategory::Provider5xx);
        $this->datasetRun('google_ads_campaign_daily', CollectionRunStatus::Failed, CollectionErrorCategory::Authorization);
        $this->datasetRun('google_ads_campaign_daily', CollectionRunStatus::Completed, null);
        $this->assertSame('provider_5xx', $subjects->lastErrorCategory($id), 'the campaign failure was fixed by its next run');
        $this->assertNull($subjects->lastErrorCategory($id, ['google_ads_campaign_daily']));

        // Older than a week: not the account's current error.
        CollectionDatasetRun::query()->where('dataset_contract_id', 'google_ads_keyword_daily')->update(['created_at' => now()->subDays(8)]);
        $this->assertNull($subjects->lastErrorCategory($id));
    }

    private function staleMessage(): OperatorMessage
    {
        $this->assertSame(1, (new ReflectionMethod(OperationalAlertEvaluator::class, 'evaluateStaleDatasets'))->invoke(app(OperationalAlertEvaluator::class)));
        $alert = $this->staleAlert();
        $this->assertSame(['google_ads_account_daily'], $alert->observed['affected'][0]['datasets']);

        return app(OperationalAlertExplainer::class)->explain($alert);
    }

    private function staleAlert(): OperationalAlert
    {
        return OperationalAlert::query()->where('rule_key', 'dataset_stale')->where('state', OperationalAlertState::Open->value)->sole();
    }

    private function datasetRun(string $dataset, CollectionRunStatus $status, ?CollectionErrorCategory $category): void
    {
        $run = CollectionRun::factory()->create(['status' => $status === CollectionRunStatus::Retrying ? CollectionRunStatus::Running : $status]);
        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id, 'provider_or_source' => 'GOOGLE_ADS', 'external_resource_id' => $this->resource->id,
            'digital_asset_id' => $this->binding->digital_asset_id, 'status' => $run->status,
        ]);
        CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id, 'collection_resource_run_id' => $resourceRun->id, 'provider_or_source' => 'GOOGLE_ADS',
            'dataset_contract_id' => $dataset, 'status' => $status, 'error_category' => $category,
        ]);
    }

    /** @return array{0: CoreExternalResource, 1: CoreAssetBinding} */
    private function boundGoogleAdsAccount(): array
    {
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => 'google_ads', 'external_id' => '9100000041',
            'display_name' => 'Account 9100000041', 'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['is_manager' => false, 'timezone' => 'UTC', 'currency_code' => 'TRY'],
        ]);
        ResourceAutomation::query()->create([
            'external_resource_id' => $resource->id, 'collection_enabled' => true,
            'last_collection_success_at' => now()->subDay(), 'next_collection_at' => now(),
        ]);
        $brand = Brand::factory()->create(['name' => 'Atlas Dental', 'customer_id' => Customer::factory()->create()->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_ads']);
        $binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id,
            'capability' => 'google_ads', 'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        return [$resource, $binding];
    }

    /** Verified contiguous coverage of a dataset from 2026-06-01 through `$coveredThrough`, last collected at `$collectedAt`. */
    private function materialization(string $datasetId, string $coveredThrough, string $collectedAt): void
    {
        $dates = [];
        for ($day = CarbonImmutable::parse('2026-06-01'); $day->toDateString() <= $coveredThrough; $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }
        DatasetMaterialization::query()->create([
            'dataset_id' => $datasetId,
            'digital_asset_id' => $this->binding->digital_asset_id,
            'external_resource_id' => $this->resource->id,
            'provider_or_source' => 'GOOGLE_ADS',
            'contract_version' => 1,
            'status' => MaterializationStatus::Available,
            'last_collected_at' => $collectedAt,
            'coverage_start_date' => $dates[0],
            'coverage_end_date' => $coveredThrough,
            'row_count_approx' => 1,
            'row_count_semantics' => 'approximate_from_batches',
            'partial' => false,
            'freshness_metadata' => [
                'successful_coverage_dates' => $dates,
                'verified_contiguous_watermark' => $coveredThrough,
                'latest_observed_reporting_date' => $coveredThrough,
                'last_successful_reporting_date' => $coveredThrough,
                'last_reprocess_through' => $coveredThrough,
            ],
        ]);
    }
}
