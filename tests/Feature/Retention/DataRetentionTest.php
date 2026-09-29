<?php

namespace Tests\Feature\Retention;

use App\Models\CoreExternalResource;
use App\Services\Retention\DataRetentionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\InsertsFacts;
use Tests\TestCase;

final class DataRetentionTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    public function test_old_daily_performance_rolls_into_monthly_rows_and_query_facts_are_not_rolled(): void
    {
        $old = CarbonImmutable::now()->startOfMonth()->subMonths(30);
        $recent = CarbonImmutable::now()->subDays(3);
        $this->adsDay($old->addDays(1), impressions: 100, clicks: 10, share: 0.5);
        $this->adsDay($old->addDays(2), impressions: 300, clicks: 20, share: 0.9);
        $this->adsDay($recent, impressions: 50, clicks: 5, share: 0.1);
        $this->gscQueryPageDay($old->addDays(1));

        $result = app(DataRetentionService::class)->rollupDailyPerformance();

        $this->assertSame(2, $result['rolled_rows']);
        $this->assertSame(1, DB::table('google_ads_campaign_daily')->count(), 'recent daily rows stay');
        $rollup = DB::table('performance_monthly_rollups')->where('source_table', 'google_ads_campaign_daily')->sole();
        $metrics = json_decode($rollup->metrics, true);
        $dimensions = json_decode($rollup->dimensions, true);
        $this->assertSame($old->toDateString(), CarbonImmutable::parse($rollup->month)->toDateString());
        $this->assertSame(400, $metrics['impressions']);
        $this->assertSame(30, $metrics['clicks']);
        $this->assertEqualsWithDelta(0.8, $metrics['search_impression_share'], 0.0001, 'ratios are impression-weighted');
        $this->assertSame('cmp-1', $dimensions['campaign_id']);
        $this->assertSame(2, (int) $rollup->day_count);
        $this->assertSame(1, DB::table('gsc_query_page_daily')->count(), 'query facts are not rolled generically (query_sources is their monthly form)');
        $this->assertNotContains('gsc_query_page_daily', app(DataRetentionService::class)->dailyPerformanceTables());

        $again = app(DataRetentionService::class)->rollupDailyPerformance();
        $this->assertSame(0, $again['rolled_rows'], 'second run is a no-op');
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->adsDay(CarbonImmutable::now()->startOfMonth()->subMonths(30), impressions: 10, clicks: 1, share: 0.2);
        DB::table('provider_api_counters')->insert(['provider' => 'google', 'operation' => 'x', 'window_started_at' => now()->subDays(40)]);

        $result = app(DataRetentionService::class)->run(dryRun: true);

        $this->assertSame(1, $result['rolled_rows']);
        $this->assertSame(1, $result['telemetry']['provider_api_counters']);
        $this->assertSame(1, DB::table('google_ads_campaign_daily')->count());
        $this->assertSame(1, DB::table('provider_api_counters')->count());
        $this->assertSame(0, DB::table('performance_monthly_rollups')->count());
    }

    public function test_telemetry_is_trimmed_to_its_window(): void
    {
        DB::table('provider_api_counters')->insert([
            ['provider' => 'google', 'operation' => 'old', 'window_started_at' => now()->subDays(40)],
            ['provider' => 'google', 'operation' => 'new', 'window_started_at' => now()->subDays(2)],
        ]);

        app(DataRetentionService::class)->purgeTelemetry();

        $this->assertSame(['new'], DB::table('provider_api_counters')->pluck('operation')->all());
    }

    public function test_old_raw_payloads_are_deleted_but_each_pages_latest_html_is_kept(): void
    {
        Storage::fake('raw-test');
        $old = now()->subDays(120);
        $orphan = $this->rawObject('orphan.json.gz', $old);
        $latestHtml = $this->rawObject('page-latest.html.gz', $old);
        $olderHtml = $this->rawObject('page-older.html.gz', $old->copy()->subDays(10));
        $recent = $this->rawObject('recent.json.gz', now()->subDays(5));
        $this->htmlSnapshot($olderHtml, $old->copy()->subDays(10));
        $this->htmlSnapshot($latestHtml, $old);

        $deleted = app(DataRetentionService::class)->purgeRawPayloads();

        $this->assertSame(2, $deleted);
        $this->assertEqualsCanonicalizing([$latestHtml, $recent], DB::table('raw_ingestion_objects')->pluck('id')->all());
        Storage::disk('raw-test')->assertMissing('orphan.json.gz');
        Storage::disk('raw-test')->assertMissing('page-older.html.gz');
        Storage::disk('raw-test')->assertExists('page-latest.html.gz');
        $this->assertNull(DB::table('website_html_snapshot')->where('observed_at', $old->copy()->subDays(10))->value('raw_ingestion_object_id'));
    }

    public function test_command_is_a_dry_run_unless_apply(): void
    {
        $this->adsDay(CarbonImmutable::now()->startOfMonth()->subMonths(17), impressions: 10, clicks: 1, share: 0.2);

        $this->artisan('moxdop:retention')
            ->expectsOutputToContain('[deneme]')
            ->expectsOutputToContain('moxdop:retention --apply')
            ->assertSuccessful();
        $this->assertSame(1, DB::table('google_ads_campaign_daily')->count());

        $this->artisan('moxdop:retention', ['--apply' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('google_ads_campaign_daily')->count());
        $this->assertSame(1, DB::table('performance_monthly_rollups')->count());
        $this->assertStringContainsString("Schedule::command('moxdop:retention--apply')->monthlyOn(", (string) preg_replace('/\s+/', '', (string) file_get_contents(base_path('routes/console.php'))));
    }

    public function test_daily_facts_keep_sixteen_months_and_query_sources_twenty_four(): void
    {
        $retention = app(DataRetentionService::class);
        $cutoff = $retention->dailyCutoff();
        $this->assertSame(CarbonImmutable::now()->startOfMonth()->subMonths(16)->toDateString(), $cutoff->toDateString());
        $this->adsDay($cutoff->subDay(), impressions: 10, clicks: 1, share: 0.2);
        $this->adsDay($cutoff, impressions: 20, clicks: 2, share: 0.3);
        $this->gscQueryPageDay($cutoff->subDay());
        $this->gscQueryPageDay($cutoff);
        $resource = CoreExternalResource::factory()->searchConsole()->create();
        foreach ([25, 23] as $monthsAgo) {
            DB::table('query_sources')->insert(['external_resource_id' => $resource->id, 'source' => 'gsc', 'raw_query' => 'implant '.$monthsAgo,
                'month' => CarbonImmutable::now()->startOfMonth()->subMonths($monthsAgo)->toDateString(), 'impressions' => 1, 'clicks' => 0]);
        }

        $dry = $retention->run(dryRun: true);
        $this->assertSame(1, $dry['rolled_rows']);
        $this->assertSame(['gsc_query_page_daily' => 1, 'google_ads_search_term_daily' => 0], $dry['query_daily_rows']);
        $this->assertSame(1, $dry['query_source_rows']);
        $this->assertSame(2, DB::table('gsc_query_page_daily')->count(), 'dry run deletes nothing');

        $applied = $retention->run();
        $this->assertSame(1, $applied['query_source_rows']);
        $this->assertSame([$cutoff->toDateString()], DB::table('google_ads_campaign_daily')->pluck('reporting_date')->map(fn ($d): string => substr((string) $d, 0, 10))->all());
        $this->assertSame([$cutoff->toDateString()], DB::table('gsc_query_page_daily')->pluck('reporting_date')->map(fn ($d): string => substr((string) $d, 0, 10))->all());
        $this->assertSame(['implant 23'], DB::table('query_sources')->pluck('raw_query')->all());
    }

    private function adsDay(CarbonImmutable $day, int $impressions, int $clicks, float $share): void
    {
        DB::table('google_ads_campaign_daily')->insert([
            'digital_asset_id' => 1, 'external_resource_id' => 1, 'customer_id' => '123', 'reporting_date' => $day->toDateString(),
            'campaign_id' => 'cmp-1', 'impressions' => $impressions, 'clicks' => $clicks, 'cost_micros' => $clicks * 1_000_000,
            'conversions' => 1, 'search_impression_share' => $share, 'cost_amount' => $clicks, 'currency' => 'TRY',
            'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
        ]);
    }

    private function gscQueryPageDay(CarbonImmutable $day): void
    {
        $this->insertFacts('gsc_query_page_daily', [
            'digital_asset_id' => 1, 'site_url' => 'sc-domain:example.test', 'reporting_date' => $day->toDateString(), 'query' => 'diş implant', 'page' => 'https://example.test/',
            'clicks' => 3, 'impressions' => 40, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => Str::random(20),
        ]);
    }

    private function rawObject(string $key, \DateTimeInterface $capturedAt): int
    {
        Storage::disk('raw-test')->put($key, 'x');

        return DB::table('raw_ingestion_objects')->insertGetId([
            'uuid' => (string) Str::uuid(), 'dataset_id' => 'd', 'batch_key' => $key, 'provider_or_source' => 'test',
            'storage_disk' => 'raw-test', 'object_key' => $key, 'byte_size' => 1, 'sha256' => str_repeat('a', 64), 'captured_at' => $capturedAt,
        ]);
    }

    private function htmlSnapshot(int $rawId, \DateTimeInterface $observedAt): void
    {
        DB::table('website_html_snapshot')->insert([
            'digital_asset_id' => 1, 'url' => 'https://example.test/', 'html_hash' => Str::random(64), 'change_state' => 'changed',
            'html_bytes' => 1, 'raw_ingestion_object_id' => $rawId, 'observed_at' => $observedAt, 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
        ]);
    }
}
