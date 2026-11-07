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

    /** Admin audit 2026-11-07 V5: the hand-paired list merges across languages; a row whose names no longer match is skipped. */
    public function test_a_listed_merge_keeps_the_foreign_name_as_a_locale_alias_and_a_sectorless_survivor_takes_the_sector(): void
    {
        $catalog = app(ServiceCatalogService::class);
        $english = $catalog->resolveOrCreate('Dental Implant Treatment', null)['service'];
        BrandOffering::query()->create(['brand_id' => $this->brand->id, 'service_catalog_item_id' => $english->id, 'status' => 'active', 'priority' => 'support', 'locked' => false]);
        $cbct = $catalog->resolveOrCreate('3 Boyutlu Dental Tomografi (CBCT)', null)['service'];
        $cbctTwin = $catalog->resolveOrCreate('CBCT Tomografi', 'dental')['service'];
        $pageTitle = $catalog->resolveOrCreate('Our Services', null)['service'];
        $renamed = $catalog->resolveOrCreate('Zahnimplantat', null)['service'];
        $migration = require database_path('migrations/2027_01_08_130000_merge_foreign_language_service_names.php');

        $summary = $migration::apply(['merges' => [
            ['from' => $english->id, 'from_label' => 'dental implant treatment', 'into' => $this->implant->id, 'into_label' => 'Diş İmplantı', 'locale' => 'en'],
            ['from' => $cbctTwin->id, 'from_label' => 'CBCT Tomografi', 'into' => $cbct->id, 'into_label' => '3 Boyutlu Dental Tomografi (CBCT)', 'locale' => null],
            ['from' => $renamed->id, 'from_label' => 'Zahnimplantat Behandlung', 'into' => $this->implant->id, 'into_label' => 'Diş İmplantı', 'locale' => 'de'],
            ['from' => 999999, 'from_label' => 'Gone', 'into' => $this->implant->id, 'into_label' => 'Diş İmplantı', 'locale' => 'en'],
        ], 'archive' => [['id' => $pageTitle->id, 'label' => 'Our Services'], ['id' => $this->zirkonyum->id, 'label' => 'Başka Ad']]]);

        $this->assertSame(['merged' => 2, 'archived' => 1, 'skipped' => ['Zahnimplantat Behandlung → Diş İmplantı', 'Gone → Diş İmplantı', 'Başka Ad']], $summary);
        $this->assertSoftDeleted(ServiceCatalogItem::withTrashed()->find($english->id));
        $alias = ServiceCatalogName::query()->where('raw_label', 'Dental Implant Treatment')->first();
        $this->assertSame([$this->implant->id, 'en', false, 'alias'], [(int) $alias->service_catalog_item_id, $alias->locale, $alias->is_primary, $alias->name_kind], 'the English name is a locale alias of the Turkish service');
        $this->assertSame('Diş İmplantı', $this->implant->fresh()->primaryName?->raw_label, 'the Turkish name stays primary');
        $this->assertSame(1, BrandOffering::query()->where('service_catalog_item_id', $this->implant->id)->count(), 'the brand already offered it: no second offering');
        $this->assertSame('dental', $cbct->fresh()->sector, 'a survivor without a sector takes the merged service\'s sector');
        $this->assertNull(ServiceCatalogName::query()->where('raw_label', 'CBCT Tomografi')->value('locale'), 'a Turkish duplicate carries no locale');
        $this->assertSame('archived', $pageTitle->fresh()->status);
        $this->assertSame('active', $renamed->fresh()->status, 'a service whose name changed is left alone');
        $this->assertSame('active', $this->zirkonyum->fresh()->status);
        $this->assertSame(['merged' => 0, 'archived' => 0], array_intersect_key($migration::apply(['merges' => [['from' => $english->id, 'from_label' => 'Dental Implant Treatment', 'into' => $this->implant->id, 'into_label' => 'Diş İmplantı', 'locale' => 'en']]]), ['merged' => 0, 'archived' => 0]), 'a second run changes nothing');
    }

    public function test_the_merge_list_of_the_admin_audit_is_consistent(): void
    {
        $list = json_decode((string) file_get_contents(database_path('data/service-catalog-merges-2026-11-07.json')), true);
        $from = array_column($list['merges'], 'from');
        $into = array_unique(array_column($list['merges'], 'into'));
        $archive = array_column($list['archive'], 'id');

        $this->assertCount(138, $list['merges']);
        $this->assertCount(6, $list['archive']);
        $this->assertSame($from, array_values(array_unique($from)), 'a service merges once');
        $this->assertSame([], array_intersect($from, $into), 'a survivor is never merged away');
        $this->assertSame([], array_intersect($archive, array_merge($from, $into)), 'an archived page title is not in a merge');
        foreach ($list['merges'] as $merge) {
            $this->assertNotSame($merge['from'], $merge['into']);
            $this->assertNotSame('', trim($merge['from_label']));
            $this->assertNotSame('', trim($merge['into_label']));
            $this->assertContains($merge['locale'], ['en', 'de', 'it', null]);
            $this->assertNotSame(ServiceCatalogCleaner::language($merge['into_label'], null), 'en', $merge['into_label'].' survives, so it must read as Turkish');
        }
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
