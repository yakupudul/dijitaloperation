<?php

namespace Tests\Unit;

use App\Services\Collection\Providers\GoogleAds\GoogleAdsNormalizer;
use App\Services\GoogleAds\GoogleAdsLandingPageControlService;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class GoogleAdsLandingPageControlServiceTest extends TestCase
{
    public function test_high_exposure_page_without_conversions_is_classified_as_risk(): void
    {
        $service = (new ReflectionClass(GoogleAdsLandingPageControlService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(GoogleAdsLandingPageControlService::class, 'classify');
        $method->setAccessible(true);

        $result = $method->invoke($service, [
            'id' => 'lp-a',
            'url' => 'https://example.com/a',
            'spend' => 1000.0,
            'clicks' => 100,
            'conversions' => 0.0,
            'cpa' => null,
            'cvr' => 0.0,
            'speed_score' => null,
            'mobile_friendly_clicks_pct' => null,
        ], [
            'spend_p75' => 800.0,
            'spend_median' => 400.0,
            'clicks_p75' => 80.0,
            'cpa_p25' => 50.0,
            'cpa_p75' => 150.0,
            'cvr_p25' => 2.0,
            'cvr_p75' => 8.0,
        ], null);

        $this->assertSame('risk', $result['row']['decision_group']);
        $this->assertSame('zero_conversion_high_exposure', $result['row']['decision']);
    }

    public function test_efficient_high_conversion_rate_page_is_classified_as_strong(): void
    {
        $service = (new ReflectionClass(GoogleAdsLandingPageControlService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(GoogleAdsLandingPageControlService::class, 'classify');
        $method->setAccessible(true);

        $result = $method->invoke($service, [
            'id' => 'lp-b',
            'url' => 'https://example.com/b',
            'spend' => 800.0,
            'clicks' => 100,
            'conversions' => 10.0,
            'cpa' => 80.0,
            'cvr' => 10.0,
            'speed_score' => 9,
            'mobile_friendly_clicks_pct' => 100.0,
        ], [
            'spend_p75' => 900.0,
            'spend_median' => 500.0,
            'clicks_p75' => 120.0,
            'cpa_p25' => 90.0,
            'cpa_p75' => 180.0,
            'cvr_p25' => 2.0,
            'cvr_p75' => 8.0,
        ], null);

        $this->assertSame('strong', $result['row']['decision_group']);
        $this->assertSame('strong_page', $result['row']['decision']);
    }

    public function test_landing_page_normalizer_persists_google_mobile_and_speed_signals(): void
    {
        $normalizer = new GoogleAdsNormalizer();
        $records = $normalizer->normalizeLandingPageDaily(
            '1234567890',
            'Europe/Istanbul',
            'TRY',
            [[
                'segments' => ['date' => '2026-08-24'],
                'landingPageView' => ['unexpandedFinalUrl' => 'https://example.com/landing'],
                'metrics' => [
                    'impressions' => '100',
                    'clicks' => '10',
                    'costMicros' => '50000000',
                    'conversions' => '2',
                    'mobileFriendlyClicksPercentage' => 0.85,
                    'speedScore' => 7,
                ],
            ]],
            null,
            42,
        );

        $this->assertCount(1, $records);
        $this->assertSame(85.0, $records[0]['metadata']['mobile_friendly_clicks_percentage']);
        $this->assertSame(7, $records[0]['metadata']['speed_score']);
    }
}
