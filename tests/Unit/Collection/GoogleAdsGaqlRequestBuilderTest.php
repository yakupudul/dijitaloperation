<?php

namespace Tests\Unit\Collection;

use App\Services\Collection\Providers\GoogleAds\GoogleAdsGaqlRequestBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GoogleAdsGaqlRequestBuilderTest extends TestCase
{
    #[Test]
    public function campaign_snapshot_uses_v25_start_and_end_date_time_fields(): void
    {
        $query = (new GoogleAdsGaqlRequestBuilder)->campaignSnapshot();

        $this->assertStringContainsString('campaign.start_date_time', $query);
        $this->assertStringContainsString('campaign.end_date_time', $query);
        $this->assertDoesNotMatchRegularExpression('/campaign\.start_date(?!_time)/', $query);
        $this->assertDoesNotMatchRegularExpression('/campaign\.end_date(?!_time)/', $query);
        $this->assertStringContainsString('FROM campaign', $query);
    }
}
