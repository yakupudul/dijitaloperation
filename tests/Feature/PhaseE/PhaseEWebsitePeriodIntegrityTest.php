<?php

namespace Tests\Feature\PhaseE;

use App\Contracts\WebsiteOperatorWorkspace;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MoxDop\Website\Workspace\WebsiteWorkspaceData;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesCanonicalPortfolio;
use Tests\TestCase;

class PhaseEWebsitePeriodIntegrityTest extends TestCase
{
    use CreatesCanonicalPortfolio;
    use RefreshDatabase;

    private DigitalAsset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create(['locale' => 'en']);
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);
        $this->travelTo('2026-08-20 10:00:00');
        $this->asset = $this->createPortfolioAsset('website', 'Period Integrity Website');
        $this->seedPeriodEvidence();
    }

    #[Test]
    public function non_overlapping_selected_period_does_not_leak_stale_detail_rows(): void
    {
        $data = app(WebsiteWorkspaceData::class)->for($this->asset, '2026-08-14', '2026-08-20');

        $this->assertSame([], $data['queries']);
        $this->assertSame([], $data['pages']);
        $this->assertSame([], $data['landing_pages']);
        $this->assertSame([], $data['acquisition']);
        $this->assertFalse($data['period_has_data']);
        $this->assertSame([], $data['kpis']);
        $this->assertStringNotContainsString('stale-query-implant', json_encode($data));
        $this->assertStringNotContainsString('https://stale.example/page', json_encode($data));
        $this->assertStringNotContainsString('/stale-landing', json_encode($data));
        $this->assertStringNotContainsString('Stale Organic Search', json_encode($data));
    }

    #[Test]
    public function overlapping_custom_range_keeps_requested_period_aggregate_rows(): void
    {
        $data = app(WebsiteOperatorWorkspace::class)->overview($this->asset, '2026-08-01', '2026-08-10');

        $this->assertSame('stale-query-implant', $data['queries'][0]['query'] ?? null);
        $this->assertSame('https://stale.example/page', $data['pages'][0]['page'] ?? null);
        $this->assertSame('/stale-landing', $data['landing_pages'][0]['landingPage'] ?? null);
        $this->assertSame('Stale Organic Search', $data['acquisition'][0]['sessionDefaultChannelGroup'] ?? null);
        $this->assertSame(['2026-08-01', '2026-08-06'], array_column($data['queries'], 'date'));
        $this->assertTrue($data['period_has_data']);
        $clicks = collect($data['kpis'])->firstWhere('label', 'Organic clicks');
        $this->assertSame('4,242', $clicks['value'] ?? null);
    }

    #[Test]
    public function overlapping_wider_preset_keeps_aggregates_and_bounds_dated_rows(): void
    {
        $data = app(WebsiteWorkspaceData::class)->for($this->asset, '2026-07-24', '2026-08-20');

        $this->assertSame('stale-query-implant', $data['queries'][0]['query'] ?? null);
        $this->assertSame('https://stale.example/page', $data['pages'][0]['page'] ?? null);
        $this->assertSame('/stale-landing', $data['landing_pages'][0]['landingPage'] ?? null);
        $this->assertSame('Stale Organic Search', $data['acquisition'][0]['sessionDefaultChannelGroup'] ?? null);
        $this->assertSame(['2026-08-01', '2026-08-06'], array_column($data['queries'], 'date'));
    }

    #[Test]
    public function dated_rows_outside_custom_range_are_missing_not_zero(): void
    {
        $data = app(WebsiteWorkspaceData::class)->for($this->asset, '2026-08-05', '2026-08-08');

        $this->assertSame([
            [
                'query' => 'stale-query-implant',
                'clicks' => 88,
                'date' => '2026-08-06',
            ],
        ], $data['queries']);
        $this->assertNotSame(0, $data['queries'][0]['clicks'] ?? 0);
        $this->assertSame([], array_column(
            array_filter($data['queries'], fn (array $row): bool => ($row['date'] ?? null) === '2026-08-01'),
            'clicks'
        ));
    }

    #[Test]
    public function omitted_service_period_keeps_latest_evidence_without_inventing_zeros(): void
    {
        $data = app(WebsiteWorkspaceData::class)->for($this->asset);

        $this->assertNotEmpty($data['queries']);
        $this->assertNotEmpty($data['pages']);
        $this->assertNotEmpty($data['landing_pages']);
        $this->assertNotEmpty($data['acquisition']);
        $this->assertNotSame(0, $data['queries'][0]['clicks'] ?? 0);

        $empty = DigitalAsset::factory()->create([
            'brand_id' => $this->asset->brand_id,
            'type' => 'website',
            'name' => 'No Evidence Website',
        ]);
        $missing = app(WebsiteWorkspaceData::class)->for($empty, '2026-08-01', '2026-08-10');
        $this->assertSame([], $missing['queries']);
        $this->assertSame([], $missing['pages']);
        $this->assertSame([], $missing['landing_pages']);
        $this->assertSame([], $missing['acquisition']);
        $this->assertSame([], $missing['kpis']);
    }

    private function seedPeriodEvidence(): void
    {
        $run = Run::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'module_id' => 'website',
            'status' => 'completed',
            'finished_at' => now(),
        ]);
        $period = ['start' => '2026-08-01', 'end' => '2026-08-10'];
        $base = [
            'run_id' => $run->id,
            'digital_asset_id' => $this->asset->id,
            'source_module' => 'website',
            'observed_at' => now(),
        ];

        Evidence::factory()->create([
            ...$base,
            'type' => 'gsc_performance_summary',
            'payload' => [
                'response_ok' => true,
                'requested_period' => $period,
                'current' => ['clicks' => 4242, 'impressions' => 9000, 'ctr' => 0.1, 'position' => 4.2],
                'previous' => ['clicks' => 4000, 'impressions' => 8000, 'ctr' => 0.1, 'position' => 4.4],
                'deltas' => ['clicks' => ['percent' => 0.06]],
            ],
        ]);
        Evidence::factory()->create([
            ...$base,
            'type' => 'gsc_query_performance',
            'payload' => [
                'response_ok' => true,
                'requested_period' => $period,
                'rows' => [
                    ['query' => 'stale-query-implant', 'clicks' => 12, 'date' => '2026-08-01'],
                    ['query' => 'stale-query-implant', 'clicks' => 88, 'date' => '2026-08-06'],
                ],
            ],
        ]);
        Evidence::factory()->create([
            ...$base,
            'type' => 'gsc_page_performance',
            'payload' => [
                'response_ok' => true,
                'requested_period' => $period,
                'rows' => [
                    ['page' => 'https://stale.example/page', 'clicks' => 21],
                ],
            ],
        ]);
        Evidence::factory()->create([
            ...$base,
            'type' => 'ga4_landing_page_performance',
            'payload' => [
                'response_ok' => true,
                'requested_period' => $period,
                'rows' => [
                    ['landingPage' => '/stale-landing', 'sessions' => 40],
                ],
            ],
        ]);
        Evidence::factory()->create([
            ...$base,
            'type' => 'ga4_acquisition_summary',
            'payload' => [
                'response_ok' => true,
                'requested_period' => $period,
                'rows' => [
                    ['sessionDefaultChannelGroup' => 'Stale Organic Search', 'sessions' => 55, 'totalUsers' => 40],
                ],
            ],
        ]);
    }
}
