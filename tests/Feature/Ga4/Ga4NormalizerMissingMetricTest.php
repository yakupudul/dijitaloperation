<?php

namespace Tests\Feature\Ga4;

use App\Services\Collection\Providers\Ga4\Ga4Normalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Ga4NormalizerMissingMetricTest extends TestCase
{
    #[Test]
    public function missing_or_non_numeric_metric_values_are_null_not_zero(): void
    {
        $records = (new Ga4Normalizer)->normalizeReportRows(
            'ga4_property_daily',
            '123',
            ['date'],
            ['sessions', 'newUsers', 'totalRevenue'],
            [
                'rows' => [
                    ['dimensionValues' => [['value' => '20260801']], 'metricValues' => [['value' => '12'], ['value' => '0'], ['value' => '4.5']]],
                    ['dimensionValues' => [['value' => '20260802']], 'metricValues' => [['value' => '7']]],
                    ['dimensionValues' => [['value' => '20260803']], 'metricValues' => [['value' => '3'], ['value' => 'n/a'], ['value' => '']]],
                ],
            ],
            ['timezone' => 'UTC'],
            null,
            55,
        );

        $this->assertCount(3, $records);

        $this->assertSame(12, $records[0]['sessions']);
        $this->assertSame(0, $records[0]['newUsers']);
        $this->assertSame('4.500000', $records[0]['totalRevenue']);
        $this->assertArrayNotHasKey('missing_metrics', $records[0]['metadata']);

        $this->assertSame(7, $records[1]['sessions']);
        $this->assertNull($records[1]['newUsers']);
        $this->assertNull($records[1]['totalRevenue']);
        $this->assertSame(['newUsers', 'totalRevenue'], $records[1]['metadata']['missing_metrics']);

        $this->assertNull($records[2]['newUsers']);
        $this->assertNull($records[2]['totalRevenue']);
        $this->assertSame(['newUsers', 'totalRevenue'], $records[2]['metadata']['missing_metrics']);
    }
}
