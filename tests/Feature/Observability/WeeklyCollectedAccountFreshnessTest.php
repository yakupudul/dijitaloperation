<?php

namespace Tests\Feature\Observability;

use App\Enums\Collection\ActivityTier;
use App\Enums\DataPool\FreshnessState;
use App\Enums\DataPool\MaterializationStatus;
use App\Enums\Observability\OperationalAlertState;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DigitalAsset;
use App\Models\Observability\OperationalAlert;
use App\Models\ResourceActivity;
use App\Models\ResourceAutomation;
use App\Services\Collection\Activity\ActivityTierService;
use App\Services\Collection\DataContractRegistryLoader;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsCentralRequestFamilyCatalog;
use App\Services\DataPool\Freshness\DueCollectionQueryService;
use App\Services\DataPool\Freshness\Support\DueCollectionItem;
use App\Services\Observability\OperationalAlertEvaluator;
use App\Services\Operations\SystemHealthReader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\Support\InsertsFacts;
use Tests\TestCase;

/**
 * Idle / dormant accounts are collected weekly with the light set only: the data freshness alert and System health
 * judge them by that rhythm, and accounts not collected on purpose (switched off, manager, not enabled) are not late.
 */
final class WeeklyCollectedAccountFreshnessTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    private const string NOW = '2026-09-27 10:00:00';

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

    public function test_idle_account_is_judged_by_its_light_set_with_the_weekly_interval_as_grace(): void
    {
        [$resource, $binding] = $this->boundAccount('meta_ads', 'act_9100001');
        $this->spend($resource, '2026-09-10');
        $this->assertSame(ActivityTier::Idle, app(ActivityTierService::class)->refreshResource($resource)->tier);
        ResourceActivity::query()->where('external_resource_id', $resource->id)->update(['last_light_check_at' => '2026-09-21 03:00:00']);
        // The weekly light pass ran six days ago; the other datasets are not collected while the account is idle.
        foreach (['meta_account_daily', 'meta_campaign_daily', 'meta_adset_daily'] as $dataset) {
            $this->materialization($binding, $dataset, '2026-09-20', '2026-09-21 03:00:00');
        }

        $this->assertSame(0, $this->evaluateStale());
        $this->assertNull($this->openStaleAlert());

        $items = app(DueCollectionQueryService::class)->query(['core_asset_binding_ids' => [$binding->id], 'activity_tiers' => true]);
        $this->assertSame(['meta_account_daily'], $this->datasets($items));
        $this->assertSame(FreshnessState::Due, $items[0]->freshnessState);

        // A status read records no gate pass: the weekly clock (and so the weekly collection) does not move.
        $this->assertSame(0, DB::table('collection_activity_passes')->count());
        $this->assertSame('2026-09-21 03:00:00', ResourceActivity::query()->where('external_resource_id', $resource->id)->first()->last_light_check_at->format('Y-m-d H:i:s'));

        // The weekly pass was missed: past 7 days + SLA the light set is late, and only the light set.
        $this->materialization($binding, 'meta_account_daily', '2026-09-16', '2026-09-17 03:00:00');
        $this->assertSame(1, $this->evaluateStale());
        $alert = $this->openStaleAlert();
        $this->assertSame(1, $alert->observed['stale_or_blocked_count']);
        $this->assertSame(['meta_account_daily'], $alert->observed['affected'][0]['datasets']);
    }

    public function test_dormant_and_paused_accounts_follow_the_weekly_rhythm_too(): void
    {
        [$dormant, $dormantBinding] = $this->boundAccount('meta_ads', 'act_9100002');
        $this->spend($dormant, '2026-07-01');
        [$paused, $pausedBinding] = $this->boundAccount('meta_ads', 'act_9100003');
        $this->spend($paused, '2026-09-26');
        $tiers = app(ActivityTierService::class);
        $this->assertSame(ActivityTier::Dormant, $tiers->refreshResource($dormant)->effectiveTier());
        $this->assertSame(ActivityTier::Dormant, $tiers->pause((int) $paused->id)->effectiveTier());
        foreach ([$dormantBinding, $pausedBinding] as $binding) {
            $this->materialization($binding, 'meta_account_daily', '2026-09-20', '2026-09-21 03:00:00');
            $this->materialization($binding, 'meta_ad_daily', '2026-09-20', '2026-09-21 03:00:00');
        }

        $this->assertSame(0, $this->evaluateStale());
    }

    public function test_active_account_keeps_the_normal_freshness_sla(): void
    {
        [$resource, $binding] = $this->boundAccount('meta_ads', 'act_9100004');
        $this->spend($resource, '2026-09-26');
        $this->assertSame(ActivityTier::Active, app(ActivityTierService::class)->refreshResource($resource)->tier);
        foreach (['meta_account_daily', 'meta_campaign_daily', 'meta_adset_daily'] as $dataset) {
            $this->materialization($binding, $dataset, '2026-09-20', '2026-09-21 03:00:00');
        }

        $this->assertSame(1, $this->evaluateStale());
        $alert = $this->openStaleAlert();
        $this->assertSame(3, $alert->observed['stale_or_blocked_count']);
        $this->assertEqualsCanonicalizing(['meta_account_daily', 'meta_campaign_daily', 'meta_adset_daily'], $alert->observed['affected'][0]['datasets']);
    }

    public function test_with_activity_aware_collection_switched_off_an_idle_account_is_judged_daily(): void
    {
        [$resource, $binding] = $this->boundAccount('meta_ads', 'act_9100005');
        $this->spend($resource, '2026-09-10');
        app(ActivityTierService::class)->refreshResource($resource);
        foreach (['meta_account_daily', 'meta_campaign_daily'] as $dataset) {
            $this->materialization($binding, $dataset, '2026-09-20', '2026-09-21 03:00:00');
        }
        config(['moxdop-collection-activity.enabled' => false]);

        $this->assertSame(1, $this->evaluateStale());
        $this->assertSame(2, $this->openStaleAlert()->observed['stale_or_blocked_count']);
    }

    public function test_switched_off_and_parked_accounts_are_not_late_but_an_account_to_reconnect_is(): void
    {
        $accounts = [
            'off' => $this->boundAccount('google_ads', '9100000011'),
            'manager' => $this->boundAccount('google_ads', '9100000012', ['is_manager' => true]),
            'not_enabled' => $this->boundAccount('google_ads', '9100000013', ['not_enabled_at' => now()->subDay()->toIso8601String()]),
            'reconnect' => $this->boundAccount('google_ads', '9100000014', resourceStatus: CoreExternalResource::STATUS_UNAVAILABLE),
        ];
        ResourceAutomation::query()->where('external_resource_id', $accounts['off'][0]->id)->update(['collection_enabled' => false]);
        foreach ($accounts as [, $binding]) {
            $this->materialization($binding, 'google_ads_campaign_daily', '2026-09-20', '2026-09-21 03:00:00', 'GOOGLE_ADS');
        }

        $this->assertSame(1, $this->evaluateStale());
        $alert = $this->openStaleAlert();
        $this->assertSame(1, $alert->observed['stale_or_blocked_count']);
        $this->assertSame([(int) $accounts['reconnect'][0]->id], array_column($alert->observed['affected'], 'resource_id'));

        // Nothing left late: the alert resolves.
        ResourceAutomation::query()->where('external_resource_id', $accounts['reconnect'][0]->id)->update(['collection_enabled' => false]);
        $this->assertSame(0, $this->evaluateStale());
        $this->assertNull($this->openStaleAlert());
    }

    public function test_google_ads_light_set_is_matched_by_the_dataset_it_writes(): void
    {
        [$resource, $binding] = $this->boundAccount('google_ads', '9100000021');
        $this->spend($resource, '2026-09-12');
        $this->assertSame(ActivityTier::Idle, app(ActivityTierService::class)->refreshResource($resource)->tier);
        $this->materialization($binding, 'google_ads_account_daily', '2026-09-20', '2026-09-21 03:00:00', 'GOOGLE_ADS');
        $this->materialization($binding, 'google_ads_campaign_daily', '2026-09-20', '2026-09-21 03:00:00', 'GOOGLE_ADS');

        $weekly = app(DueCollectionQueryService::class)->query(['core_asset_binding_ids' => [$binding->id], 'activity_tiers' => true]);
        $this->assertSame(['google_ads_account_daily'], $this->datasets($weekly));
        $this->assertSame(FreshnessState::Due, $weekly[0]->freshnessState);

        // Every other caller keeps the daily view.
        $daily = app(DueCollectionQueryService::class)->query(['core_asset_binding_ids' => [$binding->id]]);
        $stale = array_filter($daily, fn (DueCollectionItem $item): bool => $item->freshnessState === FreshnessState::Stale);
        $this->assertEqualsCanonicalizing(['google_ads_account_daily', 'google_ads_campaign_daily'], $this->datasets(array_values($stale)));
    }

    public function test_light_datasets_are_the_datasets_the_light_families_write(): void
    {
        $contracts = app(DataContractRegistryLoader::class);
        $contracts->load();
        $written = [];
        foreach ($contracts->requirements() as $requirement) {
            if (is_string($requirement['request_family'] ?? null) && is_string($requirement['dataset'] ?? null)) {
                $written[$requirement['request_family']] ??= $requirement['dataset'];
            }
        }
        $providers = array_keys((array) config('moxdop-collection-activity.light_families'));
        $this->assertEqualsCanonicalizing($providers, array_keys((array) config('moxdop-collection-activity.light_datasets')));
        foreach ($providers as $provider) {
            $datasets = array_map(fn (string $family): ?string => $provider === 'GOOGLE_ADS'
                ? GoogleAdsCentralRequestFamilyCatalog::definition($family)['dataset_id']
                : ($written[$family] ?? null), (array) config('moxdop-collection-activity.light_families.'.$provider));
            $this->assertSame($datasets, config('moxdop-collection-activity.light_datasets.'.$provider), $provider);
        }
    }

    public function test_system_health_gives_weekly_collected_accounts_the_weekly_interval(): void
    {
        [$active] = $this->boundAccount('google_ads', '9100000031');
        $this->spend($active, '2026-09-26');
        [$idle] = $this->boundAccount('google_ads', '9100000032');
        $this->spend($idle, '2026-09-12');
        [$idleLate] = $this->boundAccount('google_ads', '9100000033');
        $this->spend($idleLate, '2026-09-12');
        app(ActivityTierService::class)->refresh();
        ResourceAutomation::query()->whereIn('external_resource_id', [$active->id, $idle->id])->update(['last_collection_success_at' => now()->subDays(5)]);
        ResourceAutomation::query()->where('external_resource_id', $idleLate->id)->update(['last_collection_success_at' => now()->subDays(11)]);

        $stale = collect(app(SystemHealthReader::class)->read()['accounts'])->pluck('stale', 'name');

        $this->assertTrue($stale['Account 9100000031'], 'active: daily interval + 3 days');
        $this->assertFalse($stale['Account 9100000032'], 'idle: weekly interval + 3 days');
        $this->assertTrue($stale['Account 9100000033'], 'idle past the weekly interval + 3 days');
    }

    // ---------------------------------------------------------------- helpers

    private function evaluateStale(): int
    {
        return (new ReflectionMethod(OperationalAlertEvaluator::class, 'evaluateStaleDatasets'))->invoke(app(OperationalAlertEvaluator::class));
    }

    private function openStaleAlert(): ?OperationalAlert
    {
        return OperationalAlert::query()->where('rule_key', 'dataset_stale')->where('state', OperationalAlertState::Open->value)->first();
    }

    /**
     * @param  list<DueCollectionItem>  $items
     * @return list<string>
     */
    private function datasets(array $items): array
    {
        return array_values(array_unique(array_map(fn (DueCollectionItem $item): string => $item->datasetId, $items)));
    }

    /**
     * A collected account (with its automation) bound to an operational brand's asset.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{0: CoreExternalResource, 1: CoreAssetBinding}
     */
    private function boundAccount(string $type, string $externalId, array $metadata = [], string $resourceStatus = CoreExternalResource::STATUS_AVAILABLE): array
    {
        $provider = $type === 'meta_ads' ? 'meta' : 'google';
        $integration = CoreIntegration::query()->where('provider', $provider)->first() ?? ($provider === 'meta'
            ? CoreIntegration::factory()->meta()->create(['status' => CoreIntegration::STATUS_ACTIVE])
            : CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]));
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'provider' => $provider, 'resource_type' => $type, 'external_id' => $externalId,
            'display_name' => 'Account '.$externalId, 'status' => $resourceStatus,
            'metadata' => $metadata + ['is_manager' => false, 'timezone' => 'UTC', 'currency_code' => 'TRY'],
        ]);
        ResourceAutomation::query()->create([
            'external_resource_id' => $resource->id, 'collection_enabled' => true,
            'last_collection_success_at' => now()->subDay(), 'next_collection_at' => now(),
        ]);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => $type]);
        $binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id,
            'capability' => $type, 'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        return [$resource, $binding];
    }

    /** One day of account spend (the activity signal). */
    private function spend(CoreExternalResource $resource, string $date): void
    {
        $provenance = ['contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', $resource->id.$date), 'created_at' => now(), 'updated_at' => now()];
        if ($resource->resource_type === 'meta_ads') {
            $this->insertFact('meta_account_daily', [
                'external_resource_id' => $resource->id, 'account_id' => preg_replace('/\D+/', '', (string) $resource->external_id),
                'reporting_date' => $date, 'spend' => 10, 'impressions' => 100, 'clicks' => 5, 'currency' => 'TRY',
            ] + $provenance);

            return;
        }
        $this->insertFact('google_ads_account_daily', [
            'external_resource_id' => $resource->id, 'customer_id' => (string) $resource->external_id, 'reporting_date' => $date,
            'impressions' => 100, 'clicks' => 5, 'cost_micros' => 10_000_000, 'conversions' => 0, 'cost_amount' => 10, 'currency' => 'TRY',
        ] + $provenance);
    }

    /** Verified contiguous coverage of a dataset from 2026-06-01 through `$coveredThrough`, last collected at `$collectedAt`. */
    private function materialization(CoreAssetBinding $binding, string $datasetId, string $coveredThrough, string $collectedAt, string $provider = 'META_ADS'): void
    {
        DatasetMaterialization::query()->where('dataset_id', $datasetId)->where('external_resource_id', $binding->external_resource_id)->delete();
        $dates = [];
        for ($day = CarbonImmutable::parse('2026-06-01'); $day->toDateString() <= $coveredThrough; $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }
        DatasetMaterialization::query()->create([
            'dataset_id' => $datasetId,
            'digital_asset_id' => $binding->digital_asset_id,
            'external_resource_id' => $binding->external_resource_id,
            'provider_or_source' => $provider,
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
