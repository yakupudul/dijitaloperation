<?php

namespace App\Services\DataCenter;

use App\Services\DataPool\Compact\CompactFactStore;
use App\Services\DataPool\DataPoolStorageRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * What the data center knows about stored data sets: where a data set lives, which column ties its rows to a
 * source (an external account or a website asset), and whether it is protected (queries, search terms, keywords).
 */
final class DataCenterCatalog
{
    /** Pseudo data set: raw provider payloads and HTML copies of a source. */
    public const RAW = 'raw_payloads';

    /**
     * Tables written outside the data-pool registry: table => [key column, source kind (resource|asset), label].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const EXTRA_TABLES = [
        'website_cms_object_snapshot' => ['digital_asset_id', 'asset', 'WordPress içerikleri (sayfa/yazı/görsel alt metni)'],
        'website_cms_seo_snapshot' => ['digital_asset_id', 'asset', 'WordPress SEO alanları'],
        'website_cms_site_snapshot' => ['digital_asset_id', 'asset', 'WordPress site bilgisi'],
        'website_cms_extension_snapshot' => ['digital_asset_id', 'asset', 'WordPress eklenti/tema listesi'],
        'website_cms_taxonomy_snapshot' => ['digital_asset_id', 'asset', 'WordPress kategori/etiketleri'],
        'website_html_snapshot' => ['digital_asset_id', 'asset', 'Sayfa HTML kopyaları'],
        'website_link_edge' => ['digital_asset_id', 'asset', 'İç/dış bağlantılar'],
        'website_crawl_issue_snapshot' => ['digital_asset_id', 'asset', 'Tarama sorunları'],
        'website_sitemap_watch' => ['digital_asset_id', 'asset', 'Sitemap takibi'],
        'gbp_location_snapshots' => ['external_resource_id', 'resource', 'İşletme bilgisi'],
        'gbp_performance_daily' => ['external_resource_id', 'resource', 'İşletme performansı (günlük)'],
        'gbp_search_keywords_monthly' => ['external_resource_id', 'resource', 'İşletme arama terimleri (aylık)'],
        'gbp_reviews' => ['external_resource_id', 'resource', 'Yorumlar'],
        'gbp_media' => ['external_resource_id', 'resource', 'İşletme medyası (liste)'],
        'gbp_posts' => ['external_resource_id', 'resource', 'Gönderiler'],
        'gbp_attribute_snapshots' => ['external_resource_id', 'resource', 'Özellikler'],
        'gbp_service_snapshots' => ['external_resource_id', 'resource', 'Hizmet listesi'],
        'gbp_place_action_links' => ['external_resource_id', 'resource', 'Eylem bağlantıları'],
        'gbp_verification_snapshots' => ['external_resource_id', 'resource', 'Doğrulama durumu'],
    ];

    /** @var array<string, string> */
    private const PREFIX_LABELS = [
        'gsc_' => 'Search Console', 'ga4_' => 'GA4', 'google_ads_' => 'Google Ads', 'meta_' => 'Meta', 'website_' => 'Web sitesi',
        'dataforseo_' => 'DataForSEO', 'gbp_' => 'İşletme Profili',
    ];

    public function __construct(
        private readonly DataPoolStorageRegistry $registry,
        private readonly CompactFactStore $compact,
    ) {}

    public function isProtected(string $dataset): bool
    {
        return in_array($dataset, (array) config('moxdop-retention.protected_tables', []), true)
            || in_array($this->table($dataset), (array) config('moxdop-retention.protected_tables', []), true);
    }

    public function table(string $dataset): string
    {
        if ($dataset === self::RAW || isset(self::EXTRA_TABLES[$dataset])) {
            return $dataset;
        }
        try {
            return $this->registry->tableName($dataset);
        } catch (Throwable) {
            return $dataset;
        }
    }

    public function label(string $dataset): string
    {
        if ($dataset === self::RAW) {
            return 'Ham yanıtlar ve HTML dosyaları';
        }
        if (isset(self::EXTRA_TABLES[$dataset])) {
            return self::EXTRA_TABLES[$dataset][2];
        }
        $name = $dataset;
        foreach (array_keys(self::PREFIX_LABELS) as $prefix) {
            if (str_starts_with($name, $prefix)) {
                $name = substr($name, strlen($prefix));
                break;
            }
        }
        $name = str_replace(['_daily', '_snapshot', '_monthly', '_'], [' (günlük)', ' (anlık)', ' (aylık)', ' '], $name);

        return ucfirst(trim($name));
    }

    public function provider(string $dataset): string
    {
        foreach (self::PREFIX_LABELS as $prefix => $label) {
            if (str_starts_with($dataset, $prefix)) {
                return $label;
            }
        }

        return 'Diğer';
    }

    /**
     * Deletes one data set's rows of one source. Compact PostgreSQL tables are views: rows are deleted from the fact
     * table. Returns the number of rows deleted.
     */
    public function deleteRows(string $dataset, string $kind, int $id): int
    {
        $table = $this->table($dataset);
        if ($this->compact->isCompact($table)) {
            $spec = (array) $this->compact->spec($table);
            $column = $kind === 'resource' ? ($spec['generic'] ? 'external_resource_id' : 'resource_id') : 'digital_asset_id';

            return DB::table((string) $spec['fact'])->where($column, $id)->delete();
        }
        if (! Schema::hasTable($table)) {
            return 0;
        }
        $column = isset(self::EXTRA_TABLES[$dataset]) ? self::EXTRA_TABLES[$dataset][0] : ($kind === 'resource' ? 'external_resource_id' : 'digital_asset_id');
        if (! Schema::hasColumn($table, $column)) {
            return 0;
        }
        $query = DB::table($table)->where($column, $id);
        // A website asset's own rows only; rows collected through an account belong to that account's source.
        if ($kind === 'asset' && ! isset(self::EXTRA_TABLES[$dataset]) && Schema::hasColumn($table, 'external_resource_id')) {
            $query->whereNull('external_resource_id');
        }

        return $query->delete();
    }
}
