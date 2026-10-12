<?php

namespace Tests\Feature\Catalog;

use App\Models\ServiceCatalogName;
use App\Models\ServiceCategory;
use App\Services\Catalog\ServiceCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** A service name is found only within its sector (or uncategorized); another sector gets its own service. */
final class ServiceCatalogSectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş', 'normalized_key' => 'dis']);
        ServiceCategory::query()->firstOrCreate(['code' => 'aesthetic'], ['name' => 'Estetik', 'normalized_key' => 'estetik']);
    }

    public function test_a_same_name_service_of_another_sector_is_not_returned_a_new_one_is_created(): void
    {
        $catalog = app(ServiceCatalogService::class);
        $dental = $catalog->resolveOrCreate('Botoks', 'dental')['service'];

        $aesthetic = $catalog->resolveOrCreate('Botoks', 'aesthetic');

        $this->assertTrue($aesthetic['created']);
        $this->assertNotSame($dental->id, $aesthetic['service']->id);
        $this->assertSame('aesthetic', $aesthetic['service']->sector);
        $this->assertSame(['', 'aesthetic'], ServiceCatalogName::query()->orderBy('id')->pluck('sector_scope')->all());
        // Each sector finds its own; a third ask does not create again.
        $this->assertSame($dental->id, $catalog->resolveOrCreate('botoks', 'dental')['service']->id);
        $this->assertSame($aesthetic['service']->id, $catalog->resolveOrCreate('BOTOKS', 'aesthetic')['service']->id);
        $this->assertFalse($catalog->resolveOrCreate('Botoks', 'aesthetic')['created']);
        // Without a sector any service of the name is found.
        $this->assertFalse($catalog->resolveOrCreate('Botoks')['created']);
    }

    public function test_an_uncategorized_service_is_reused_by_any_sector_and_a_deleted_one_still_blocks(): void
    {
        $catalog = app(ServiceCatalogService::class);
        $loose = $catalog->resolveOrCreate('Lazer Epilasyon')['service'];

        $this->assertSame($loose->id, $catalog->resolveOrCreate('Lazer Epilasyon', 'aesthetic')['service']->id);

        $gone = $catalog->resolveOrCreate('Dolgu', 'dental')['service'];
        $catalog->delete($gone);
        $this->expectException(ValidationException::class);
        $catalog->resolveOrCreate('Dolgu', 'dental');
    }

    public function test_renaming_a_sector_scoped_service_keeps_its_own_name(): void
    {
        $catalog = app(ServiceCatalogService::class);
        $catalog->resolveOrCreate('Botoks', 'dental');
        $aesthetic = $catalog->resolveOrCreate('Botoks', 'aesthetic')['service'];

        $updated = $catalog->update($aesthetic, 'Botoks', 'aesthetic', 'Kırışıklık tedavisi');

        $this->assertSame('Kırışıklık tedavisi', $updated->description);
    }
}
