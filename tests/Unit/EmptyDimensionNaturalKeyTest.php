<?php

namespace Tests\Unit;

use App\Services\DataPool\DataPoolStorageRegistry;
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

    public function test_ga4_campaign_name_is_a_dimension_so_an_empty_name_does_not_stop_the_write(): void
    {
        $registry = app(DataPoolStorageRegistry::class);
        foreach (['ga4_campaign_daily', 'ga4_event_campaign_daily'] as $dataset) {
            $columns = collect($registry->physicalDataset($dataset)['columns'])->keyBy('name');
            $this->assertSame('dimension', $columns['sessionCampaignName']['role'] ?? null, $dataset);
        }

        $writer = app(PostgresWarehouseWriter::class);
        $validate = new ReflectionMethod($writer, 'validateAndNormalizeRecord');
        $physical = $registry->physicalDataset('ga4_campaign_daily');
        $columnMap = collect($physical['columns'])->keyBy('name')->all();
        $batch = new NormalizedDatasetBatch('ga4_campaign_daily', 1, 1, 'k', []);
        $record = ['external_resource_id' => 'r', 'property_id' => '1', 'reporting_date' => '2026-11-12', 'sessionCampaignId' => '(not set)',
            'sessionCampaignName' => '', 'sessionSource' => 'google', 'sessionMedium' => 'cpc', 'sessions' => 2];
        foreach ($columnMap as $name => $definition) {
            if (! array_key_exists($name, $record) && ! ($definition['nullable'] ?? false) && ! array_key_exists('default', $definition)) {
                $record[$name] = str_contains(strtolower($name), 'date') ? '2026-11-12' : '1';
            }
        }
        $row = $validate->invoke($writer, $batch, $record, 4, $physical['natural_key'], $columnMap, array_keys($columnMap), 'upsert', now());
        $this->assertSame(PostgresWarehouseWriter::EMPTY_DIMENSION, $row['sessionCampaignName']);
    }
}
