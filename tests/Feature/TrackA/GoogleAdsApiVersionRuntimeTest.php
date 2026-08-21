<?php

namespace Tests\Feature\TrackA;

use App\Support\Integrations\Google\GoogleOAuthConfig;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GoogleAdsApiVersionRuntimeTest extends TestCase
{
    #[Test]
    public function configured_google_ads_api_version_defaults_to_currently_supported_v25(): void
    {
        $this->assertSame('v25', config('moxdop.google.ads_api_version'));
        $this->assertSame('v25', GoogleOAuthConfig::adsApiVersion());
        $this->assertStringContainsString('/v25/', GoogleOAuthConfig::adsApiUrl('customers:listAccessibleCustomers'));
    }

    #[Test]
    public function google_ads_runtime_php_does_not_hardcode_retired_api_versions(): void
    {
        $paths = [
            base_path('config/moxdop.php'),
            base_path('app/Support/Integrations/Google/GoogleOAuthConfig.php'),
            base_path('config/moxdop-google-ads-collector.php'),
        ];
        foreach ($paths as $path) {
            $contents = (string) file_get_contents($path);
            $this->assertDoesNotMatchRegularExpression('/googleads\\.googleapis\\.com\\/v1[0-9]\\b/', $contents, $path);
            $this->assertStringNotContainsString("'v19'", $contents, $path);
        }
    }
}
