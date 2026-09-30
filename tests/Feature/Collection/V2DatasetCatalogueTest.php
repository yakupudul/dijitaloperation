<?php

namespace Tests\Feature\Collection;

use App\Services\Collection\Providers\DataForSeo\DataForSeoRequestFamilyCatalog;
use App\Services\Collection\Providers\Ga4\Ga4RequestFamilyCatalog;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsCentralRequestFamilyCatalog;
use App\Services\Collection\Providers\MetaAds\MetaAdsProfessionalDatasetExecutor;
use App\Services\Collection\Providers\MetaAds\MetaAdsRequestFamilyCatalog;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleRequestFamilyCatalog;
use App\Services\Integrations\DataForSeo\DataForSeoEndpointAllowlist;
use App\Support\Collection\CollectionDatasetCatalog;
use Illuminate\Support\Facades\Artisan;
use ReflectionMethod;
use Tests\TestCase;

/** MoxDOP v2 Faz 1: config moxdop-collection.datasets is the single truth; every collector plans exactly that set. */
final class V2DatasetCatalogueTest extends TestCase
{
    public function test_search_console_collects_query_page_property_totals_and_sitemaps_only(): void
    {
        $datasets = array_map(
            fn (string $family): ?string => SearchConsoleRequestFamilyCatalog::definition($family)['dataset_id'],
            SearchConsoleRequestFamilyCatalog::centralFamilies(),
        );

        $this->assertEqualsCanonicalizing(['gsc_property_daily', 'gsc_query_page_daily', 'gsc_sitemap_snapshot', null], $datasets);
        $this->assertNotContains(SearchConsoleRequestFamilyCatalog::FAMILY_URL_INSPECTION, SearchConsoleRequestFamilyCatalog::centralFamilies());
        $this->assertTrue(CollectionDatasetCatalog::isOnDemand('SEARCH_CONSOLE', 'gsc_url_inspection_snapshot'));
    }

    public function test_ga4_collects_property_totals_landing_page_by_source_medium_and_key_events(): void
    {
        $datasets = array_map(
            fn (string $family): ?string => Ga4RequestFamilyCatalog::definition($family)['dataset_id'],
            Ga4RequestFamilyCatalog::centralFamilies(),
        );

        $this->assertEqualsCanonicalizing(CollectionDatasetCatalog::kept('GA4'), $datasets);
        $landing = Ga4RequestFamilyCatalog::definition(Ga4RequestFamilyCatalog::FAMILY_LANDING_SOURCE_DAILY);
        $this->assertSame(['date', 'landingPage', 'sessionSource', 'sessionMedium'], $landing['dimensions']);
        $this->assertContains('keyEvents', $landing['optional_metrics']);
    }

    public function test_google_ads_plans_exactly_the_kept_datasets(): void
    {
        $datasets = array_values(array_unique(array_map(
            fn (array $definition): string => (string) $definition['dataset_id'],
            GoogleAdsCentralRequestFamilyCatalog::definitions(),
        )));

        $this->assertEqualsCanonicalizing(CollectionDatasetCatalog::kept('GOOGLE_ADS'), $datasets);
        foreach (['google_ads_landing_page_daily', 'google_ads_device_daily', 'google_ads_hour_daily', 'google_ads_change_event', 'google_ads_shopping_product_daily'] as $dropped) {
            $this->assertNotContains($dropped, $datasets);
        }
    }

    public function test_meta_producers_cover_exactly_the_kept_datasets_and_breakdowns_are_region_only(): void
    {
        $produced = [];
        foreach ((array) config('moxdop-meta-ads-central.families') as $definition) {
            $produced[] = (string) $definition['dataset'];
        }
        foreach (MetaAdsRequestFamilyCatalog::supportedFamilies() as $family) {
            array_push($produced, ...MetaAdsRequestFamilyCatalog::definition($family)['dataset_ids']);
        }
        $kept = array_values(array_filter(array_unique($produced), fn (string $dataset): bool => CollectionDatasetCatalog::keeps('META_ADS', $dataset)));

        $this->assertEqualsCanonicalizing(CollectionDatasetCatalog::kept('META_ADS'), $kept);
        $this->assertFalse(CollectionDatasetCatalog::keeps('META_ADS', 'meta_hourly_daily'));
        $this->assertFalse(CollectionDatasetCatalog::keeps('META_ADS', 'meta_change_event'));

        $groups = new ReflectionMethod(MetaAdsProfessionalDatasetExecutor::class, 'breakdownGroupNames');
        $this->assertSame(['region'], $groups->invoke(app(MetaAdsProfessionalDatasetExecutor::class)));
    }

    public function test_business_profile_drops_place_actions_and_verification(): void
    {
        $this->assertEqualsCanonicalizing([
            'gbp_location', 'gbp_attributes', 'gbp_services', 'gbp_reviews', 'gbp_posts',
            'gbp_performance_daily', 'gbp_search_keywords_monthly', 'gbp_media',
        ], CollectionDatasetCatalog::kept('GOOGLE_BUSINESS_PROFILE'));
        $this->assertFalse(CollectionDatasetCatalog::keeps('GOOGLE_BUSINESS_PROFILE', 'gbp_place_actions'));
        $this->assertFalse(CollectionDatasetCatalog::keeps('GOOGLE_BUSINESS_PROFILE', 'gbp_verification'));
    }

    public function test_dataforseo_is_limited_to_serp_top_ten_and_search_volume(): void
    {
        $this->assertEqualsCanonicalizing([
            DataForSeoEndpointAllowlist::APPENDIX_USER_DATA,
            DataForSeoEndpointAllowlist::LABS_LOCATIONS_AND_LANGUAGES,
            DataForSeoEndpointAllowlist::SERP_GOOGLE_LOCATIONS_TR,
            DataForSeoEndpointAllowlist::SERP_GOOGLE_ORGANIC_LIVE_ADVANCED,
            DataForSeoEndpointAllowlist::KEYWORDS_DATA_GOOGLE_ADS_SEARCH_VOLUME_LIVE,
        ], DataForSeoEndpointAllowlist::all());
        $this->assertFalse(DataForSeoEndpointAllowlist::isAllowed(DataForSeoEndpointAllowlist::LABS_GOOGLE_RANKED_KEYWORDS_LIVE));
        $this->assertFalse(DataForSeoEndpointAllowlist::isAllowed('serp/google/maps/task_get/advanced/12345678-aaaa'));
        $this->assertSame(['DFS-FREE-USER', 'DFS-FREE-MARKETS'], DataForSeoRequestFamilyCatalog::supportedFamilies(), 'no paid collection-engine family');
        $this->assertFileDoesNotExist(app_path('Services/Collection/DataForSeo/DataForSeoEnrichmentOrchestrator.php'));
        $this->assertArrayNotHasKey('moxdop:intel:collect', Artisan::all(), 'no DataForSEO task queue is polled');
        $this->assertEqualsCanonicalizing(['serp_results', 'query_volumes'], CollectionDatasetCatalog::kept('DATAFORSEO'));
    }
}
