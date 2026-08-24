<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Runtime replacement for markdown heading checks. Meta depth is Track D.
 */
class MetaAdsProductSpecTest extends TestCase
{
    public function test_meta_ads_module_package_exists_and_is_not_a_track_a_completeness_claim(): void
    {
        $this->assertTrue(is_dir(base_path('app-modules/meta-ads')));
        $this->assertFileExists(base_path('docs/product/meta-ads/META_ADS.md'));
        $this->assertFileExists(base_path('app-modules/meta-ads/src/Collection/MetaAdsBoundCollector.php'));
    }
}
