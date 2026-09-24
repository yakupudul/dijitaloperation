<?php

namespace Tests\Feature\Measurement;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Website\PageScorecard;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\SeoPlan;
use App\Models\SeoTask;
use App\Models\User;
use App\Services\Measurement\PageScorecardReader;
use App\Support\Roles;
use Carbon\Carbon;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class PageScorecardTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private DigitalAsset $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-23 09:00:00', 'UTC'));
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'primary_url' => 'https://atlasdis.com/', 'domain' => 'atlasdis.com']);

        // /implant: Google clicks halved, visits without conversions, ads clicks without conversions, not indexed, slow.
        $this->pool('gsc_page_daily', ['site_url' => 'sc-domain:atlasdis.com', 'reporting_date' => '2026-09-10', 'page' => 'https://atlasdis.com/implant/', 'clicks' => 20, 'impressions' => 400]);
        $this->pool('gsc_page_daily', ['site_url' => 'sc-domain:atlasdis.com', 'reporting_date' => '2026-08-10', 'page' => 'https://atlasdis.com/implant/', 'clicks' => 60, 'impressions' => 500]);
        $this->pool('ga4_landing_page_daily', ['external_resource_id' => 1, 'property_id' => '1', 'reporting_date' => '2026-09-10', 'landingPage' => '/implant/', 'sessions' => 150, 'engagedSessions' => 90, 'keyEvents' => 0]);
        $this->pool('ga4_landing_channel_daily', ['external_resource_id' => 1, 'property_id' => '1', 'reporting_date' => '2026-09-10', 'landingPage' => '/implant/', 'sessionDefaultChannelGroup' => 'Paid Search', 'sessions' => 100]);
        $this->pool('ga4_landing_channel_daily', ['external_resource_id' => 1, 'property_id' => '1', 'reporting_date' => '2026-09-10', 'landingPage' => '/implant/', 'sessionDefaultChannelGroup' => 'Organic Search', 'sessions' => 50]);
        $this->pool('google_ads_landing_page_daily', ['customer_id' => '123', 'reporting_date' => '2026-09-11', 'landing_page' => 'https://atlasdis.com/implant/?gclid=abc', 'impressions' => 900, 'clicks' => 40, 'cost_micros' => 500_000_000, 'cost_amount' => 500, 'conversions' => 0, 'currency' => 'TRY']);
        $this->pool('google_ads_landing_page_daily', ['customer_id' => '123', 'reporting_date' => '2026-09-11', 'landing_page' => 'https://baska-site.com/x', 'impressions' => 9, 'clicks' => 4, 'cost_micros' => 1, 'cost_amount' => 1, 'conversions' => 0, 'currency' => 'TRY']);
        $this->pool('gsc_url_inspection_snapshot', ['site_url' => 'sc-domain:atlasdis.com', 'page' => 'https://atlasdis.com/implant/', 'inspected_at' => now(), 'metadata' => json_encode(['verdict' => 'NEUTRAL', 'coverage_state' => 'Crawled - currently not indexed'])]);
        DB::table('website_performance_measurement')->insert($this->performanceRow());
        $plan = SeoPlan::query()->create(['brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'digital_asset_id' => $this->site->id, 'status' => 'completed', 'completed_at' => now()]);
        SeoTask::query()->create([
            'customer_id' => $this->brand->customer_id, 'brand_id' => $this->brand->id, 'digital_asset_id' => $this->site->id,
            'task_key' => 'k1', 'type' => 'fix', 'rule_id' => 'x', 'severity' => 'high', 'priority_score' => 900, 'title' => 'İmplant sayfasını dizine aldır',
            'reason' => 'r', 'evidence' => [], 'checklist' => [], 'status' => 'open', 'target_url' => 'https://atlasdis.com/implant', 'first_seen_plan_id' => $plan->id, 'last_seen_plan_id' => $plan->id,
        ]);
        // /iletisim: Google only.
        $this->pool('gsc_page_daily', ['site_url' => 'sc-domain:atlasdis.com', 'reporting_date' => '2026-09-12', 'page' => 'https://atlasdis.com/iletisim', 'clicks' => 5, 'impressions' => 50]);
    }

    public function test_one_row_per_page_joins_all_sources(): void
    {
        $card = app(PageScorecardReader::class)->read($this->site);

        $this->assertSame(2, $card['total']);
        $implant = $card['rows'][0];
        $this->assertSame('/implant/', $implant['path']);
        $this->assertSame([20, 60, 150, 0], [$implant['clicks'], $implant['clicks_prev'], $implant['sessions'], $implant['key_events']]);
        $this->assertSame(['Paid Search' => 100, 'Organic Search' => 50], $implant['channels']);
        $this->assertSame([40, 500.0, 0.0], [$implant['ads_clicks'], $implant['ads_cost'], $implant['ads_conversions']], 'tracking parameters stripped, other hosts ignored');
        $this->assertFalse($implant['indexed']);
        $this->assertSame(5200, $implant['lcp_ms']);
        $this->assertSame(1, $implant['task_count']);
        $this->assertEqualsCanonicalizing(['Google dizininde değil', 'Google tıkları düşüyor', 'Ziyaret var, dönüşüm yok', 'Reklam tıklıyor, dönüşmüyor', 'Yavaş açılıyor'], $implant['flags']);

        $contact = $card['rows'][1];
        $this->assertNull($contact['sessions'], 'no GA4 row = unknown, not zero');
        $this->assertNull($contact['indexed']);
        $this->assertSame([], $contact['flags']);
    }

    public function test_tab_renders_and_searches(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        Livewire::test(PageScorecard::class, ['websiteId' => $this->site->id])
            ->assertSee('Sayfa Karnesi (son 28 gün)')
            ->assertSee('/implant/')
            ->assertSee('Google Ads %67')
            ->set('search', 'iletisim')
            ->assertSee('/iletisim')
            ->assertDontSee('/implant/');

        $this->get(route('operator.website', ['assetId' => $this->site->id, 'tab' => 'scorecard']))->assertOk()->assertSee('Sayfa Karnesi');
    }

    /** @return array<string, mixed> */
    private function performanceRow(): array
    {
        $columns = Schema::getColumnListing('website_performance_measurement');
        $row = ['digital_asset_id' => $this->site->id, 'url' => 'https://atlasdis.com/implant/', 'strategy' => 'mobile', 'observed_at' => now(), 'metadata' => json_encode(['lcp_ms' => 5200])];
        foreach (['contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20)] as $column => $value) {
            if (in_array($column, $columns, true)) {
                $row[$column] = $value;
            }
        }

        return $row;
    }

    /** @param  array<string, mixed>  $values */
    private function pool(string $table, array $values): void
    {
        DB::table($table)->insert($values + [
            'digital_asset_id' => $this->site->id, 'contract_version' => 1, 'first_collected_at' => now(),
            'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
        ]);
    }
}
