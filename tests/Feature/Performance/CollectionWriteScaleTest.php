<?php

namespace Tests\Feature\Performance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('performance')]
class CollectionWriteScaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_writer_uses_configurable_batch_size(): void
    {
        $batch = (int) config('moxdop-data-pool.default_batch_size', 500);
        $this->assertGreaterThan(0, $batch);
        $this->assertLessThanOrEqual(5_000, $batch);

        $writer = File::get(app_path('Services/DataPool/PostgresWarehouseWriter.php'));
        $this->assertStringContainsString('array_chunk', $writer);
        $this->assertStringContainsString('countExisting', $writer);
        $this->assertStringContainsString('Single bounded OR-query', $writer);
        $this->assertStringNotContainsString('->exists()', $writer);
    }

    public function test_natural_key_indexes_exist_on_hot_fact_tables(): void
    {
        $this->assertTrue(Schema::hasTable('gsc_query_daily'));
        $this->assertTrue(Schema::hasTable('google_ads_search_term_daily'));

        $gscIndexes = collect(Schema::getIndexes('gsc_query_daily'))
            ->pluck('name')
            ->all();
        $adsIndexes = collect(Schema::getIndexes('google_ads_search_term_daily'))
            ->pluck('name')
            ->all();

        $this->assertTrue(
            collect($gscIndexes)->contains(fn (string $n): bool => str_contains($n, 'nk') || str_contains($n, 'unique')),
        );
        $this->assertTrue(
            collect($adsIndexes)->contains(fn (string $n): bool => str_contains($n, 'nk') || str_contains($n, 'unique')),
        );
    }
}
