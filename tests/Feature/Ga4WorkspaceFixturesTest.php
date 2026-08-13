<?php

namespace Tests\Feature;

use App\Support\Demo\DemoCatalog;
use App\Support\Demo\Ga4WorkspaceFixtures;
use Tests\TestCase;

class Ga4WorkspaceFixturesTest extends TestCase
{
    public function test_last_28_baselines_and_identity_are_deterministic(): void
    {
        $a = Ga4WorkspaceFixtures::workspace('last_28');
        $b = Ga4WorkspaceFixtures::workspace('last_28');

        $this->assertSame($a, $b);
        $this->assertSame(18420, $a['glance']['users']['raw']);
        $this->assertSame(24860, $a['glance']['sessions']['raw']);
        $this->assertSame(684, $a['glance']['business_actions']['raw']);

        $identity = $a['identity'];
        $this->assertSame('Google Analytics', $identity['eyebrow']);
        $this->assertSame('Atlas Dental — GA4', $identity['title']);
        $this->assertSame(DemoCatalog::BRAND_ID, $identity['brand_id']);
        $this->assertSame(DemoCatalog::WEBSITE_ASSET_ID, $identity['website_asset_id']);
        $this->assertSame(DemoCatalog::GOOGLE_ADS_ASSET_ID, $identity['google_ads_asset_id']);
        $this->assertSame(DemoCatalog::META_ASSET_ID, $identity['meta_asset_id']);
        $this->assertSame(DemoCatalog::GA4_ASSET_ID, $identity['ga4_asset_id']);
        $this->assertSame('Measures · Atlas Dental Website', $identity['relationship_line']);
        $this->assertSame('Connected', $identity['status']);
        $this->assertSame('Data through Aug 12', $identity['freshness']);
        $this->assertSame('123456789', $identity['property_id']);
        $this->assertSame('G-DEMOATLAS', $identity['measurement_id']);
    }

    public function test_measurement_stories_use_honest_missing_states(): void
    {
        $workspace = Ga4WorkspaceFixtures::workspace('last_28');
        $states = array_column($workspace['business_actions'], 'state', 'action');

        $this->assertSame('Healthy', $states['Lead Form']);
        $this->assertSame('Not mapped', $states['WhatsApp']);
        $this->assertSame('Review', $states['Phone']);
        $this->assertSame('Not mapped', $states['Appointment']);

        $appointment = collect($workspace['business_actions'])->firstWhere('action', 'Appointment');
        $this->assertNull($appointment['event_count']);
        $this->assertNull($appointment['ga4_event']);

        $this->assertSame('6% → 18%', $workspace['measurement']['utm_hygiene']['trend']);
        $this->assertSame('ga4-f-lead-interruption', $workspace['measurement']['interruptions'][0]['finding_id']);
        $this->assertSame('Improvement observed', $workspace['operations']['outcomes'][0]['state']);
        $this->assertStringContainsString('Self-referral', $workspace['measurement']['referrals'][0]['state']);

        $paths = array_column($workspace['landing_pulse'], 'path');
        $this->assertSame(['/implant', '/post-bariatric', '/', '/contact', '/team'], $paths);
        $this->assertLessThanOrEqual(5, count($workspace['needs_attention']));
        $this->assertStringNotContainsStringIgnoringCase('plastic', json_encode($workspace, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('health_score', $workspace);
        $this->assertArrayNotHasKey('score', $workspace['glance']);
    }

    public function test_custom_range_aggregates_from_daily_series(): void
    {
        $custom = Ga4WorkspaceFixtures::workspace('custom', '2026-08-01', '2026-08-10');
        $again = Ga4WorkspaceFixtures::workspace('custom', '2026-08-01', '2026-08-10');

        $this->assertSame($custom, $again);
        $this->assertSame(10, $custom['period_days']);
        $this->assertSame('2026-08-01', $custom['period_start']);
        $this->assertSame('2026-08-10', $custom['period_end']);
        $this->assertGreaterThan(0, $custom['glance']['sessions']['raw']);
        $this->assertLessThan(24860, $custom['glance']['sessions']['raw']);
        $this->assertNotEmpty($custom['performance_trend']['sessions']);
        $this->assertSame(
            count($custom['performance_trend']['labels']),
            count($custom['performance_trend']['business_actions']),
        );
    }
}
