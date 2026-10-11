<?php

namespace Tests\Feature\Queries;

use App\Models\BrandOffering;
use App\Models\Query;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCatalogName;
use App\Models\ServiceCategory;
use App\Services\Catalog\ServiceCatalogCleaner;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Queries\QueryNormalizer;
use Tests\Feature\Site\SiteTestCase;

/** moxdop:services:clean: junk names archived (operator-made kept), same-sector same-language duplicates merged. */
final class ServiceCatalogCleanTest extends SiteTestCase
{
    public function test_junk_is_archived_duplicates_merge_into_the_most_used_service_and_a_second_run_changes_nothing(): void
    {
        $catalog = app(ServiceCatalogService::class);
        $junk = $catalog->resolveOrCreate('Ekimi', 'dental')['service'];
        $operatorJunk = $catalog->resolveOrCreate('Hizmetlerimiz', 'dental', actor: $this->admin)['service'];
        $duplicate = $catalog->resolveOrCreate('Diş İmplantı Tedavisi', 'dental')['service'];
        $english = $catalog->resolveOrCreate('Dental Implant Treatment', 'dental')['service'];
        $hair = ServiceCategory::query()->firstOrCreate(['code' => 'hair'], ['name' => 'Saç', 'normalized_key' => 'sac']);
        $otherSector = $catalog->resolveOrCreate('Diş İmplantı Tedavisi', 'hair')['service'];
        app(ServiceKeywordService::class)->replace($duplicate, 'implant vida');
        app(ServiceKeywordService::class)->replace($this->implant, 'implant');
        $junkQuery = $this->libraryQuery('saç ekimi fiyat', $junk->id);
        $moved = $this->libraryQuery('implant vidası', $duplicate->id);
        $cluster = $this->cluster($duplicate, 'Vida', ['implant vida çeşitleri']);

        $this->artisan('moxdop:services:clean', ['--dry-run' => true])->expectsOutputToContain('[deneme]')->assertSuccessful();
        $this->assertSame('active', $junk->fresh()->status, 'dry run changes nothing');

        $this->artisan('moxdop:services:clean')->expectsOutputToContain('1 arşivlendi · 1 korundu')->assertSuccessful();

        $this->assertSame('archived', $junk->fresh()->status);
        $this->assertNull($junkQuery->fresh()->service_id, 'its query goes back to triage');
        $this->assertSame('active', $operatorJunk->fresh()->status, 'operator-made names are kept');
        $this->assertSoftDeleted($duplicate->fresh() ?? ServiceCatalogItem::withTrashed()->find($duplicate->id));
        $this->assertSame($this->implant->id, (int) $moved->fresh()->service_id);
        $this->assertSame($this->implant->id, (int) $cluster->fresh()->service_id);
        $this->assertSame(['implant', 'implant vida'], $this->implant->matchingKeywords()->orderBy('normalized_key')->pluck('normalized_key')->all());
        $this->assertSame($this->implant->id, (int) ServiceCatalogName::query()->where('raw_label', 'Diş İmplantı Tedavisi')->where('sector_scope', '')->value('service_catalog_item_id'), 'the old label is an alias');
        $this->assertSame('active', $english->fresh()->status, 'no TR↔EN merge');
        $this->assertSame('active', $otherSector->fresh()->status, 'no cross-sector merge');
        $this->assertSame(1, BrandOffering::query()->where('service_catalog_item_id', $this->implant->id)->count());

        $this->assertSame(['archived' => 0, 'merged' => 0], array_intersect_key(app(ServiceCatalogCleaner::class)->clean(), ['archived' => 0, 'merged' => 0]), 'idempotent');
    }

    public function test_fold_key_and_language(): void
    {
        $this->assertSame('implant', ServiceCatalogCleaner::foldKey('İmplant Tedavisi'));
        $this->assertSame('implant', ServiceCatalogCleaner::foldKey('implant-tedavisi'));
        $this->assertSame('sacekimi', ServiceCatalogCleaner::foldKey('Saç Ekimi'));
        $this->assertSame('tedavisi', ServiceCatalogCleaner::foldKey('Tedavisi'), 'nothing would stay: kept');
        $this->assertSame('en', ServiceCatalogCleaner::language('Implant Treatment', null));
        $this->assertSame('tr', ServiceCatalogCleaner::language('İmplant Tedavisi', null));
        $this->assertSame('en', ServiceCatalogCleaner::language('İmplant', 'en'));
    }

    private function libraryQuery(string $text, int $serviceId): Query
    {
        return Query::query()->create(['text' => $text, 'text_hash' => QueryNormalizer::hash($text), 'sector_id' => $this->dental->id,
            'service_id' => $serviceId, 'assignment' => 'rule', 'ai_checked_at' => now()]);
    }
}
