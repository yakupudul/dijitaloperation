<?php

namespace Tests\Feature\IntelligenceProjection;

use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Models\IntelligenceProjection\WebsiteSearchTermProfile;
use App\Services\IntelligenceProjection\Website\WebsiteProjectionRebuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A rebuild reads each fact table in a fixed number of statements (SQL aggregation, no OFFSET pages), resolves
 * Search Console terms and writes profiles in batches: the statement count no longer grows per day of facts, per
 * term or per profile.
 */
final class WebsiteProjectionRebuildQueryCountTest extends TestCase
{
    use BuildsProjectionFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00:00', 'UTC'));
        $this->bindWebsite();
    }

    public function test_fact_reads_do_not_grow_with_the_number_of_fact_rows(): void
    {
        $this->seedSearchConsole(pages: 12, terms: 12, days: 3, startDay: 1);
        $few = $this->countRebuildQueries();

        // The same pages and terms over 90 more days: 3 000+ more fact rows per table, more than one old OFFSET page.
        $this->seedSearchConsole(pages: 12, terms: 12, days: 90, startDay: 4);
        $many = $this->countRebuildQueries();

        $this->assertSame($few['facts'], $many['facts'], 'fact tables are read in a fixed number of statements');
        $this->assertSame($few['total'], $many['total']);
        $this->assertSame(12, WebsiteSearchTermProfile::query()->where('website_asset_id', $this->site->id)->count());
    }

    public function test_term_resolution_and_profile_writes_do_not_grow_per_term(): void
    {
        $this->seedSearchConsole(pages: 1, terms: 10, days: 2, startDay: 1);
        $ten = $this->countRebuildQueries();

        $this->seedSearchConsole(pages: 1, terms: 120, days: 2, startDay: 3, firstTerm: 10);
        $more = $this->countRebuildQueries();

        $this->assertSame(130, WebsiteSearchTermProfile::query()->where('website_asset_id', $this->site->id)->count());
        $this->assertSame($ten['terms'], $more['terms'], 'term identities and aliases: a few statements per 500 terms');
        $this->assertSame($ten['profiles'], $more['profiles'], 'profiles: a few statements per 500 rows');
        $this->assertSame($ten['total'], $more['total']);
    }

    public function test_profile_writes_do_not_grow_per_page(): void
    {
        foreach (range(1, 20) as $i) {
            $this->websiteUrl('https://golden.test/sayfa-'.$i.'/', '2026-09-18 03:00:00');
        }
        $twenty = $this->countRebuildQueries();

        foreach (range(21, 140) as $i) {
            $this->websiteUrl('https://golden.test/sayfa-'.$i.'/', '2026-09-18 03:00:00');
        }
        $more = $this->countRebuildQueries();

        $this->assertSame(140, WebsitePageProfile::query()->where('website_asset_id', $this->site->id)->count());
        $this->assertSame($twenty['profiles'], $more['profiles'], 'page profiles: one read and one write per 500 rows');
    }

    public function test_profiles_beyond_one_write_slice_are_written_once_each_in_order(): void
    {
        $this->seedSearchConsole(pages: 1, terms: 10, days: 1, startDay: 1);
        $ten = $this->countRebuildQueries();

        $this->seedSearchConsole(pages: 1, terms: 995, days: 1, startDay: 2, firstTerm: 10);
        $more = $this->countRebuildQueries();

        $profiles = WebsiteSearchTermProfile::query()->where('website_asset_id', $this->site->id)->orderBy('id')->get();
        $this->assertCount(1005, $profiles);
        $identityIds = $profiles->pluck('search_term_identity_id')->map(fn (mixed $id): int => (int) $id)->all();
        $sorted = $identityIds;
        sort($sorted);
        $this->assertSame($sorted, $identityIds, 'new profiles take their ids in projection order across slices');
        $this->assertSame(array_unique($identityIds), $identityIds);
        $texts = $profiles->pluck('canonical_text')->sort()->values()->all();
        $expected = array_map(static fn (int $t): string => 'sorgu '.$t, range(0, 1004));
        sort($expected);
        $this->assertSame($expected, $texts);
        // Every rebuild rewrites each profile (a new projection run id): 1 005 term profiles take two reads (one per
        // 1000) and three writes (500 + 500 + 5) where 10 took one of each, instead of one select and update each.
        $this->assertSame($ten['profiles'] + 3, $more['profiles']);
    }

    private function seedSearchConsole(int $pages, int $terms, int $days, int $startDay, int $firstTerm = 0): void
    {
        $insert = function (string $table, array $row): void {
            $this->insertFact($table, $row);
        };
        for ($d = $startDay; $d < $startDay + $days; $d++) {
            $date = CarbonImmutable::parse('2026-06-21')->addDays($d)->toDateString();
            $collectedAt = $date.' 06:00:00';
            $base = [
                'digital_asset_id' => $this->site->id, 'external_resource_id' => $this->gscResource->id, 'site_url' => self::SITE_URL,
                'reporting_date' => $date, 'contract_version' => 1, 'last_collection_run_id' => $this->runs[$d % 4][0],
                'last_dataset_run_id' => $this->runs[$d % 4][1], 'first_collected_at' => $collectedAt, 'last_collected_at' => $collectedAt,
                'source_timezone' => 'America/Los_Angeles', 'created_at' => $collectedAt, 'updated_at' => $collectedAt, 'search_type' => 'web',
            ];
            $position = json_encode(['provider_average_position' => 1.5 + ($d - $startDay + 1)]);
            for ($p = 1; $p <= $pages; $p++) {
                $page = 'https://golden.test/sayfa-'.$p.'/';
                $insert('gsc_page_daily', $base + ['page' => $page, 'clicks' => 2, 'impressions' => 20, 'metadata' => $position, 'record_fingerprint' => hash('sha256', $page.$date)]);
            }
            for ($t = $firstTerm; $t < $firstTerm + $terms; $t++) {
                $query = 'sorgu '.$t;
                $page = 'https://golden.test/sayfa-'.($t % $pages + 1).'/';
                $insert('gsc_query_daily', $base + ['query' => $query, 'clicks' => 1, 'impressions' => 10, 'metadata' => $position, 'record_fingerprint' => hash('sha256', $query.$date)]);
                $insert('gsc_query_page_daily', $base + ['query' => $query, 'page' => $page, 'clicks' => 1, 'impressions' => 10, 'metadata' => $position, 'record_fingerprint' => hash('sha256', $query.$page.$date)]);
            }
        }
    }

    /**
     * Statements of a steady rebuild (data unchanged since the previous one), so two sizes compare like for like:
     * the first rebuild after new data also creates identities, aliases and profiles.
     *
     * @return array{total:int, facts:int, terms:int, profiles:int}
     */
    private function countRebuildQueries(): array
    {
        app(WebsiteProjectionRebuilder::class)->rebuild($this->site, 'test');
        $counts = ['total' => 0, 'facts' => 0, 'terms' => 0, 'profiles' => 0];
        DB::listen(function (QueryExecuted $query) use (&$counts): void {
            $counts['total']++;
            $sql = strtolower($query->sql);
            $counts['facts'] += (int) (str_contains($sql, '_daily') && str_contains($sql, 'reporting_date'));
            $counts['terms'] += (int) str_contains($sql, 'intelligence_search_term_');
            $counts['profiles'] += (int) (str_contains($sql, 'website_page_profiles') || str_contains($sql, 'website_search_term_profiles'));
        });
        app(WebsiteProjectionRebuilder::class)->rebuild($this->site, 'test');
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $counts;
    }
}
