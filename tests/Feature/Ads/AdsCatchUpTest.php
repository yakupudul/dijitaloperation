<?php

namespace Tests\Feature\Ads;

use App\Jobs\Ads\RefreshAdServiceStatsJob;
use App\Jobs\CollectMetaGeoResultsJob;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Services\Ads\AdsCatchUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** The hourly catch-up: stale per-service rows, missing Meta breakdowns and today's winners snapshot. */
class AdsCatchUpTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_rows_are_rebuilt_then_missing_breakdowns_and_the_snapshot_follow(): void
    {
        Queue::fake();
        $brand = Brand::factory()->create();
        $meta = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'meta_ads']);
        DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $meta->id, 'capability' => 'meta_ads', 'status' => CoreAssetBinding::STATUS_ACTIVE]);

        $this->assertSame(['service_stats' => 2, 'breakdowns' => 0, 'snapshot' => 0], app(AdsCatchUp::class)->run(), 'never ran: rebuild first');
        Queue::assertPushed(RefreshAdServiceStatsJob::class, 2);

        $this->assertSame(['service_stats' => 0, 'breakdowns' => 1, 'snapshot' => 0], app(AdsCatchUp::class)->run(), 'fresh rows: the account without breakdowns is fetched');
        Queue::assertPushed(CollectMetaGeoResultsJob::class, 1);
        $this->assertSame(0, app(AdsCatchUp::class)->run()['breakdowns'], 'once a day per account');

        $this->travel(61)->minutes();
        app(AdsCatchUp::class)->run();
        $this->assertTrue(DB::table('ad_winner_snapshots')->doesntExist(), 'no service numbers yet, nothing to store');

        Cache::put(AdsCatchUp::RAN_AT_KEY, now()->subHours(24)->toIso8601String());
        $this->assertSame(2, app(AdsCatchUp::class)->run()['service_stats'], 'a day old: rebuilt again');
    }
}
