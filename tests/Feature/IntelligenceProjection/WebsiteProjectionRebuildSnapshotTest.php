<?php

namespace Tests\Feature\IntelligenceProjection;

use App\Services\IntelligenceProjection\Website\WebsiteProjectionRebuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pins what a rebuild writes (profiles, identities, aliases, run summary) for a site with several pages, terms and
 * days of Search Console and GA4 facts: a full rebuild, a second one that updates and adds rows, and a partial one
 * where Search Console fails halfway through its terms. The snapshot was taken with the row-by-row rebuild, before
 * the SQL aggregation and bulk writes; the rebuild must keep producing exactly this.
 */
final class WebsiteProjectionRebuildSnapshotTest extends TestCase
{
    use BuildsProjectionFixture;
    use RefreshDatabase;

    private const string SNAPSHOT = __DIR__.'/WebsiteProjectionRebuildSnapshot.json';

    /** @var array<string, list<string>> table => JSON columns */
    private const array TABLES = [
        'website_intelligence_projection_runs' => ['source_watermarks', 'coverage_state', 'summary'],
        'website_page_profiles' => ['source_states', 'coverage_state'],
        'website_search_term_profiles' => ['source_states', 'coverage_state'],
        'website_entity_profiles' => ['source_states', 'coverage_state'],
        'website_outcome_profiles' => ['source_states', 'coverage_state'],
        'intelligence_page_identities' => [],
        'intelligence_page_aliases' => ['metadata'],
        'intelligence_search_term_identities' => [],
        'intelligence_search_term_aliases' => ['metadata'],
        'intelligence_entity_identities' => [],
        'intelligence_entity_aliases' => ['metadata'],
        'intelligence_business_action_aliases' => ['metadata'],
    ];

    public function test_rebuilds_write_the_pinned_profiles_identities_and_aliases(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The snapshot holds SQLite row ids; PostgreSQL sequences are not reset between tests.');
        }

        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00:00', 'UTC'));
        $this->bindWebsite();
        $this->mapLeadOutcome();
        $this->seedFirstCollection();
        $snapshots = ['full' => $this->rebuildAndDump()];

        $this->travelTo(CarbonImmutable::parse('2026-09-21 10:00:00', 'UTC'));
        $this->seedSecondCollection();
        $snapshots['update'] = $this->rebuildAndDump();

        $this->travelTo(CarbonImmutable::parse('2026-09-22 10:00:00', 'UTC'));
        $this->seedThirdCollection();
        $snapshots['partial'] = $this->rebuildAndDump();

