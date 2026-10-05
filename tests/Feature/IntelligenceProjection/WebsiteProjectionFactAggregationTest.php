<?php

namespace Tests\Feature\IntelligenceProjection;

use App\Services\IntelligenceProjection\Website\WebsiteProjectionAdapterSupport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The SQL aggregation the projection adapters read facts with. On PostgreSQL the Search Console daily tables are
 * compact views whose id column is NULL, so a keyset (chunkById) read cannot work there; the aggregation orders by
 * reporting date and only uses the id to break ties, and gives the same groups on a view without ids.
 */
final class WebsiteProjectionFactAggregationTest extends TestCase
{
    use BuildsProjectionFixture;
    use RefreshDatabase;

    public function test_groups_follow_reporting_date_order_on_a_fact_view_without_row_ids(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00:00', 'UTC'));
        $this->bindWebsite();
        if (DB::getDriverName() === 'sqlite') {
            // PostgreSQL already serves gsc_page_daily as a compact view with NULL ids; build the same on SQLite.
            $columns = array_values(array_diff(Schema::getColumnListing('gsc_page_daily'), ['id']));
            DB::statement('ALTER TABLE gsc_page_daily RENAME TO gsc_page_daily_rows');
            DB::statement('CREATE VIEW gsc_page_daily AS SELECT NULL AS id, '.implode(', ', array_map(static fn (string $c): string => '"'.$c.'"', $columns)).' FROM gsc_page_daily_rows');
        }
        $insertInto = DB::getDriverName() === 'sqlite' ? 'gsc_page_daily_rows' : 'gsc_page_daily';
        $row = function (string $date, string $page, int $clicks, int $run, string $collectedAt) use ($insertInto): void {
            $this->insertFact($insertInto, [
                'digital_asset_id' => $this->site->id, 'external_resource_id' => $this->gscResource->id, 'site_url' => self::SITE_URL,
                'reporting_date' => $date, 'page' => $page, 'clicks' => $clicks, 'impressions' => $clicks * 10, 'contract_version' => 1,
                'last_collection_run_id' => null, 'last_dataset_run_id' => $this->runs[$run][1], 'first_collected_at' => $collectedAt,
                'last_collected_at' => $collectedAt, 'source_timezone' => 'America/Los_Angeles', 'record_fingerprint' => hash('sha256', $date.$page),
                'metadata' => json_encode(['provider_average_position' => 2.5]), 'created_at' => $collectedAt, 'updated_at' => $collectedAt, 'search_type' => 'web',
            ]);
        };
        // Inserted newest day first: the order must come from the reporting date, not from insertion.
        $row('2026-09-12', 'https://golden.test/b/', 3, 2, '2026-09-13 06:00:00');
        $row('2026-09-12', 'https://golden.test/a/', 5, 2, '2026-09-13 06:00:00');
        $row('2026-09-10', 'https://golden.test/a/', 1, 0, '2026-09-15 08:00:00');
        $row('2026-09-11', 'https://golden.test/b/', 2, 1, '2026-09-12 06:00:00');
        $row('2026-09-11', 'https://golden.test/a/', 4, 1, '2026-09-12 06:00:00');

        $support = app(WebsiteProjectionAdapterSupport::class);
        $facts = fn (): Builder => DB::table('gsc_page_daily')->where('external_resource_id', $this->gscResource->id)->where('site_url', self::SITE_URL);
        $dimensions = ['dimension_value' => $facts()->getGrammar()->wrap('page')];
        $groups = $support->factGroups($facts(), $dimensions, ['clicks' => $facts()->getGrammar()->wrap('clicks')], ['last_dataset_run_id']);

        $this->assertSame(['https://golden.test/a/', 'https://golden.test/b/'], array_map(static fn (object $g): string => $g->dimension_value, $groups));
        $this->assertSame([10, 5], array_map(static fn (object $g): int => (int) $g->clicks, $groups));
        $this->assertSame(['2026-09-10', '2026-09-11'], array_map(static fn (object $g): string => (string) $g->first_date, $groups));
        $this->assertSame(['2026-09-12', '2026-09-12'], array_map(static fn (object $g): string => (string) $g->latest_date, $groups));
        $this->assertSame([$this->runs[2][1], $this->runs[2][1]], array_map(static fn (object $g): int => (int) $g->last_dataset_run_id, $groups));
        // The backfilled day (collected last) holds the latest collection time, whatever its reporting date.
        $this->assertSame('2026-09-15T08:00:00+00:00', $support->latestTimestamp($groups[0]->last_collected_at));
        $this->assertNull($groups[0]->first_id);

        $runs = array_map(
            static fn (object $a): string => $a->dimension_value.'#'.(int) $a->last_dataset_run_id,
            $support->factFirstAppearances($facts(), $dimensions, ['last_dataset_run_id']),
        );
        $this->assertSame([
            'https://golden.test/a/#'.$this->runs[0][1],
            'https://golden.test/a/#'.$this->runs[1][1],
            'https://golden.test/b/#'.$this->runs[1][1],
            'https://golden.test/a/#'.$this->runs[2][1],
            'https://golden.test/b/#'.$this->runs[2][1],
        ], $runs);
    }

    public function test_a_later_fact_is_decided_by_reporting_date_then_id(): void
    {
        $support = app(WebsiteProjectionAdapterSupport::class);

        $this->assertTrue($support->isLaterFact(null, ['2026-09-10', null]));
        $this->assertTrue($support->isLaterFact(['2026-09-10', 9], ['2026-09-11', 1]));
        $this->assertTrue($support->isLaterFact(['2026-09-10', 1], ['2026-09-10', 9]));
        $this->assertFalse($support->isLaterFact(['2026-09-11', 1], ['2026-09-10', 9]));
        $this->assertFalse($support->isLaterFact(['2026-09-10', null], ['2026-09-10', null]));
    }
}
