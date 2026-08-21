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
    public function keyword_snapshots_preserve_same_criterion_in_different_ad_groups(): void
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

        $this->assertCount(2, $normalized);
        $ids = collect($normalized)->pluck('ad_group_id')->map(fn ($id): string => (string) $id)->sort()->values()->all();
        $this->assertSame(['22', '23'], $ids);
        $byGroup = collect($normalized)->keyBy(fn (array $row): string => (string) $row['ad_group_id']);
        $this->assertSame('777', (string) $byGroup->get('22')['criterion_id']);
        $this->assertSame('ENABLED', $byGroup->get('22')['metadata']['status']);
        $this->assertSame('PAUSED', $byGroup->get('23')['metadata']['status']);
    }

    #[Test]
    public function keyword_daily_preserves_same_criterion_in_different_ad_groups(): void
    {
        $normalized = (new GoogleAdsNormalizer)->normalizeKeywordDaily(
            '1112223333',
            'Europe/Istanbul',
            'TRY',
            [
                [
                    'segments' => ['date' => '2026-08-12'],
                    'adGroupCriterion' => [
                        'criterionId' => '777',
                        'status' => 'ENABLED',
                        'keyword' => ['text' => 'dental', 'matchType' => 'EXACT'],
                    ],
                    'adGroup' => ['id' => '22'],
                    'campaign' => ['id' => '555'],
                    'metrics' => ['impressions' => 10, 'clicks' => 2, 'costMicros' => '1000000', 'conversions' => 1],
                ],
                [
                    'segments' => ['date' => '2026-08-12'],
                    'adGroupCriterion' => [
                        'criterionId' => '777',
                        'status' => 'PAUSED',
                        'keyword' => ['text' => 'dental', 'matchType' => 'EXACT'],
                    ],
                    'adGroup' => ['id' => '23'],
                    'campaign' => ['id' => '555'],
                    'metrics' => ['impressions' => 4, 'clicks' => 1, 'costMicros' => '500000', 'conversions' => 0],
                ],
            ],
            2,
            173,
        );

        $this->assertCount(2, $normalized['daily']);
        $this->assertCount(2, $normalized['snapshots']);
        $dailyByGroup = [];
        foreach ($normalized['daily'] as $row) {
            $dailyByGroup[(string) $row['ad_group_id']] = $row;
        }
        $this->assertSame(10, $dailyByGroup['22']['impressions']);
        $this->assertSame(4, $dailyByGroup['23']['impressions']);
    }

    #[Test]
    public function keyword_snapshots_skip_rows_without_ad_group_id(): void
    {
        $normalized = (new GoogleAdsNormalizer)->normalizeKeywordSnapshots(
            '1112223333',
            'Europe/Istanbul',
            [[
                'adGroupCriterion' => [
                    'criterionId' => '777',
                    'status' => 'ENABLED',
                    'keyword' => ['text' => 'dental', 'matchType' => 'EXACT'],
                ],
                'campaign' => ['id' => '555'],
            ]],
            2,
            173,
        );

        $this->assertSame([], $normalized);
    }
}
