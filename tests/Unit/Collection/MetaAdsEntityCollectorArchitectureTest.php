<?php

namespace Tests\Unit\Collection;

use App\Services\Collection\Providers\MetaAds\MetaAdsRequestFamilyCatalog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetaAdsEntityCollectorArchitectureTest extends TestCase
{
    #[Test]
    public function v1_runtime_families_do_not_overlap_professional_v2(): void
    {
        $this->assertSame([
            MetaAdsRequestFamilyCatalog::FAMILY_AD_ACCOUNT_META,
            MetaAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT,
        ], MetaAdsRequestFamilyCatalog::supportedFamilies());

        $professionalFamilies = array_keys((array) config('moxdop-meta-ads-central.families', []));

        $this->assertSame([], array_values(array_intersect(
            MetaAdsRequestFamilyCatalog::supportedFamilies(),
            $professionalFamilies,
        )));
    }

    #[Test]
    public function entity_snapshot_executor_is_dataset_aware_and_never_builds_provider_id_in_filters(): void
    {
        $path = app_path('Services/Collection/Providers/MetaAds/MetaAdsDatasetExecutor.php');
        $source = (string) file_get_contents($path);

        $this->assertStringContainsString("'meta_campaign_snapshot' => \$this->executeCampaignSnapshot", $source);
        $this->assertStringContainsString("'meta_adset_snapshot' => \$this->executeAdSetSnapshot", $source);
        $this->assertStringContainsString("'meta_creative_snapshot' => \$this->executeCreativeSnapshot", $source);
        $this->assertStringContainsString('account_edge_cursor_pagination_then_application_id_filter', $source);
        $this->assertStringContainsString("\$scope['act_id'].'/campaigns'", $source);
        $this->assertStringContainsString("\$scope['act_id'].'/adsets'", $source);
        $this->assertStringContainsString("\$scope['act_id'].'/ads'", $source);
        $this->assertStringContainsString("\$scope['act_id'].'/adcreatives'", $source);
        $this->assertStringNotContainsString("'filtering' =>", $source);
        $this->assertStringNotContainsString("'operator' => 'IN'", $source);
    }

    #[Test]
    public function meta_collectors_share_the_canonical_api_client_without_a_second_compatibility_client(): void
    {
        $providerSource = (string) file_get_contents(app_path('../app/Providers/MetaAdsCollectionServiceProvider.php'));

        $this->assertStringContainsString('singleton(MetaApiClient::class)', $providerSource);
        $this->assertStringNotContainsString('MetaApiClientCompatibility', $providerSource);
        $this->assertFileDoesNotExist(app_path('Services/Integrations/Meta/MetaApiClientCompatibility.php'));
    }
}
