<?php

namespace Tests\Feature;

use App\Services\CrossAssetWebsiteGbpPhoneConsistencyService;
use Tests\TestCase;

/**
 * Runtime replacement for markdown heading checks. Cross-asset extras stay deferred.
 */
class CrossAssetAnalysisProductSpecTest extends TestCase
{
    public function test_cross_asset_analysis_is_not_a_shipped_operator_module(): void
    {
        $this->assertDirectoryDoesNotExist(base_path('app-modules/cross-asset-analysis'));
        $this->assertFileExists(base_path('docs/product/cross-asset/CROSS_ASSET_ANALYSIS.md'));
        $this->assertTrue(class_exists(CrossAssetWebsiteGbpPhoneConsistencyService::class));
    }
}
