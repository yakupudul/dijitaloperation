<?php

namespace Tests\Feature\Ga4;

use App\Enums\DataPool\MaterializationStatus;
use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DigitalAsset;
use App\Services\DataPool\PartitionManager;
use App\Services\Ga4\Ga4SpecialistBindingResolver;
use App\Services\Ga4\WebsiteGa4AnalysisService;
use App\Support\Integrations\Google\GoogleResourceType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WebsiteGa4AnalysisServiceTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $website;

    private CoreExternalResource $resource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-08-01 12:00:00', 'UTC'));

        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $this->website = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'status' => DigitalAssetStatus::Active,
        ]);
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $this->resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => 'google',
            'resource_type' => GoogleResourceType::GA4_PROPERTY,
            'external_id' => 'properties/777',
            'display_name' => 'Website GA4',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->website->id,
            'external_resource_id' => $this->resource->id,
            'capability' => Ga4SpecialistBindingResolver::CAPABILITY,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    #[Test]
    public function totals_are_unavailable_when_run_coverage_has_a_gap_even_if_fact_rows_span_the_range(): void
    {
        $dates = $this->dates('2026-07-01', 10);
        $this->insertPropertyDaily($dates, sessions: 10);
        $this->proveCoverage(array_values(array_diff($dates, ['2026-07-05'])));

        $result = $this->build('2026-07-01', '2026-07-10');

        $this->assertNull($this->metric($result, 'sessions'));
        $this->assertFalse($result['has_data']);
        $this->assertFalse($result['coverage']['complete']);
        $this->assertSame('2026-07-10', $result['coverage']['end']);
    }

    #[Test]
    public function totals_are_exposed_only_for_fully_proven_range_and_comparison_needs_its_own_coverage(): void
    {
        $current = $this->dates('2026-07-01', 10);
        $previous = $this->dates('2026-06-26', 5);
        $this->insertPropertyDaily(array_merge($previous, $current), sessions: 10);
        $this->proveCoverage(array_merge($previous, $current));

        $result = $this->build('2026-07-01', '2026-07-10', compare: true);

        $this->assertSame(100, $this->metric($result, 'sessions'));
        $this->assertNull($this->metricDelta($result, 'sessions'), 'comparison window 06-21..06-30 is only half covered');
        $this->assertTrue($result['coverage']['complete']);
    }

    #[Test]
    public function channel_share_uses_full_population_and_purchases_are_unrestricted(): void
    {
        $dates = $this->dates('2026-07-01', 2);
        $this->insertPropertyDaily($dates, sessions: 90);
        $this->proveCoverage($dates);

        foreach ($dates as $date) {
            foreach (range(1, 9) as $i) {
                $this->insertFact('ga4_acquisition_channel_daily', $date, ['sessionDefaultChannelGroup' => 'Channel '.$i, 'sessions' => 5, 'engagedSessions' => 1]);
            }
            foreach (range(1, 11) as $i) {
                $this->insertFact('ga4_ecommerce_item_daily', $date, ['itemId' => 'sku-'.$i, 'itemName' => 'Item '.$i, 'itemCategory' => 'c', 'itemsViewed' => 3, 'itemsPurchased' => 1, 'itemRevenue' => '10']);
            }
        }

        $result = $this->build('2026-07-01', '2026-07-02');

        $this->assertSame(22, $result['ecommerce']['purchases']);
        $this->assertCount(8, $result['channels']);
        $this->assertSame(11.1, $result['channels'][0]['share']);
        $this->assertCount(10, $result['ecommerce']['items']);
    }

    #[Test]
    public function legacy_campaign_rows_are_not_double_counted_with_expanded_grain_rows(): void
    {
        $dates = $this->dates('2026-07-01', 2);
        $this->insertPropertyDaily($dates, sessions: 90);
        $this->proveCoverage($dates);

        // Day 1 was re-collected at the expanded grain; day 2 only has the legacy row.
        $this->insertFact('ga4_campaign_daily', '2026-07-01', ['sessionCampaignName' => 'spring', 'sessions' => 30, 'engagedSessions' => 3]);
        $this->insertFact('ga4_campaign_daily', '2026-07-01', ['sessionCampaignName' => 'spring', 'sessionCampaignId' => '42', 'sessionSource' => 'google', 'sessionMedium' => 'cpc', 'sessions' => 30, 'engagedSessions' => 3]);
        $this->insertFact('ga4_campaign_daily', '2026-07-02', ['sessionCampaignName' => 'spring', 'sessions' => 5, 'engagedSessions' => 1]);

        $result = $this->build('2026-07-01', '2026-07-02');

        $this->assertSame([['label' => 'spring', 'sessions' => 35, 'engaged' => 4]], $result['campaigns']);
    }

    /** @return array<string, mixed> */
    private function build(string $start, string $end, bool $compare = false): array
    {
        return app(WebsiteGa4AnalysisService::class)->build($this->website, 'custom', $start, $end, $compare, 'previous');
    }

    private function metric(array $result, string $key): int|float|null
    {
        foreach ($result['metrics'] as $metric) {
            if ($metric['key'] === $key) {
                return $metric['value'];
            }
        }

        $this->fail("metric {$key} missing");
    }

    private function metricDelta(array $result, string $key): ?float
    {
        foreach ($result['metrics'] as $metric) {
            if ($metric['key'] === $key) {
                return $metric['delta'];
            }
        }

        $this->fail("metric {$key} missing");
    }

    /** @param list<string> $dates */
    private function proveCoverage(array $dates): void
    {
        sort($dates);
        DatasetMaterialization::query()->create([
            'dataset_id' => 'ga4_property_daily',
            'digital_asset_id' => null,
            'external_resource_id' => $this->resource->id,
            'provider_or_source' => 'GA4',
            'contract_version' => 1,
            'status' => MaterializationStatus::Available,
            'last_collected_at' => now(),
            'coverage_start_date' => $dates[0],
            'coverage_end_date' => $dates[count($dates) - 1],
            'row_count_approx' => 0,
            'row_count_semantics' => 'approximate_from_batches',
            'partial' => false,
            'freshness_metadata' => ['successful_coverage_dates' => $dates],
        ]);
    }

    /** @param list<string> $dates */
    private function insertPropertyDaily(array $dates, int $sessions): void
    {
        foreach ($dates as $date) {
            $this->insertFact('ga4_property_daily', $date, [
                'sessions' => $sessions,
                'engagedSessions' => (int) floor($sessions / 2),
                'screenPageViews' => $sessions * 2,
                'activeUsers' => 1,
                'totalUsers' => 1,
                'userEngagementDuration' => 10,
            ]);
        }
    }

    /** @param array<string, mixed> $values */
    private function insertFact(string $table, string $date, array $values): void
    {
        $this->ensurePartitionFor($table, $date);
        DB::table($table)->insert(array_merge([
            'digital_asset_id' => null,
            'external_resource_id' => $this->resource->id,
            'property_id' => '777',
            'reporting_date' => $date,
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', $table.$date.json_encode($values)),
            'created_at' => now(),
            'updated_at' => now(),
        ], $values));
    }

    /** PostgreSQL range-partitioned fact tables need their monthly partition before a raw insert. */
    private function ensurePartitionFor(string $table, string $date): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        $partitioned = DB::selectOne(
            'SELECT 1 AS ok FROM pg_partitioned_table p JOIN pg_class c ON c.oid = p.partrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relname = ? AND n.nspname = current_schema()',
            [$table],
        );
        if ($partitioned !== null) {
            app(PartitionManager::class)->ensureRange($table, $date, $date);
        }
    }

    /** @return list<string> */
    private function dates(string $start, int $count): array
    {
        $cursor = CarbonImmutable::parse($start);

        return array_map(static fn (int $i): string => $cursor->addDays($i)->toDateString(), range(0, $count - 1));
    }
}
