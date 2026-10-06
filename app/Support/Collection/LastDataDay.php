<?php

namespace App\Support\Collection;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Last day with data of a provider account / property: the newest reporting_date in the account-grain Data Pool fact
 * table of its type. Never `resource_automations.data_through`: that is only the date range of the last fully
 * successful central run (Business Profile never writes it, one failing dataset keeps it old, and it is the earliest
 * end of the run's data families). Shared by the brand file (Marka dosyası) and `moxdop:diagnose`.
 *
 * Resource-first collectors store the provider resource as the row identity (`external_resource_id`), so a batch
 * costs one grouped query per fact table touched; a table without that column is skipped (no rows, not an error).
 */
final class LastDataDay
{
    /**
     * Account / property grain fact table per resource type (core_external_resources.resource_type).
     *
     * @var array<string, string>
     */
    public const array FACT_TABLES = [
        'ga4' => 'ga4_property_daily',
        'search_console' => 'gsc_property_daily',
        'google_ads' => 'google_ads_account_daily',
        'meta_ads' => 'meta_account_daily',
        'meta_ad_account' => 'meta_account_daily',
        'google_business_profile' => 'gbp_performance_daily',
    ];

    /** Resources per grouped query (keeps the IN list bounded). */
    private const int CHUNK = 500;

    /** @var array<string, bool> */
    private static array $readable = [];

    /** The fact table that holds the resource type's last data day, or null when the type has none. */
    public static function table(string $resourceType): ?string
    {
        return self::FACT_TABLES[$resourceType] ?? null;
    }

    /** Last data day (Y-m-d) of one resource, or null when it has no rows. */
    public static function forResource(int $resourceId, string $resourceType): ?string
    {
        return self::forResources([$resourceId => $resourceType])[$resourceId] ?? null;
    }

    /**
     * Last data day per resource, in one grouped query per fact table.
     *
     * @param  array<int, string>  $resourceTypes  external resource id => resource type
     * @return array<int, string> external resource id => Y-m-d; resources without rows (or without a fact table) are left out
     */
    public static function forResources(array $resourceTypes): array
    {
        $byTable = [];
        foreach ($resourceTypes as $resourceId => $resourceType) {
            $table = self::table((string) $resourceType);
            if ($table !== null && (int) $resourceId > 0) {
                $byTable[$table][(int) $resourceId] = true;
            }
        }
        $out = [];
        foreach ($byTable as $table => $ids) {
            if (! self::readable($table)) {
                continue;
            }
            foreach (array_chunk(array_keys($ids), self::CHUNK) as $chunk) {
                DB::table($table)->whereIn('external_resource_id', $chunk)->groupBy('external_resource_id')
                    ->selectRaw('external_resource_id, max(reporting_date) as last_date')
                    ->get()
                    ->each(function (object $row) use (&$out): void {
                        $date = substr((string) $row->last_date, 0, 10);
                        if ($date !== '') {
                            $out[(int) $row->external_resource_id] = $date;
                        }
                    });
            }
        }

        return $out;
    }

    private static function readable(string $table): bool
    {
        return self::$readable[$table] ??= Schema::hasTable($table) && Schema::hasColumn($table, 'external_resource_id');
    }
}
