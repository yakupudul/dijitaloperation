<?php

namespace Tests\Feature\Collection;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Panel audit N4 (2026-10-21): after the v2 collection (2026-09-29) many screens kept reading Search Console and GA4
 * tables nobody fills any more and silently showed 0. A new read of an uncollected table breaks this test: read a
 * collected table (config moxdop-collection.datasets) instead, or collect the dataset.
 */
final class UncollectedTableReadsTest extends TestCase
{
    /** Collection plumbing that names every dataset on purpose. */
    private const string PLUMBING = '#(Catalog|Normalizer|Registry|DatasetLabels|Benchmark|DatabaseSlim|CentralCollectionService|Executor|Contract|Retention|Collector|Writer)#';

    /**
     * Known reads left, each showing its section as not collected: table => files. Shrink this list, never grow it.
     *
     * @var array<string, list<string>>
     */
    private const array KNOWN = [
        'ga4_ecommerce_item_daily' => ['Services/Ga4/WebsiteGa4AnalysisService.php'],
        'ga4_event_campaign_daily' => ['Services/Ga4/WebsiteGa4AnalysisService.php'],
        'ga4_event_channel_daily' => ['Services/Ga4/WebsiteGa4AnalysisService.php'],
        'ga4_event_landing_daily' => ['Services/Ga4/WebsiteGa4AnalysisService.php'],
        'ga4_first_user_acquisition_daily' => ['Services/Ga4/WebsiteGa4AnalysisService.php'],
        'ga4_geo_city_daily' => ['Services/Ga4/WebsiteGa4AnalysisService.php'],
        'ga4_geo_region_daily' => ['Services/Ga4/WebsiteGa4AnalysisService.php'],
        'ga4_hour_daily' => ['Services/Ga4/WebsiteGa4AnalysisService.php'],
        'ga4_page_content_daily' => ['Services/Ga4/WebsiteGa4AnalysisService.php', 'Services/IntelligenceProjection/Website/Adapters/Ga4ProjectionAdapter.php'],
        'ga4_technology_daily' => ['Services/Ga4/WebsiteGa4AnalysisService.php'],
        'gsc_page_country_daily' => ['Services/Gsc/WebsiteSearchConsoleAnalysisService.php'],
        'gsc_page_device_daily' => ['Services/Gsc/WebsiteSearchConsoleAnalysisService.php'],
        'gsc_query_country_daily' => ['Mcp/Tools/ChannelData.php', 'Services/Gsc/WebsiteSearchConsoleAnalysisService.php'],
        'gsc_query_device_daily' => ['Services/Gsc/WebsiteSearchConsoleAnalysisService.php'],
        // Moves rows between duplicate assets: names old tables on purpose.
        'gsc_query_daily' => ['Services/Ownership/WebsiteDuplicateMerger.php'],
        'gsc_search_appearance_daily' => ['Services/Gsc/WebsiteSearchConsoleAnalysisService.php', 'Services/Integrations/ResourceAutomationService.php'],
        'gsc_url_inspection_daily' => ['Services/Gsc/UrlInspectionTargets.php'],
    ];

    public function test_no_new_code_reads_a_search_console_or_ga4_table_that_is_not_collected(): void
    {
        $kept = array_merge(...array_values((array) config('moxdop-collection.datasets')), ...array_values((array) config('moxdop-collection.on_demand')));
        $found = [];
        $app = app_path();
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app)) as $file) {
            if ($file->getExtension() !== 'php' || preg_match(self::PLUMBING, $file->getPathname()) === 1) {
                continue;
            }
            preg_match_all('/[\'"]((?:gsc|ga4)_[a-z0-9_]+_(?:daily|snapshot|monthly))[\'"]/', (string) file_get_contents($file->getPathname()), $matches);
            foreach (array_unique($matches[1]) as $table) {
                if (! in_array($table, $kept, true)) {
                    $found[$table][] = ltrim(str_replace($app, '', $file->getPathname()), '/');
                }
            }
        }

        $unexpected = [];
        foreach ($found as $table => $files) {
            foreach (array_diff($files, self::KNOWN[$table] ?? []) as $path) {
                $unexpected[] = $table.' in '.$path;
            }
        }
        $this->assertSame([], $unexpected, 'Reads of tables that are not collected (they always return nothing).');
    }
}
