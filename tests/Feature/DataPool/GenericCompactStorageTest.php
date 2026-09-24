<?php

namespace Tests\Feature\DataPool;

use App\Services\DataPool\Compact\CompactFactStore;
use App\Services\DataPool\PartitionManager;
use App\Services\Retention\DataRetentionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Generic compact storage (GA4 / Meta / Google Ads daily facts). PostgreSQL only; skipped on SQLite.
 */
class GenericCompactStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Compact storage is PostgreSQL only.');
        }
    }

    public function test_search_term_table_is_converted_and_keeps_rows_and_writes(): void
    {
        app(PartitionManager::class)->ensureRange('google_ads_search_term_daily', '2026-09-01', '2026-09-30');
        DB::table('google_ads_search_term_daily')->insert([
            $this->searchTerm('diş beyazlatma', '2026-09-10', 12, '45.500000', ['campaign_ids' => ['1']]),
            $this->searchTerm('implant fiyat', '2026-09-10', 3, '9.000000', null),
            $this->searchTerm('diş beyazlatma', '2026-09-11', 7, '20.000000', ['campaign_ids' => ['1']], resource: null),
        ]);

        $this->artisan('moxdop:db:compact', ['--execute' => true, '--table' => 'google_ads_search_term_daily', '--reserve-gb' => 0])->assertSuccessful();

        $this->assertSame('v', DB::selectOne("select relkind from pg_class where relname = 'google_ads_search_term_daily'")->relkind);
        $this->assertTrue(Schema::hasTable('google_ads_search_term_daily'), 'hasTable must see the compact view');
        $this->assertNull(DB::selectOne("select 1 as x from pg_class where relname = 'google_ads_search_term_daily__legacy'"));
        $this->assertSame(3, DB::table('google_ads_search_term_daily')->count());

        $row = DB::table('google_ads_search_term_daily')->where('search_term', 'diş beyazlatma')->where('reporting_date', '2026-09-10')->first();
        $this->assertSame(12, (int) $row->clicks);
        $this->assertSame('45.500000', (string) $row->cost_amount);
        $this->assertSame('TRY', $row->currency);
        $this->assertSame(['campaign_ids' => ['1']], json_decode((string) $row->metadata, true));
        $this->assertSame(77, (int) $row->external_resource_id);
        $this->assertNotNull($row->id);
        $this->assertNull(DB::table('google_ads_search_term_daily')->where('reporting_date', '2026-09-11')->value('external_resource_id'));

        // Writer path: one update of an existing key, one new key.
        $stats = app(CompactFactStore::class)->upsert('google_ads_search_term_daily', [
            $this->searchTerm('implant fiyat', '2026-09-10', 5, '15.000000', null),
            $this->searchTerm('kanal tedavisi', '2026-09-12', 2, '4.250000', ['campaign_ids' => ['2']]),
        ]);
        $this->assertSame(['inserted' => 1, 'updated' => 1, 'unchanged' => 0], $stats);
        $this->assertSame(4, DB::table('google_ads_search_term_daily')->count());
        $this->assertSame(5, (int) DB::table('google_ads_search_term_daily')->where('search_term', 'implant fiyat')->value('clicks'));
        $this->assertSame('4.250000', (string) DB::table('google_ads_search_term_daily')->where('search_term', 'kanal tedavisi')->value('cost_amount'));
        $this->assertGreaterThan((int) $row->id, (int) DB::table('google_ads_search_term_daily')->where('search_term', 'kanal tedavisi')->value('id'));
    }

    public function test_retention_rolls_up_and_deletes_old_rows_of_a_compact_table(): void
    {
        $old = CarbonImmutable::now()->startOfMonth()->subMonths(30)->addDays(3)->toDateString();
        app(PartitionManager::class)->ensureRange('meta_ad_daily', $old, $old);
        DB::table('meta_ad_daily')->insert([$this->metaAd('ad-1', $old, '12.500000'), $this->metaAd('ad-2', $old, '7.500000')]);
        $this->artisan('moxdop:db:compact', ['--execute' => true, '--table' => 'meta_ad_daily', '--reserve-gb' => 0])->assertSuccessful();
        $this->assertSame(2, DB::table('meta_ad_daily')->count());

        $this->assertContains('meta_ad_daily', app(DataRetentionService::class)->dailyPerformanceTables());
        $result = app(DataRetentionService::class)->rollupMonth('meta_ad_daily', CarbonImmutable::parse($old)->startOfMonth());

        $this->assertSame(2, $result['rolled_rows']);
        $this->assertSame(0, DB::table('meta_ad_daily')->count());
        $this->assertSame(2, DB::table('performance_monthly_rollups')->where('source_table', 'meta_ad_daily')->count());
    }

    /** @param array<string, mixed>|null $metadata @return array<string, mixed> */
    private function searchTerm(string $term, string $date, int $clicks, string $cost, ?array $metadata, ?int $resource = 77): array
    {
        return [
            'digital_asset_id' => null, 'external_resource_id' => $resource, 'customer_id' => '1234567890', 'reporting_date' => $date,
            'search_term' => $term, 'impressions' => $clicks * 10, 'clicks' => $clicks, 'cost_micros' => (int) round((float) $cost * 1e6),
            'conversions' => 0, 'cost_amount' => $cost, 'currency' => 'TRY', 'contract_version' => 1, 'last_collection_run_id' => null,
            'last_dataset_run_id' => 9, 'first_collected_at' => now(), 'last_collected_at' => now(), 'source_timezone' => 'Europe/Istanbul',
            'record_fingerprint' => str_repeat('a', 64), 'metadata' => $metadata === null ? null : json_encode($metadata),
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    /** @return array<string, mixed> */
    private function metaAd(string $ad, string $date, string $spend): array
    {
        return [
            'digital_asset_id' => 5, 'external_resource_id' => 8, 'account_id' => 'act_1', 'reporting_date' => $date, 'ad_id' => $ad,
            'spend' => $spend, 'impressions' => 100, 'clicks' => 4, 'reach' => 80, 'currency' => 'TRY', 'contract_version' => 1,
            'last_dataset_run_id' => 3, 'first_collected_at' => now(), 'last_collected_at' => now(), 'source_timezone' => 'Europe/Istanbul',
            'record_fingerprint' => str_repeat('b', 64), 'metadata' => null, 'created_at' => now(), 'updated_at' => now(),
        ];
    }
}
