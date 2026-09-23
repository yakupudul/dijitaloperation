<?php

namespace Tests\Feature\GoogleAds;

use App\Models\CoreExternalResource;
use App\Services\GoogleAds\GoogleAdsPoolReadRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Campaign search impression share must be impressions-weighted across the selected days. */
final class GoogleAdsCampaignImpressionShareTest extends TestCase
{
    use RefreshDatabase;

    private CoreExternalResource $resource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resource = CoreExternalResource::factory()->create(['external_id' => '1112223333']);
    }

    public function test_impression_share_is_weighted_by_impressions_and_ignores_missing_days(): void
    {
        $this->campaignDay('2026-09-01', 'c1', 100, 0.10);
        $this->campaignDay('2026-09-02', 'c1', 900, 0.50);
        $this->campaignDay('2026-09-03', 'c1', 5000, null);

        $row = $this->campaignRow('c1');

        // (0.10*100 + 0.50*900) / (100 + 900) = 0.46 — a plain AVG would report 0.30.
        $this->assertEqualsWithDelta(0.46, $row['search_impression_share'], 0.0001);
        $this->assertSame(6000, $row['impressions']);
    }

    public function test_impression_share_is_null_when_no_day_reports_it(): void
    {
        $this->campaignDay('2026-09-01', 'c2', 100, null);

        $this->assertNull($this->campaignRow('c2')['search_impression_share']);
    }

    public function test_zero_impression_days_fall_back_to_the_plain_average(): void
    {
        $this->campaignDay('2026-09-01', 'c3', 0, 0.20);
        $this->campaignDay('2026-09-02', 'c3', 0, 0.40);

        $this->assertEqualsWithDelta(0.30, $this->campaignRow('c3')['search_impression_share'], 0.0001);
    }

    /** @return array<string, mixed> */
    private function campaignRow(string $campaignId): array
    {
        $rows = app(GoogleAdsPoolReadRepository::class)->campaignPerformance(
            0,
            (int) $this->resource->id,
            '1112223333',
            '2026-09-01',
            '2026-09-30',
        );

        $row = collect($rows)->firstWhere('campaign_id', $campaignId);
        $this->assertIsArray($row);

        return $row;
    }

    private function campaignDay(string $date, string $campaignId, int $impressions, ?float $impressionShare): void
    {
        DB::table('google_ads_campaign_daily')->insert([
            'digital_asset_id' => null,
            'external_resource_id' => $this->resource->id,
            'customer_id' => '1112223333',
            'reporting_date' => $date,
            'campaign_id' => $campaignId,
            'impressions' => $impressions,
            'clicks' => 0,
            'cost_micros' => 0,
            'cost_amount' => 0,
            'conversions' => 0,
            'currency' => 'TRY',
            'search_impression_share' => $impressionShare,
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'Europe/Istanbul',
            'record_fingerprint' => hash('sha256', $date.$campaignId),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
