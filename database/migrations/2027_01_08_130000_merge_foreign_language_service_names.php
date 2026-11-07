<?php

use App\Enums\OfferingStatus;
use App\Models\ServiceCatalogItem;
use App\Services\Catalog\ServiceCatalogCleaner;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Queries\KeywordInsights;
use App\Services\Queries\QueryPipeline;
use App\Services\SeoTasks\SeoText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Hizmet kataloğu (admin denetimi 2026-11-07 V5): 120 English / German / Italian names that multilingual clinic sites
 * created as separate services and 12 second Turkish names merge into their Turkish service; six page titles that are
 * not services are archived. The list is database/data/service-catalog-merges-2026-11-07.json, paired by hand and
 * approved as a whole. Safety: a row applies only when the service with that id still carries the listed primary
 * name, so another database (or a renamed service) is never touched. Reversible per row: Kütüphane › Hizmetler
 * restores an archived service and its alias can be moved back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('service_catalog_items') || ! Schema::hasTable('service_catalog_names')) {
            return;
        }
        $summary = self::apply(json_decode((string) file_get_contents(database_path('data/service-catalog-merges-2026-11-07.json')), true) ?: []);
        Log::info('services.merge-list', $summary);
    }

    /**
     * @param  array{merges?: list<array{from: int, from_label: string, into: int, into_label: string, locale: ?string}>, archive?: list<array{id: int, label: string}>}  $list
     * @return array{merged: int, archived: int, skipped: list<string>}
     */
    public static function apply(array $list): array
    {
        $summary = ['merged' => 0, 'archived' => 0, 'skipped' => []];
        $cleaner = app(ServiceCatalogCleaner::class);
        $catalog = app(ServiceCatalogService::class);
        foreach ($list['merges'] ?? [] as $merge) {
            $from = (int) $merge['from'];
            $into = (int) $merge['into'];
            if (! self::carries($from, (string) $merge['from_label']) || ! self::carries($into, (string) $merge['into_label'])) {
                $summary['skipped'][] = $merge['from_label'].' → '.$merge['into_label'];

                continue;
            }
            $cleaner->merge($from, $into, $merge['locale'] ?? null);
            $summary['merged']++;
        }
        foreach ($list['archive'] ?? [] as $row) {
            $id = (int) $row['id'];
            if (! self::carries($id, (string) $row['label'])) {
                $summary['skipped'][] = (string) $row['label'];

                continue;
            }
            DB::table('brand_offerings')->where('service_catalog_item_id', $id)->where('locked', false)
                ->update(['status' => OfferingStatus::Archived->value, 'updated_at' => now()]);
            $catalog->setStatus(ServiceCatalogItem::query()->findOrFail($id), 'archived');
            $summary['archived']++;
        }
        if ($summary['merged'] + $summary['archived'] > 0) {
            QueryPipeline::releaseServices();
            KeywordInsights::forgetSuggestions();
        }

        return $summary;
    }

    /** The active service with this id still has this primary name (Turkish fold: case and punctuation do not matter). */
    private static function carries(int $id, string $label): bool
    {
        $current = DB::table('service_catalog_items as s')
            ->join('service_catalog_names as n', fn ($join) => $join->on('n.service_catalog_item_id', '=', 's.id')->where('n.is_primary', true)->where('n.is_active', true))
            ->where('s.id', $id)->where('s.status', 'active')->whereNull('s.deleted_at')->value('n.raw_label');

        return $current !== null && SeoText::fold((string) $current) === SeoText::fold($label);
    }

    public function down(): void
    {
        // Data migration: undo per service from Kütüphane › Hizmetler (restore), not by rolling the list back.
    }
};
