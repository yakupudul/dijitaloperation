<?php

namespace Tests\Unit\Collection;

use App\Services\Collection\Providers\GoogleAds\GoogleAdsNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GoogleAdsNormalizerCampaignDatesTest extends TestCase
{
    #[Test]
    public function campaign_snapshots_map_v25_date_time_fields_to_calendar_dates(): void
    {
        $normalized = (new GoogleAdsNormalizer)->normalizeCampaignSnapshots(
            '1112223333',
            'Europe/Berlin',
            [[
                'campaign' => [
                    'id' => '555',
                    'name' => 'Search Brand',
                    'status' => 'ENABLED',
                    'advertisingChannelType' => 'SEARCH',
                    'startDateTime' => '2026-01-15 00:00:00',
                    'endDateTime' => '2026-12-31 23:59:59',
                ],
                'campaignBudget' => [
                    'id' => '91',
                    'amountMicros' => '50000000',
                    'deliveryMethod' => 'STANDARD',
                    'explicitlyShared' => false,
                ],
            ]],
            1,
            2,
        );

        $this->assertSame('2026-01-15', $normalized['campaigns'][0]['metadata']['start_date']);
        $this->assertSame('2026-12-31', $normalized['campaigns'][0]['metadata']['end_date']);
    }
}