        $this->assertSame('partial', data_get($snapshots, 'partial.website_intelligence_projection_runs.2.status'));
        $encoded = $this->encode($snapshots);
        if (getenv('UPDATE_PROJECTION_SNAPSHOT') === '1') {
            file_put_contents(self::SNAPSHOT, $encoded);
        }
        $this->assertSame(
            json_decode((string) file_get_contents(self::SNAPSHOT), true, 512, JSON_THROW_ON_ERROR),
            json_decode($encoded, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * One table row per line, so a deliberate change shows as a readable diff. Regenerate only for an intended
     * output change: UPDATE_PROJECTION_SNAPSHOT=1 php artisan test --filter=WebsiteProjectionRebuildSnapshotTest
     *
     * @param  array<string, array<string, list<array<string, mixed>>>>  $snapshots
     */
    private function encode(array $snapshots): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
        $stages = [];
        foreach ($snapshots as $stage => $tables) {
            $lines = [];
            foreach ($tables as $table => $rows) {
                $encodedRows = array_map(static fn (array $row): string => json_encode($row, $flags), $rows);
                $lines[] = '    '.json_encode($table, $flags).': ['.($encodedRows === [] ? '' : "\n      ".implode(",\n      ", $encodedRows)."\n    ").']';
            }
            $stages[] = '  '.json_encode($stage, $flags).": {\n".implode(",\n", $lines)."\n  }";
        }

        return "{\n".implode(",\n", $stages)."\n}\n";
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function rebuildAndDump(): array
    {
        app(WebsiteProjectionRebuilder::class)->rebuild($this->site, 'test');

        $dump = [];
        foreach (self::TABLES as $table => $jsonColumns) {
            $dump[$table] = DB::table($table)->orderBy('id')->get()->map(function (object $row) use ($jsonColumns): array {
                $values = (array) $row;
                unset($values['uuid']);
                foreach ($jsonColumns as $column) {
                    $values[$column] = is_string($values[$column] ?? null) ? json_decode($values[$column], true) : $values[$column];
                }

                return $values;
            })->all();
        }

        return $dump;
    }

    /**
     * Ids are deliberately not in date order (later days first, then a backfill of older days) so the first and
     * latest row of each page/term follow the reporting date, not the insert order.
     */
    private function seedFirstCollection(): void
    {
        foreach (['https://golden.test/', 'https://golden.test/implant/', 'https://golden.test/iletisim/'] as $url) {
            $this->websiteUrl($url, '2026-09-18 03:00:00');
        }

        $page = 'gsc_page_daily';
        $this->gsc($page, '2026-09-12', ['page' => 'https://golden.test/'], 5, 40, 2.25, 1, '2026-09-13 06:00:00');
        $this->gsc($page, '2026-09-12', ['page' => 'https://golden.test/implant/'], 3, 20, 4.75, 1, '2026-09-13 06:00:00');
        $this->gsc($page, '2026-09-13', ['page' => 'https://golden.test/'], 7, 48, 1.5, 3, '2026-09-14 06:00:00');
        $this->gsc($page, '2026-09-13', ['page' => '/hizmetler/'], 1, 8, 10.125, 3, '2026-09-14 06:00:00');
        $this->gsc($page, '2026-09-10', ['page' => 'https://golden.test/implant/'], 2, 16, 6.5, 2, '2026-09-15 08:00:00');
        $this->gsc($page, '2026-09-10', ['page' => '123'], 0, 4, null, 2, '2026-09-15 08:00:00');
        $this->gsc($page, '2026-09-11', ['page' => 'https://golden.test/'], 4, 32, 3.0, 0, '2026-09-12 06:00:00');
        $this->gsc($page, '2026-09-11', ['page' => 'https://golden.test/implant/ '], 1, 12, 5.25, 0, '2026-09-12 06:00:00');
        $this->gsc($page, '2026-09-11', ['page' => '   '], 9, 9, 1.0, 0, '2026-09-12 06:00:00');
        $this->gsc($page, '2026-09-11', ['page' => '/hizmetler/'], 0, 0, 12.0, 0, '2026-09-12 06:00:00');
        $this->gsc($page, '2026-05-01', ['page' => 'https://golden.test/'], 100, 100, 1.0, 0, '2026-05-02 06:00:00');
        $this->gsc($page, '2026-09-09', ['page' => 'https://golden.test/'], 50, 50, 1.0, 0, '2026-09-12 06:00:00', searchType: 'image');

        $query = 'gsc_query_daily';
        $this->gsc($query, '2026-09-12', ['query' => 'diş implant'], 2, 30, 3.5, 1, '2026-09-13 06:00:00');
        $this->gsc($query, '2026-09-12', ['query' => 'implant fiyatları'], 1, 10, 8.25, 1, '2026-09-13 06:00:00');
        $this->gsc($query, '2026-09-13', ['query' => 'Diş İmplant'], 3, 12, 2.5, 3, '2026-09-14 06:00:00');
        $this->gsc($query, '2026-09-13', ['query' => '123'], 0, 2, null, 3, '2026-09-14 06:00:00', nullMetadata: true);
        $this->gsc($query, '2026-09-10', ['query' => 'implant fiyatları '], 0, 6, 9.5, 2, '2026-09-15 08:00:00');
        $this->gsc($query, '2026-09-11', ['query' => 'diş implant'], 1, 20, 4.0, 0, '2026-09-12 06:00:00');
        $this->gsc($query, '2026-09-11', ['query' => ' '], 1, 1, 1.0, 0, '2026-09-12 06:00:00');
        $this->gsc($query, '2026-09-11', ['query' => 'tedavi'], 0, 4, 11.75, 0, '2026-09-12 06:00:00');

        $pair = 'gsc_query_page_daily';
        $this->gsc($pair, '2026-09-12', ['query' => 'diş implant', 'page' => 'https://golden.test/implant/'], 2, 20, 3.25, 1, '2026-09-13 06:00:00');
        $this->gsc($pair, '2026-09-12', ['query' => 'diş implant', 'page' => 'https://golden.test/'], 0, 10, 4.5, 1, '2026-09-13 06:00:00');
        $this->gsc($pair, '2026-09-13', ['query' => 'Diş İmplant', 'page' => 'https://golden.test/implant/'], 3, 12, 2.5, 3, '2026-09-14 06:00:00');
        $this->gsc($pair, '2026-09-13', ['query' => 'implant fiyatları', 'page' => 'https://golden.test/sadece-sorgu/'], 1, 10, 7.0, 3, '2026-09-14 06:00:00');
        $this->gsc($pair, '2026-09-13', ['query' => 'yok sorgu', 'page' => 'https://golden.test/'], 0, 10, 2.0, 3, '2026-09-14 06:00:00');
        $this->gsc($pair, '2026-09-10', ['query' => 'implant fiyatları ', 'page' => 'https://golden.test/implant/'], 0, 6, 9.5, 2, '2026-09-15 08:00:00');
        $this->gsc($pair, '2026-09-11', ['query' => 'diş implant', 'page' => 'https://golden.test/implant/'], 1, 10, 5.0, 0, '2026-09-12 06:00:00');
        $this->gsc($pair, '2026-09-11', ['query' => 'tedavi', 'page' => 'https://golden.test/'], 0, 10, 12.25, 0, '2026-09-12 06:00:00');
        $this->gsc($pair, '2026-09-11', ['query' => '123', 'page' => '/hizmetler/'], 0, 2, null, 0, '2026-09-12 06:00:00', nullMetadata: true);

        $landing = 'ga4_landing_page_daily';
        $this->ga4($landing, '2026-09-12', ['landingPagePlusQueryString' => '/implant/?utm_source=x', 'landingPage' => '/implant/', 'sessions' => 10, 'engagedSessions' => 6, 'keyEvents' => 1.5], 1, '2026-09-13 07:00:00');
        $this->ga4($landing, '2026-09-13', ['landingPagePlusQueryString' => null, 'landingPage' => '/hizmetler/', 'sessions' => 4, 'engagedSessions' => 1, 'keyEvents' => null], 3, '2026-09-14 07:00:00');
        $this->ga4($landing, '2026-09-13', ['landingPagePlusQueryString' => '(not set)', 'landingPage' => '(not set)', 'sessions' => 9, 'engagedSessions' => 9, 'keyEvents' => 9.0], 3, '2026-09-14 07:00:00');
        $this->ga4($landing, '2026-09-10', ['landingPagePlusQueryString' => '/implant/?utm_source=x', 'landingPage' => '/implant/', 'sessions' => 3, 'engagedSessions' => 2, 'keyEvents' => 0.25], 2, '2026-09-15 09:00:00');
        $this->ga4($landing, '2026-09-11', ['landingPagePlusQueryString' => '', 'landingPage' => '/bos/', 'sessions' => 2, 'engagedSessions' => 2, 'keyEvents' => 1.0], 0, '2026-09-12 07:00:00');
        $this->ga4($landing, '2026-09-11', ['landingPagePlusQueryString' => null, 'landingPage' => '/hizmetler/', 'sessions' => 1, 'engagedSessions' => 1, 'keyEvents' => 2.0], 0, '2026-09-12 07:00:00');

        $content = 'ga4_page_content_daily';
        $this->ga4($content, '2026-09-12', $this->contentValues('golden.test', '/implant/', 'İmplant Tedavisi', 20, 1.0), 1, '2026-09-13 07:00:00');
        $this->ga4($content, '2026-09-12', $this->contentValues('golden.test', '/implant/', '404', 1, null), 1, '2026-09-13 07:00:00');
        $this->ga4($content, '2026-09-13', $this->contentValues('WWW.Golden.test ', '/implant/', 'İmplant', 5, 0.75), 3, '2026-09-14 07:00:00');
        $this->ga4($content, '2026-09-10', $this->contentValues('golden.test', '/implant/', 'İmplant', 6, 0.5), 2, '2026-09-15 09:00:00');
        $this->ga4($content, '2026-09-11', $this->contentValues('golden.test', '/hizmetler/', 'Hizmetler', 3, null), 0, '2026-09-12 07:00:00');
        $this->ga4($content, '2026-09-11', $this->contentValues('golden.test', '', 'Boş', 4, 1.0), 0, '2026-09-12 07:00:00');
        $this->ga4($content, '2026-09-11', $this->contentValues('golden.test', '/implant/', '', 2, null), 0, '2026-09-12 07:00:00');

        $events = 'ga4_key_event_daily';
        $this->ga4($events, '2026-09-11', ['eventName' => 'generate_lead', 'keyEvents' => 2.0], 0, '2026-09-12 07:00:00');
        $this->ga4($events, '2026-09-12', ['eventName' => 'generate_lead', 'keyEvents' => 1.5], 1, '2026-09-13 07:00:00');
        $this->ga4($events, '2026-09-12', ['eventName' => 'other_event', 'keyEvents' => 4.0], 1, '2026-09-13 07:00:00');
    }

    /** Adds a day: an existing page and term get newer data, a new page and term appear, a crawled URL disappears. */
    private function seedSecondCollection(): void
    {
        DB::table('website_url')->where('normalized_url', 'https://golden.test/iletisim/')->delete();
        $this->gsc('gsc_page_daily', '2026-09-14', ['page' => 'https://golden.test/'], 2, 16, 2.75, 3, '2026-09-21 06:00:00');
        $this->gsc('gsc_page_daily', '2026-09-14', ['page' => 'https://golden.test/yeni/'], 1, 4, 6.0, 3, '2026-09-21 06:00:00');
        $this->gsc('gsc_query_daily', '2026-09-14', ['query' => 'diş implant'], 1, 8, 3.75, 3, '2026-09-21 06:00:00');
        $this->gsc('gsc_query_daily', '2026-09-14', ['query' => 'yeni sorgu'], 0, 2, 15.5, 3, '2026-09-21 06:00:00');
        $this->gsc('gsc_query_page_daily', '2026-09-14', ['query' => 'yeni sorgu', 'page' => 'https://golden.test/yeni/'], 0, 2, 15.5, 3, '2026-09-21 06:00:00');
        $this->ga4('ga4_landing_page_daily', '2026-09-14', ['landingPagePlusQueryString' => '/implant/?utm_source=x', 'landingPage' => '/implant/', 'sessions' => 2, 'engagedSessions' => 2, 'keyEvents' => null], 3, '2026-09-21 07:00:00');
    }

    /**
     * A term that normalizes to nothing (a non-breaking space) fails Search Console halfway: the terms before it
     * are resolved, the ones after it are not, and the rebuild is partial (other sources still update).
     */
    private function seedThirdCollection(): void
    {
        $this->gsc('gsc_query_daily', '2026-09-15', ['query' => 'tedavi'], 0, 2, 9.0, 3, '2026-09-22 06:00:00');
        $this->gsc('gsc_query_daily', '2026-09-15', ['query' => 'Diş İmplant'], 1, 4, 2.0, 3, '2026-09-22 06:00:00');
        $this->gsc('gsc_query_daily', '2026-09-11', ['query' => "\u{00A0}"], 0, 1, 20.0, 0, '2026-09-22 06:00:00');
        $this->ga4('ga4_landing_page_daily', '2026-09-15', ['landingPagePlusQueryString' => null, 'landingPage' => '/hizmetler/', 'sessions' => 5, 'engagedSessions' => 3, 'keyEvents' => 1.0], 3, '2026-09-22 07:00:00');
    }

    /** @return array<string, mixed> */
    private function contentValues(string $host, string $path, string $title, int $views, ?float $keyEvents): array
    {
        return [
            'hostName' => $host, 'pagePathPlusQueryString' => $path, 'pageTitle' => $title, 'screenPageViews' => $views,
            'activeUsers' => intdiv($views, 2), 'totalUsers' => intdiv($views, 2) + 1, 'eventCount' => $views * 3,
            'scrolledUsers' => intdiv($views, 4), 'userEngagementDuration' => $views * 7, 'keyEvents' => $keyEvents,
        ];
    }
}
