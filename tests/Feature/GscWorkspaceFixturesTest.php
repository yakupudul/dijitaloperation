<?php

namespace Tests\Feature;

use App\Support\Demo\DemoCatalog;
use App\Support\Demo\GscWorkspaceFixtures;
use Tests\TestCase;

class GscWorkspaceFixturesTest extends TestCase
{
    public function test_last_28_baselines_and_identity_are_deterministic(): void
    {
        $a = GscWorkspaceFixtures::workspace('last_28');
        $b = GscWorkspaceFixtures::workspace('last_28');

        $this->assertSame($a, $b);
        $this->assertSame(18420, $a['glance']['clicks']['raw']);
        $this->assertSame(842000, $a['glance']['impressions']['raw']);

        $identity = $a['identity'];
        $this->assertSame('Google Search Console', $identity['eyebrow']);
        $this->assertSame('Atlas Dental — Search Console', $identity['title']);
        $this->assertSame(DemoCatalog::BRAND_ID, $identity['brand_id']);
        $this->assertSame(DemoCatalog::WEBSITE_ASSET_ID, $identity['website_asset_id']);
        $this->assertSame(DemoCatalog::GSC_ASSET_ID, $identity['gsc_asset_id']);
        $this->assertSame('Observes · Atlas Dental Website', $identity['relationship_line']);
        $this->assertSame('sc-domain:atlasdental.example', $identity['property_label']);
        $this->assertSame('Domain property', $identity['property_type']);
        $this->assertSame('Connected', $identity['status']);
    }

    public function test_demand_ownership_indexing_and_no_fake_scores(): void
    {
        $workspace = GscWorkspaceFixtures::workspace('last_28');

        $this->assertCount(6, $workspace['demand']['clusters']);
        $this->assertNotEmpty($workspace['demand']['queries']);
        $this->assertArrayHasKey('growing', $workspace['demand']['momentum']);
        $ownership = $workspace['demand']['ownership_reviews'][0];
        $this->assertSame('/implant', $ownership['intended_page']);
        $this->assertStringContainsString('fragmented', strtolower($ownership['state']));
        $this->assertStringContainsString('candidate', strtolower($ownership['language']));

        $paths = array_column($workspace['pages']['directory'], 'path');
        $this->assertContains('/implant', $paths);
        $this->assertContains('/post-bariatric', $paths);
        $this->assertLessThanOrEqual(5, count($workspace['needs_attention']));

        $implantUrl = collect($workspace['indexing']['urls'])->firstWhere('path', '/implant');
        $this->assertSame('Mismatch', $implantUrl['canonical']);
        $this->assertStringContainsString('not a Live URL test', $workspace['indexing']['inspection_note']);

        $json = json_encode($workspace, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsStringIgnoringCase('SEO Score', $json);
        $this->assertStringNotContainsStringIgnoringCase('plastic', $json);
        $this->assertStringContainsString('No Force Index', $workspace['indexing']['inspection_note']);
        $this->assertArrayNotHasKey('health_score', $workspace);
        $this->assertSame('Improvement observed', $workspace['operations']['outcomes'][0]['state']);
        $this->assertSame('Still observed', $workspace['operations']['outcomes'][2]['state']);
    }

    public function test_custom_range_aggregates_from_daily_series(): void
    {
        $custom = GscWorkspaceFixtures::workspace('custom', '2026-08-01', '2026-08-10');
        $again = GscWorkspaceFixtures::workspace('custom', '2026-08-01', '2026-08-10');

        $this->assertSame($custom, $again);
        $this->assertSame(10, $custom['period_days']);
        $this->assertGreaterThan(0, $custom['glance']['clicks']['raw']);
        $this->assertLessThan(18420, $custom['glance']['clicks']['raw']);
        $this->assertNotEmpty($custom['performance_trend']['clicks'] ?? GscWorkspaceFixtures::metricSeries($custom['period_start'], $custom['period_end'])['clicks']);
    }
}
