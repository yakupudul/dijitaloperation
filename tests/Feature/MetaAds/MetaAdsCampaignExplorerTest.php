<?php

namespace Tests\Feature\MetaAds;

use App\Enums\DataPool\IntegrityAuditMode;
use App\Enums\DataPool\IntegrityAuditStatus;
use App\Enums\DataPool\IntegrityCheckStatus;
use App\Enums\DataPool\MaterializationStatus;
use App\Enums\DigitalAssetStatus;
use App\Livewire\Operator\Meta\OverviewPage;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DataPool\DataIntegrityAuditRun;
use App\Models\DataPool\DataIntegrityCheckResult;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\DataPool\Integrity\Support\CoverageIntervalSet;
use App\Services\MetaAds\MetaAdsSpecialistBindingResolver;
use App\Support\Demo\DemoPeriod;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Operator\OperatorReportingPeriod;
use App\Support\Roles;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Meta Ads campaigns tab drill-down / sorting / CSV export and the Creatives tab fatigue table,
 * all read from seeded local Data Pool tables (no Meta API).
 */
final class MetaAdsCampaignExplorerTest extends TestCase
{
    use RefreshDatabase;

    private const string ACCOUNT_ID = '22220002';

    private DigitalAsset $asset;

    private CoreExternalResource $resource;

