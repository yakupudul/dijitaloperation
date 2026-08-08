<?php

namespace Tests\Unit;

use MoxDop\Website\Opportunities\GscStrikingDistanceConfig;
use MoxDop\Website\Opportunities\GscStrikingDistanceOpportunities;
use PHPUnit\Framework\TestCase;

class GscStrikingDistanceOpportunitiesTest extends TestCase
{
    public function test_filters_position_band_and_impression_floor_and_bounds_results(): void
    {
        $rows = [];
        for ($i = 0; $i < 30; $i++) {
            $rows[] = [
                'query' => "query-$i",
                'page' => "https://example.com/p$i",
                'position' => 8.0 + ($i * 0.1),
                'impressions' => 100 - $i,
                'clicks' => 5,
                'ctr' => 0.05,
            ];
        }
        $rows[] = [
            'query' => 'too-high',
            'page' => 'https://example.com/a',
            'position' => 3.0,
            'impressions' => 500,
            'clicks' => 50,
            'ctr' => 0.1,
        ];
        $rows[] = [
            'query' => 'low-volume',
            'page' => 'https://example.com/b',
            'position' => 10.0,
            'impressions' => 5,
            'clicks' => 0,
            'ctr' => 0.0,
        ];

        $out = (new GscStrikingDistanceOpportunities)->fromQueryPageRows($rows);

        $this->assertLessThanOrEqual(GscStrikingDistanceConfig::MAX_OPPORTUNITIES, count($out));
        $this->assertNotEmpty($out);
        foreach ($out as $row) {
            $this->assertGreaterThanOrEqual(GscStrikingDistanceConfig::POSITION_MIN, $row['position']);
            $this->assertLessThanOrEqual(GscStrikingDistanceConfig::POSITION_MAX, $row['position']);
            $this->assertGreaterThanOrEqual(GscStrikingDistanceConfig::MINIMUM_IMPRESSIONS, $row['impressions']);
            $this->assertStringEndsWith('%', $row['ctr_label']);
        }
        $this->assertSame('query-0', $out[0]['query']);
        $queries = array_column($out, 'query');
        $this->assertNotContains('too-high', $queries);
        $this->assertNotContains('low-volume', $queries);
    }

    public function test_collapses_query_to_best_page(): void
    {
        $rows = [
            [
                'query' => 'brand',
                'page' => 'https://example.com/weak',
                'position' => 18.0,
                'impressions' => 40,
                'clicks' => 1,
                'ctr' => 0.025,
            ],
            [
                'query' => 'brand',
                'page' => 'https://example.com/strong',
                'position' => 9.0,
                'impressions' => 80,
                'clicks' => 4,
                'ctr' => 0.05,
            ],
        ];

        $out = (new GscStrikingDistanceOpportunities)->fromQueryPageRows($rows);

        $this->assertCount(1, $out);
        $this->assertSame('https://example.com/strong', $out[0]['page']);
        $this->assertSame(9.0, $out[0]['position']);
    }
}
