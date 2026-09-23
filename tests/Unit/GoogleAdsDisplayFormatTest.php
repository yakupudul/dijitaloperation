<?php

namespace Tests\Unit;

use App\Services\GoogleAds\Support\GoogleAdsDisplayFormat;
use Tests\TestCase;

class GoogleAdsDisplayFormatTest extends TestCase
{
    public function test_period_delta_is_localized(): void
    {
        app()->setLocale('tr');
        $this->assertSame('önceki döneme göre +%12,5', GoogleAdsDisplayFormat::periodDelta(12.5));
        $this->assertSame('önceki döneme göre -%3,0', GoogleAdsDisplayFormat::periodDelta(-3.0));
        $this->assertSame('Önceki dönemle karşılaştırma yok', GoogleAdsDisplayFormat::periodDelta(null));

        app()->setLocale('en');
        $this->assertSame('+12.5% vs previous period', GoogleAdsDisplayFormat::periodDelta(12.5));
        $this->assertSame('vs previous period unavailable', GoogleAdsDisplayFormat::periodDelta(null));
    }

    public function test_percent_change_needs_a_non_zero_previous_value(): void
    {
        $this->assertEqualsWithDelta(50.0, GoogleAdsDisplayFormat::percentChange(150, 100), 0.0001);
        $this->assertNull(GoogleAdsDisplayFormat::percentChange(150, 0));
        $this->assertNull(GoogleAdsDisplayFormat::percentChange(150, null));
    }

    public function test_chart_dates_use_the_app_locale(): void
    {
        app()->setLocale('tr');
        $this->assertSame('5 Tem', GoogleAdsDisplayFormat::chartDate('2026-07-05'));

        app()->setLocale('en');
        $this->assertSame('Jul 5', GoogleAdsDisplayFormat::chartDate('2026-07-05'));
    }

    public function test_campaign_statuses_are_operator_labels(): void
    {
        app()->setLocale('tr');
        $this->assertSame('Aktif', GoogleAdsDisplayFormat::status('ENABLED'));
        $this->assertSame('Duraklatıldı', GoogleAdsDisplayFormat::status('PAUSED'));
        $this->assertSame('Kaldırıldı', GoogleAdsDisplayFormat::status('removed'));
    }
}
