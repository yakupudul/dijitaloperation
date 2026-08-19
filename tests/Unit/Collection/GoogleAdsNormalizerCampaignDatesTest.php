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

    #[Test]
    public function keyword_snapshots_collapse_duplicate_criterion_ids_last_wins(): void
    {
        $normalized = (new GoogleAdsNormalizer)->normalizeKeywordSnapshots(
            '1112223333',
            'Europe/Istanbul',
            [
                [
                    'adGroupCriterion' => [
                        'criterionId' => '777',
                        'status' => 'ENABLED',
                        'keyword' => ['text' => 'dental', 'matchType' => 'EXACT'],
                    ],
                    'adGroup' => ['id' => '22'],
                    'campaign' => ['id' => '555'],
                ],
                [
                    'adGroupCriterion' => [
                        'criterionId' => '777',
                        'status' => 'PAUSED',
                        'keyword' => ['text' => 'dental', 'matchType' => 'EXACT'],
                    ],
                    'adGroup' => ['id' => '23'],
                    'campaign' => ['id' => '555'],
                ],
            ],
            2,
            173,
        );

        $this->assertCount(1, $normalized);
        $this->assertSame('777', $normalized[0]['criterion_id']);
        $this->assertSame('23', (string) $normalized[0]['metadata']['ad_group_id']);
        $this->assertSame('PAUSED', $normalized[0]['metadata']['status']);
    }
}