    /** @var list<string> */
    private array $dates = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(DemoPeriod::ANCHOR_DATE, DemoPeriod::TIMEZONE)->endOfDay());
        app()->setLocale('tr');
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);

        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
            'status' => DigitalAssetStatus::Active,
        ]);

        $integration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => ['auth_method' => 'oauth', 'auth_status' => 'connected', 'connection_status' => 'connected', 'credential_status' => 'valid', 'granted_permissions' => ['ads_read', 'business_management']],
        ]);
        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => ['access_token' => 'EAAG-synthetic-meta-token-never-real', 'granted_permissions' => ['ads_read', 'business_management']],
        ]);
        $this->resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => 'meta',
            'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_'.self::ACCOUNT_ID,
            'display_name' => 'Explorer Test Account',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['currency' => 'EUR', 'timezone_name' => 'Europe/Berlin', 'account_status' => 1],
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'capability' => MetaAdsSpecialistBindingResolver::CAPABILITY,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        $bounds = OperatorReportingPeriod::queryBounds('last_28');
        $cursor = CarbonImmutable::parse($bounds['start']->toDateString());
        $end = $bounds['end']->toDateString();
        while ($cursor->toDateString() <= $end) {
            $this->dates[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }
    }

    public function test_campaign_rows_drill_down_to_adsets_and_ads_with_breadcrumb(): void
    {
        $this->seedHierarchy();

        $page = $this->page(['tab' => 'campaigns']);
        $view = $page->viewData('explorer');
        $this->assertSame('campaigns', $view['level']);
        $this->assertSame(['camp-b', 'camp-a'], array_column($view['rows'], 'id'));
        $page->assertSeeHtml('wire:click="drillCampaign(\'camp-a\')"')
            ->assertSee('Tüm kampanyalar');

        $page->call('drillCampaign', 'camp-a')
            ->assertSet('campaign_level', 'adsets')
            ->assertSet('campaign_filter', 'camp-a');
        $view = $page->viewData('explorer');
        $this->assertEqualsCanonicalizing(['set-a1', 'set-a2'], array_column($view['rows'], 'id'));
        $this->assertSame('Lead Kampanyası', $view['campaign']['name']);
        $page->assertSee('Kampanya: Lead Kampanyası');

        $page->call('drillAdset', 'set-a2', 'camp-a')
            ->assertSet('campaign_level', 'ads')
            ->assertSet('adset_filter', 'set-a2');
        $view = $page->viewData('explorer');
        $this->assertSame(['ad-2'], array_column($view['rows'], 'id'));
        $page->assertSee('Reklam seti: Lead Set A2');

        $page->call('drillUp', 'campaign')->assertSet('campaign_level', 'adsets')->assertSet('adset_filter', '');
        $page->call('drillUp', '')->assertSet('campaign_level', 'campaigns')->assertSet('campaign_filter', '');
        $this->assertCount(2, $page->viewData('explorer')['rows']);
    }

    public function test_url_state_filters_ads_by_campaign_and_legacy_level_without_filter_lists_everything(): void
    {
        $this->seedHierarchy();

        $filtered = Livewire::withQueryParams(['tab' => 'campaigns', 'level' => 'ads', 'campaign' => 'camp-b'])
            ->test(OverviewPage::class, ['assetId' => (string) $this->asset->id]);
        $this->assertSame(['ad-3'], array_column($filtered->viewData('explorer')['rows'], 'id'));

        $legacy = Livewire::withQueryParams(['tab' => 'campaigns', 'level' => 'adsets'])
            ->test(OverviewPage::class, ['assetId' => (string) $this->asset->id]);
        $this->assertCount(3, $legacy->viewData('explorer')['rows']);
    }

    public function test_results_follow_the_objective_and_rows_sort_by_spend_results_and_cost_per_result(): void
    {
        $this->seedHierarchy();

        $page = $this->page(['tab' => 'campaigns']);
        $rows = collect($page->viewData('explorer')['rows'])->keyBy('id');

        // Lead campaign: 2 + 1 leads/day from its ads; the purchase action is not its result.
        // Spend is 30/day and leads 3/day over the same verified days.
        $this->assertEqualsWithDelta($rows['camp-a']['spend'] / 10, $rows['camp-a']['results'], 0.001);
        $this->assertSame('lead', $rows['camp-a']['result_type']);
        $this->assertEqualsWithDelta(10.0, $rows['camp-a']['cost_per_result'], 0.001);
        // Traffic campaign: link clicks are the result.
        $this->assertEqualsWithDelta($rows['camp-b']['spend'] / 50, $rows['camp-b']['results'], 0.001);
        $this->assertEqualsWithDelta(50.0, $rows['camp-b']['cost_per_result'], 0.001);
        $this->assertNotNull($rows['camp-a']['cpm']);
        $this->assertSame((int) round($rows['camp-a']['spend'] * 100), $rows['camp-a']['impressions']);
        $this->assertEqualsWithDelta(10.0, $rows['camp-a']['cpm'], 0.001);

        $page->call('sortCampaignsBy', 'results');
        $this->assertSame(['camp-a', 'camp-b'], array_column($page->viewData('explorer')['rows'], 'id'));

        $page->call('sortCampaignsBy', 'cost_per_result')->assertSet('campaign_direction', 'asc');
        $this->assertSame(['camp-a', 'camp-b'], array_column($page->viewData('explorer')['rows'], 'id'));

        $page->call('sortCampaignsBy', 'cost_per_result')->assertSet('campaign_direction', 'desc');
        $this->assertSame(['camp-b', 'camp-a'], array_column($page->viewData('explorer')['rows'], 'id'));

        $page->call('sortCampaignsBy', 'spend')->assertSet('campaign_sort', 'spend');
        $this->assertSame(['camp-b', 'camp-a'], array_column($page->viewData('explorer')['rows'], 'id'));

        $page->call('sortCampaignsBy', 'not-a-column')->assertSet('campaign_sort', 'spend');
    }

    public function test_csv_export_contains_the_current_filtered_list_with_bom_and_semicolons(): void
    {
        $this->seedHierarchy();

        $page = $this->page(['tab' => 'campaigns']);
        $page->call('drillCampaign', 'camp-a')->call('exportCsv');

        $download = $page->effects['download'] ?? null;
        $this->assertNotNull($download);
        $this->assertStringStartsWith('meta-ads-adsets-', $download['name']);
        $this->assertStringEndsWith('.csv', $download['name']);
        $this->assertStringContainsString('text/csv', (string) $download['contentType']);

        $csv = base64_decode($download['content']);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = array_values(array_filter(explode("\n", substr($csv, 3))));
        $this->assertCount(3, $lines);
        $this->assertStringStartsWith('Seviye;ID;Ad;Kampanya;"Reklam seti";Durum', $lines[0]);
        $this->assertStringContainsString('Sonuç başı maliyet', $lines[0]);
        $body = implode("\n", array_slice($lines, 1));
        $this->assertStringContainsString('set-a1', $body);
        $this->assertStringContainsString('set-a2', $body);
        $this->assertStringNotContainsString('set-b1', $body);
        // Set A2: 20/day spend and 1 lead/day → 20,00 per result; the purchase action is not counted.
        $this->assertMatchesRegularExpression('/set-a2;"Lead Set A2";"Lead Kampanyası";"Lead Set A2";[^;]*;EUR;[0-9]+,00;[0-9]+;[0-9]+;3,00;0,67;20,00;[0-9]+;lead;20,00/u', $body);

        Http::assertNothingSent();
    }

    public function test_creative_fatigue_flags_falling_ctr_with_rising_frequency_sorted_by_severity(): void
    {
        $this->seedHierarchy();

        $page = $this->page(['tab' => 'creatives']);
        $fatigue = $page->viewData('fatigue');

        $this->assertSame('available', $fatigue['state']);
        $this->assertSame(['ad-1', 'ad-3', 'ad-2'], array_column($fatigue['rows'], 'ad_id'));
        $rows = collect($fatigue['rows'])->keyBy('ad_id');

        $this->assertSame('fatigue_high', $rows['ad-1']['status']);
        $this->assertEqualsWithDelta(5.0, $rows['ad-1']['first_ctr'], 0.001);
        $this->assertEqualsWithDelta(2.0, $rows['ad-1']['last_ctr'], 0.001);
        $this->assertEqualsWithDelta(-60.0, $rows['ad-1']['ctr_change'], 0.001);
        $this->assertEqualsWithDelta(1.25, $rows['ad-1']['first_frequency'], 0.001);
        $this->assertEqualsWithDelta(2.5, $rows['ad-1']['last_frequency'], 0.001);
        $this->assertEqualsWithDelta(70.0, $rows['ad-1']['last_spend'], 0.001);
        $this->assertSame('Lead Reklam 1', $rows['ad-1']['name']);

        // CTR fell 40% but frequency stayed flat and low: watch, not fatigue.
        $this->assertSame('watch', $rows['ad-3']['status']);
        $this->assertSame('healthy', $rows['ad-2']['status']);
        $this->assertSame(1, $fatigue['flagged']);

        $page->assertSee('Kreatif yorgunluğu')->assertSee('Yorgunluk (yüksek)')->assertSee('İzle');
        Http::assertNothingSent();
    }

    public function test_creative_fatigue_says_so_honestly_when_ad_daily_data_is_not_collected(): void
    {
        $this->seedHierarchy(withAdDaily: false);

        $page = $this->page(['tab' => 'creatives']);

        $this->assertSame('unavailable', $page->viewData('fatigue')['state']);
        $this->assertSame([], $page->viewData('fatigue')['rows']);
        $page->assertSee('Reklam düzeyinde günlük veri bu dönem için toplanmamış veya doğrulanmamış.');
    }

    /** @param array<string, string> $query */
    private function page(array $query): Testable
    {
        return Livewire::withQueryParams($query)->test(OverviewPage::class, ['assetId' => (string) $this->asset->id]);
    }

    private function seedHierarchy(bool $withAdDaily = true): void
    {
        foreach (['meta_campaign_daily', 'meta_adset_daily', 'meta_typed_action_daily'] as $dataset) {
            $this->seedDatasetReady($dataset, $this->dates);
        }
        if ($withAdDaily) {
            $this->seedDatasetReady('meta_ad_daily', $this->dates);
        }
        $this->seedSnapshotReady('meta_ad_snapshot');

        $this->snapshot('meta_campaign_snapshot', 'campaign_id', 'camp-a', ['name' => 'Lead Kampanyası', 'objective' => 'OUTCOME_LEADS', 'status' => 'ACTIVE']);
        $this->snapshot('meta_campaign_snapshot', 'campaign_id', 'camp-b', ['name' => 'Trafik Kampanyası', 'objective' => 'OUTCOME_TRAFFIC', 'status' => 'ACTIVE']);
        $this->snapshot('meta_adset_snapshot', 'adset_id', 'set-a1', ['name' => 'Lead Set A1', 'campaign_id' => 'camp-a', 'optimization_goal' => 'LEAD_GENERATION']);
        $this->snapshot('meta_adset_snapshot', 'adset_id', 'set-a2', ['name' => 'Lead Set A2', 'campaign_id' => 'camp-a', 'optimization_goal' => 'LEAD_GENERATION']);
        $this->snapshot('meta_adset_snapshot', 'adset_id', 'set-b1', ['name' => 'Trafik Set B1', 'campaign_id' => 'camp-b', 'optimization_goal' => 'LINK_CLICKS']);

        foreach ([['ad-1', 'Lead Reklam 1', 'camp-a', 'set-a1'], ['ad-2', 'Lead Reklam 2', 'camp-a', 'set-a2'], ['ad-3', 'Trafik Reklam 3', 'camp-b', 'set-b1']] as [$adId, $name, $campaignId, $adsetId]) {
            DB::table('meta_ad_snapshot')->insert($this->provenance('ad-snap-'.$adId) + [
                'ad_id' => $adId, 'ad_name' => $name, 'campaign_id' => $campaignId, 'adset_id' => $adsetId,
                'creative_id' => 'crv-'.$adId, 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE',
            ]);
        }

        // Today's partial day is not a verified reporting day; ad rows stop the day before.
        $adDates = array_slice($this->dates, 0, -1);
        $count = count($adDates);
        foreach ($this->dates as $index => $date) {
            $this->daily('meta_campaign_daily', 'campaign_id', 'camp-a', $date, 30.0, 3000, 120);
            $this->daily('meta_campaign_daily', 'campaign_id', 'camp-b', $date, 50.0, 2000, 80);
            $this->daily('meta_adset_daily', 'adset_id', 'set-a1', $date, 10.0, 1000, 40);
            $this->daily('meta_adset_daily', 'adset_id', 'set-a2', $date, 20.0, 1000, 30);
            $this->daily('meta_adset_daily', 'adset_id', 'set-b1', $date, 50.0, 2000, 80);

            $this->action($date, 'ad-1', 'lead', 2);
            $this->action($date, 'ad-2', 'lead', 1);
            $this->action($date, 'ad-2', 'offsite_conversion.fb_pixel_purchase', 5);
            $this->action($date, 'ad-3', 'link_click', 1);

            if (! $withAdDaily || $index >= $count) {
                continue;
            }

            $early = $index < 7;
            $late = $index >= $count - 7;
            // ad-1: CTR 5% → 2%, daily frequency 1.25 → 2.5. Spend 10/day.
            $this->adDaily($date, 'ad-1', 'camp-a', 'set-a1', 10.0, 1000, $late ? 20 : 50, $late ? 400 : 800);
            // ad-2: stable 3% CTR, frequency ~1.43.
            $this->adDaily($date, 'ad-2', 'camp-a', 'set-a2', 20.0, 1000, 30, 700);
            // ad-3: CTR 2.5% → 1.5%, frequency flat 1.25.
            $this->adDaily($date, 'ad-3', 'camp-b', 'set-b1', 50.0, 2000, $early ? 50 : ($late ? 30 : 40), 1600);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function snapshot(string $table, string $idColumn, string $id, array $metadata): void
    {
        DB::table($table)->insert($this->provenance($table.'-'.$id, json_encode($metadata)) + [$idColumn => $id]);
    }

    private function daily(string $table, string $idColumn, string $id, string $date, float $spend, int $impressions, int $clicks): void
    {
        DB::table($table)->insert($this->provenance($table.'-'.$id.'-'.$date) + [
            $idColumn => $id, 'reporting_date' => $date, 'spend' => $spend, 'impressions' => $impressions, 'clicks' => $clicks, 'currency' => 'EUR',
        ]);
    }

    private function adDaily(string $date, string $adId, string $campaignId, string $adsetId, float $spend, int $impressions, int $clicks, int $reach): void
    {
        DB::table('meta_ad_daily')->insert($this->provenance('ad-'.$adId.'-'.$date, json_encode(['campaign_id' => $campaignId, 'adset_id' => $adsetId])) + [
            'ad_id' => $adId, 'reporting_date' => $date, 'spend' => $spend, 'impressions' => $impressions, 'clicks' => $clicks, 'reach' => $reach, 'currency' => 'EUR',
        ]);
    }

    private function action(string $date, string $adId, string $type, float $value): void
    {
        DB::table('meta_typed_action_daily')->insert($this->provenance('action-'.$adId.'-'.$type.'-'.$date) + [
            'reporting_date' => $date, 'entity_level' => 'ad', 'entity_id' => $adId, 'action_type' => $type, 'action_value' => $value, 'currency' => 'EUR',
        ]);
    }

    /** @return array<string, mixed> */
    private function provenance(string $key, ?string $metadata = null): array
    {
        return [
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'account_id' => self::ACCOUNT_ID,
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'Europe/Berlin',
            'record_fingerprint' => hash('sha256', $key),
            'metadata' => $metadata,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @param list<string> $dates */
    private function seedDatasetReady(string $datasetId, array $dates): void
    {
        $set = CoverageIntervalSet::fromSuccessfulDates($dates);
        DatasetMaterialization::query()->create([
            'dataset_id' => $datasetId,
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'provider_or_source' => 'META_ADS',
            'contract_version' => 1,
            'status' => MaterializationStatus::Available,
            'last_collected_at' => CarbonImmutable::parse(DemoPeriod::ANCHOR_DATE.' 10:00:00', 'UTC'),
            'coverage_start_date' => $set->bounds()['start'],
            'coverage_end_date' => $set->bounds()['end'],
            'row_count_approx' => 0,
            'row_count_semantics' => 'approximate_from_batches',
            'partial' => false,
            'freshness_metadata' => [
                'successful_coverage_dates' => $dates,
                'coverage_intervals' => $set->intervals,
                'internal_gaps' => $set->internalGaps(),
                'verified_contiguous_watermark' => $set->verifiedContiguousWatermark(),
                'latest_observed_reporting_date' => $set->bounds()['end'],
                'last_successful_reporting_date' => $set->verifiedContiguousWatermark(),
            ],
        ]);
        $this->seedIntegrityCheck($datasetId);
    }

    private function seedSnapshotReady(string $datasetId): void
    {
        DatasetMaterialization::query()->create([
            'dataset_id' => $datasetId,
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'provider_or_source' => 'META_ADS',
            'contract_version' => 1,
            'status' => MaterializationStatus::Available,
            'last_collected_at' => CarbonImmutable::parse(DemoPeriod::ANCHOR_DATE.' 10:00:00', 'UTC'),
            'coverage_start_date' => null,
            'coverage_end_date' => null,
            'row_count_approx' => 1,
            'row_count_semantics' => 'exact',
            'partial' => false,
            'freshness_metadata' => [],
        ]);
        $this->seedIntegrityCheck($datasetId);
    }

    private function seedIntegrityCheck(string $datasetId): void
    {
        $run = DataIntegrityAuditRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'status' => IntegrityAuditStatus::Completed,
            'mode' => IntegrityAuditMode::LocalIntegrity,
            'scope_type' => 'dataset',
            'scope' => ['dataset_id' => $datasetId],
            'contract_registry_version' => 1,
            'storage_contract_version' => 1,
            'formula_registry_version' => 1,
            'integrity_registry_version' => 1,
            'audit_rules_version' => 1,
            'started_at' => now(),
            'completed_at' => now(),
            'checks_total' => 1,
            'checks_pass' => 1,
            'checks_fail' => 0,
        ]);

        DataIntegrityCheckResult::query()->create([
            'audit_run_id' => $run->id,
            'provider_or_source' => 'META_ADS',
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'dataset_id' => $datasetId,
            'check_id' => 'natural_key_uniqueness',
            'category' => 'integrity',
            'severity' => 'info',
            'status' => IntegrityCheckStatus::Pass,
            'message' => 'test check',
            'blocks_migration' => false,
        ]);
    }
}
