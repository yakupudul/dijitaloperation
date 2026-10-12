<?php

namespace Tests\Feature\Meta;

use App\Services\Meta\MetaChecks;
use App\Services\Meta\MetaScreen;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\SeedsMetaAccount;
use Tests\TestCase;

/**
 * yakup, 2026-10-11 Meta rule fixes: the pixel is judged against the snapshot's own date, account totals do not add
 * leads, messages and purchases into one cost per result, UTM in the ad's URL parameters counts, and under ad-set
 * budgets (ABO) an ad's fair share is that of its own ad set.
 */
final class MetaRuleFixesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMetaAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        Http::preventStrayRequests();
        $this->seedMetaAccount();
    }

    public function test_the_pixel_is_judged_against_the_date_of_its_snapshot(): void
    {
        $account = app(MetaScreen::class)->account($this->asset);
        $this->assertSame('silent', app(MetaScreen::class)->pixel($account)['state'], 'fired 15 days before today\'s snapshot');

        DB::table('meta_conversion_source_snapshot')->update(['last_collected_at' => CarbonImmutable::parse('2026-10-16 08:00:00')]);

        $pixel = app(MetaScreen::class)->pixel($account);
        $this->assertSame('ok', $pixel['state'], 'the week-old snapshot saw an event the day before');
        $this->assertSame('2026-10-16', $pixel['snapshot_date']);
    }

    public function test_totals_use_the_dominant_result_type_for_the_cost_per_result(): void
    {
        $totals = MetaScreen::totals([
            ['spend' => 300.0, 'impressions' => 1000, 'clicks' => 10, 'leads' => 10.0, 'messages' => 0.0, 'purchases' => 0.0, 'results' => 10.0],
            ['spend' => 300.0, 'impressions' => 1000, 'clicks' => 10, 'leads' => 0.0, 'messages' => 30.0, 'purchases' => 0.0, 'results' => 30.0],
        ]);

        $this->assertSame('messages', $totals['result_type']);
        $this->assertSame(30.0, $totals['results']);
        $this->assertSame(40.0, $totals['all_results']);
        $this->assertSame(20.0, $totals['cpr'], 'spend / messages, not spend / (leads + messages)');
    }

    public function test_utm_in_the_ad_url_parameters_counts(): void
    {
        $this->assertSame('issue', $this->check('utm'));

        DB::table('meta_creative_snapshot')->where('creative_id', 'cr2')->update(['metadata' => json_encode([
            'name' => 'Trafik görseli', 'title' => 'Kampanya', 'body' => 'Sitemizi ziyaret edin.', 'link_url' => 'https://baska.test/kampanya',
            'url_tags' => 'utm_source=facebook&utm_medium=paid_social',
        ])]);

        $this->assertSame('ok', $this->check('utm'));
    }

    public function test_under_ad_set_budgets_an_ad_only_competes_within_its_ad_set(): void
    {
        $this->snapshot('meta_campaign_snapshot', ['campaign_id' => 'c3'], ['name' => 'ABO test', 'objective' => 'OUTCOME_LEADS', 'effective_status' => 'ACTIVE']);
        $this->snapshot('meta_adset_snapshot', ['adset_id' => 'as3'], ['name' => 'Set A', 'campaign_id' => 'c3', 'effective_status' => 'ACTIVE', 'daily_budget' => '100']);
        $this->snapshot('meta_adset_snapshot', ['adset_id' => 'as4'], ['name' => 'Set B', 'campaign_id' => 'c3', 'effective_status' => 'ACTIVE', 'daily_budget' => '5']);
        foreach (['ad3' => 'as3', 'ad4' => 'as4'] as $ad => $set) {
            $this->professional('meta_ad_snapshot', ['ad_id' => $ad, 'ad_name' => 'Reklam '.$ad, 'campaign_id' => 'c3', 'adset_id' => $set, 'creative_id' => 'cr1', 'effective_status' => 'ACTIVE']);
        }
        $start = CarbonImmutable::parse('2026-10-02');
        for ($i = 0; $i < 28; $i++) {
            $date = $start->addDays($i)->toDateString();
            $this->daily('ad3', 'c3', 'as3', $date, 100, 2000, 20, 1000);
            $this->daily('ad4', 'c3', 'as4', $date, 5, 100, 1, 80);
        }

        $this->assertSame('no_data', $this->check('starved'), 'ABO: each ad set has one ad, nothing to compare');

        DB::table('meta_campaign_snapshot')->where('campaign_id', 'c3')->update(['metadata' => json_encode(['name' => 'ABO test', 'objective' => 'OUTCOME_LEADS',
            'effective_status' => 'ACTIVE', 'daily_budget' => '105'])]);

        $this->assertSame('issue', $this->check('starved'), 'CBO: the small ad gets far under its share of the campaign budget');
    }

    private function check(string $id): string
    {
        return (string) collect(app(MetaChecks::class)->sync($this->asset))->firstWhere('id', $id)['state'];
    }
}
