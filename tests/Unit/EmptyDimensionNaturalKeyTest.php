<?php

namespace Tests\Unit;

use App\Services\DataPool\PostgresWarehouseWriter;
use App\Services\DataPool\Support\NormalizedDatasetBatch;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * An empty provider value in a text dimension of the natural key (GA4 unknown region / city) is stored as
 * "(empty)" instead of failing the whole batch; other empty keys are still rejected.
 */
final class EmptyDimensionNaturalKeyTest extends TestCase
{
    public function test_empty_text_dimension_is_labelled_and_other_empty_keys_fail(): void
    {
        $writer = app(PostgresWarehouseWriter::class);
        $validate = new ReflectionMethod($writer, 'validateAndNormalizeRecord');
        $batch = new NormalizedDatasetBatch('ga4_geo_city_daily', 1, 1, 'k', []);
        $columns = [
            'property_id' => ['type' => 'text', 'role' => 'key'],
            'city' => ['type' => 'text', 'role' => 'dimension'],
            'landingPage' => ['type' => 'text', 'role' => 'dimension', 'allow_empty_string' => true],
            'sessions' => ['type' => 'integer', 'role' => 'metric'],
        ];
        $call = fn (array $record): array => $validate->invoke($writer, $batch, $record, 0, ['property_id', 'city', 'landingPage'], $columns, array_keys($columns), 'upsert', now());

        $row = $call(['property_id' => '1', 'city' => '', 'landingPage' => '', 'sessions' => 3]);
        $this->assertSame(PostgresWarehouseWriter::EMPTY_DIMENSION, $row['city']);
        $this->assertSame('', $row['landingPage']);
        $this->assertSame('(not set)', $call(['property_id' => '1', 'city' => '(not set)', 'landingPage' => '/', 'sessions' => 1])['city']);

        $this->expectException(InvalidArgumentException::class);
        $call(['property_id' => '', 'city' => 'Ankara', 'landingPage' => '/', 'sessions' => 1]);
    }
}
