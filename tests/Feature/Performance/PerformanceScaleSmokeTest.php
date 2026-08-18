<?php

namespace Tests\Feature\Performance;

use App\Support\Performance\BenchmarkHarness;
use App\Support\Performance\BenchmarkProfiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('performance')]
class PerformanceScaleSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_agency_20_smoke_profile_runs_without_external_calls(): void
    {
        $profile = BenchmarkProfiles::resolve(BenchmarkProfiles::AGENCY_20, [
            'gsc_rows' => 200,
            'ads_rows' => 200,
            'gsc_days' => 7,
            'ads_days' => 7,
        ]);

        $result = app(BenchmarkHarness::class)->run($profile, 65);

        $this->assertSame(20, $result['fixture']['customers']);
        $this->assertGreaterThan(0, $result['fixture']['gsc_rows_inserted']);
        $this->assertGreaterThan(0, $result['fixture']['ads_rows_inserted']);
        $this->assertArrayHasKey('environment', $result);
        $this->assertArrayHasKey('git_sha', $result['environment']);
        $this->assertArrayHasKey('gsc_top_queries', $result['measurements']);
        $this->assertArrayHasKey('ads_search_terms', $result['measurements']);
        $this->assertSame(100, $result['measurements']['task_paginate_clamp']['per_page']);
        $this->assertSame('DEFER', $result['partition_decision']['further_partitioning']);
        $this->assertSame('REJECT', $result['partition_decision']['control_plane_customer_partition']);
    }

    public function test_high_volume_gsc_profile_aggregates_without_loading_all_rows_into_php_result_set(): void
    {
        $profile = BenchmarkProfiles::resolve(BenchmarkProfiles::HIGH_VOLUME_GSC, [
            'gsc_rows' => 2_000,
            'gsc_days' => 14,
        ]);

        $result = app(BenchmarkHarness::class)->run($profile, 65);

        $this->assertSame(2_000, $result['fixture']['gsc_rows_inserted']);
        $this->assertSame(20, $result['measurements']['gsc_top_queries']['rows_returned'] ?? null);
        $this->assertLessThan(
            2_000,
            $result['measurements']['gsc_top_queries']['rows_returned'] ?? 0,
            'Top-query path must not return full cardinality',
        );
    }

    public function test_profiles_are_parameterized_not_product_limits(): void
    {
        $ids = BenchmarkProfiles::ids();
        $this->assertContains(BenchmarkProfiles::AGENCY_20, $ids);
        $this->assertContains(BenchmarkProfiles::AGENCY_100, $ids);
        $this->assertContains(BenchmarkProfiles::HIGH_VOLUME_GSC, $ids);
        $this->assertContains(BenchmarkProfiles::HIGH_VOLUME_GOOGLE_ADS, $ids);
        $this->assertContains(BenchmarkProfiles::MIXED_BACKGROUND_LOAD, $ids);

        $custom = BenchmarkProfiles::resolve(BenchmarkProfiles::AGENCY_100, ['customers' => 42]);
        $this->assertSame(42, $custom['customers']);
        $this->assertStringContainsString('portfolio', strtolower($custom['purpose']));
    }
}
