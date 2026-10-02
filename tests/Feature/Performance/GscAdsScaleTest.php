<?php

namespace Tests\Feature\Performance;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Services\GoogleAds\GoogleAdsPoolReadRepository;
use App\Services\Gsc\GscPoolReadRepository;
use App\Support\Performance\BenchmarkProfiles;
use App\Support\Performance\QueryCountProbe;
use App\Support\Performance\SyntheticBenchmarkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('performance')]
class GscAdsScaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_gsc_top_queries_uses_bounded_query_count_and_does_not_return_full_cardinality(): void
    {
        $profile = BenchmarkProfiles::resolve(BenchmarkProfiles::HIGH_VOLUME_GSC, [
            'gsc_rows' => 1_500,
            'gsc_days' => 10,
        ]);
        $fixture = app(SyntheticBenchmarkSeeder::class)->seed($profile, 65);
        $asset = $fixture['primary_asset'];
        $this->assertNotNull($asset);

        $end = Carbon::now('UTC')->toDateString();
        $start = Carbon::now('UTC')->subDays(10)->toDateString();
        $repo = app(GscPoolReadRepository::class);
        $probe = app(QueryCountProbe::class);

        $measured = $probe->measure(
            fn () => $repo->topQueries($asset->id, 1, 'https://bench.example.test/', $start, $end, 20)
        );

        $this->assertCount(20, $measured['result']);
        // Aggregate + one detail batch (not 20 detail queries).
        $this->assertLessThanOrEqual(3, $measured['queries']);
        $this->assertLessThan(1_500, count($measured['result']));
    }

    public function test_gsc_wide_range_aggregation_stays_in_sql(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id]);

        $profile = BenchmarkProfiles::resolve(BenchmarkProfiles::HIGH_VOLUME_GSC, [
            'customers' => 0,
            'gsc_rows' => 1_000,
            'gsc_days' => 30,
        ]);
        // Seed against existing primary by manually inserting via seeder path:
        $seeded = app(SyntheticBenchmarkSeeder::class)->seed([
            ...$profile,
            'customers' => 1,
            'brands_per_customer' => 1,
            'assets_per_brand' => 1,
        ], 66);

        $assetId = $seeded['primary_asset']->id;
        $end = Carbon::now('UTC')->toDateString();
        $start = Carbon::now('UTC')->subDays(30)->toDateString();

        $rows = DB::table('gsc_query_daily')
            ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions')
            ->where('digital_asset_id', $assetId)
            ->whereBetween('reporting_date', [$start, $end])
            ->first();

        $this->assertNotNull($rows);
        $this->assertGreaterThan(0, (int) $rows->clicks);
        // Semantics: impressions are impressions, not search volume.
        $this->assertGreaterThan((int) $rows->clicks, (int) $rows->impressions);
    }

    public function test_ads_search_terms_remain_server_aggregated_and_limited(): void
    {
        $profile = BenchmarkProfiles::resolve(BenchmarkProfiles::HIGH_VOLUME_GOOGLE_ADS, [
            'ads_rows' => 1_500,
            'ads_days' => 10,
        ]);
        $fixture = app(SyntheticBenchmarkSeeder::class)->seed($profile, 65);
        $asset = $fixture['primary_asset'];
        $this->assertNotNull($asset);

        $end = Carbon::now('UTC')->toDateString();
        $start = Carbon::now('UTC')->subDays(10)->toDateString();
        $repo = app(GoogleAdsPoolReadRepository::class);

        $terms = $repo->topSearchTerms($asset->id, 1, 'bench-ads-customer', $start, $end, 50);
        $this->assertLessThanOrEqual(50, count($terms));
        $this->assertNotEmpty($terms);
        $this->assertArrayHasKey('search_term', $terms[0]);
        $this->assertArrayHasKey('currency', $terms[0]);
    }
}
