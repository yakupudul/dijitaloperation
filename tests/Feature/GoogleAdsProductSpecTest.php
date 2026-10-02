<?php

namespace Tests\Feature;

use App\Support\Integrations\Google\GoogleOAuthConfig;
use Tests\TestCase;

/**
 * Runtime replacement for markdown heading checks. Google Ads API version is a
 * Track A0 gate; deeper Ads facts remain Track C.
 */
class GoogleAdsProductSpecTest extends TestCase
{
    public function test_google_ads_api_version_is_the_supported_v25_runtime_default(): void
    {
        $this->assertSame('v25', config('moxdop.google.ads_api_version'));
        $this->assertSame('v25', GoogleOAuthConfig::adsApiVersion());
        $this->assertStringContainsString('/v25/', GoogleOAuthConfig::adsApiUrl('customers:listAccessibleCustomers'));
        $this->assertFileExists(base_path('docs/product/google-ads/GOOGLE_ADS.md'));
    }
}
