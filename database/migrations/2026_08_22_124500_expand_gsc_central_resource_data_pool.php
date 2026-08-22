<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $searchTypeTables = [
        'gsc_property_daily',
        'gsc_query_daily',
        'gsc_page_daily',
        'gsc_query_page_daily',
        'gsc_device_daily',
        'gsc_country_daily',
        'gsc_search_appearance_daily',
    ];

    public function up(): void
    {
        foreach ($this->searchTypeTables as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'search_type')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->string('search_type', 32)->default('web');
                });
            }
        }

        $this->createCentralIndexes();
        $this->createSitemapCentralIndex();

        $this->createCrossDimensionTable('gsc_page_device_daily', ['page', 'device'], 'gsc_pg_dev_res_nk');
        $this->createCrossDimensionTable('gsc_page_country_daily', ['page', 'country'], 'gsc_pg_cty_res_nk');
        $this->createCrossDimensionTable('gsc_query_device_daily', ['query', 'device'], 'gsc_q_dev_res_nk');
        $this->createCrossDimensionTable('gsc_query_country_daily', ['query', 'country'], 'gsc_q_cty_res_nk');
        $this->createCrossDimensionTable('gsc_search_appearance_page_daily', ['searchAppearance', 'page'], 'gsc_sa_pg_res_nk');
    }

    public function down(): void
    {
        foreach ([
            'gsc_search_appearance_page_daily',
            'gsc_query_country_daily',
            'gsc_query_device_daily',
            'gsc_page_country_daily',
            'gsc_page_device_daily',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            foreach ([
                'gsc_prop_res_st_nk', 'gsc_prop_res_st_nk_date',
                'gsc_query_res_st_nk', 'gsc_query_res_st_nk_date',
                'gsc_page_res_st_nk', 'gsc_page_res_st_nk_date',
                'gsc_qp_res_st_nk', 'gsc_qp_res_st_nk_date',
                'gsc_dev_res_st_nk', 'gsc_dev_res_st_nk_date',
                'gsc_cty_res_st_nk', 'gsc_cty_res_st_nk_date',
                'gsc_sa_res_st_nk', 'gsc_sa_res_st_nk_date',
                'gsc_smap_res_nk', 'gsc_smap_res_idx',
            ] as $index) {
                DB::statement("DROP INDEX IF EXISTS {$index}");
            }
        }

        foreach ($this->searchTypeTables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'search_type')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->dropColumn('search_type');
                });
            }
        }
    }

    private function createCentralIndexes(): void
    {
        $indexes = [
            ['gsc_property_daily', 'gsc_prop_res_st_nk', ['external_resource_id', 'site_url', 'reporting_date', 'search_type']],
            ['gsc_query_daily', 'gsc_query_res_st_nk', ['external_resource_id', 'site_url', 'reporting_date', 'search_type', 'query']],
            ['gsc_page_daily', 'gsc_page_res_st_nk', ['external_resource_id', 'site_url', 'reporting_date', 'search_type', 'page']],
            ['gsc_query_page_daily', 'gsc_qp_res_st_nk', ['external_resource_id', 'site_url', 'reporting_date', 'search_type', 'query', 'page']],
            ['gsc_device_daily', 'gsc_dev_res_st_nk', ['external_resource_id', 'site_url', 'reporting_date', 'search_type', 'device']],
            ['gsc_country_daily', 'gsc_cty_res_st_nk', ['external_resource_id', 'site_url', 'reporting_date', 'search_type', 'country']],
            ['gsc_search_appearance_daily', 'gsc_sa_res_st_nk', ['external_resource_id', 'site_url', 'reporting_date', 'search_type', 'searchAppearance']],
        ];

        foreach ($indexes as [$table, $name, $columns]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $quoted = implode(', ', array_map(fn (string $column): string => '"'.str_replace('"', '""', $column).'"', $columns));
                DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$name} ON {$table} ({$quoted})");
                DB::statement("CREATE INDEX IF NOT EXISTS {$name}_date ON {$table} (external_resource_id, reporting_date)");

                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
                $blueprint->unique($columns, $name);
                $blueprint->index(['external_resource_id', 'reporting_date'], $name.'_date');
            });
        }
    }

    private function createSitemapCentralIndex(): void
    {
        if (! Schema::hasTable('gsc_sitemap_snapshot')) {
            return;
        }

        $columns = ['external_resource_id', 'site_url', 'sitemap_path', 'retrieved_at'];
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS gsc_smap_res_nk ON gsc_sitemap_snapshot (external_resource_id, site_url, sitemap_path, retrieved_at)');
            DB::statement('CREATE INDEX IF NOT EXISTS gsc_smap_res_idx ON gsc_sitemap_snapshot (external_resource_id)');

            return;
        }

        Schema::table('gsc_sitemap_snapshot', function (Blueprint $blueprint) use ($columns): void {
            $blueprint->unique($columns, 'gsc_smap_res_nk');
            $blueprint->index(['external_resource_id'], 'gsc_smap_res_idx');
        });
    }

    /** @param list<string> $dimensions */
    private function createCrossDimensionTable(string $table, array $dimensions, string $uniqueName): void
    {
        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $blueprint) use ($dimensions, $uniqueName): void {
            $blueprint->id();
            $blueprint->unsignedBigInteger('digital_asset_id')->nullable();
            $blueprint->unsignedBigInteger('external_resource_id');
            $blueprint->text('site_url');
            $blueprint->date('reporting_date');
            $blueprint->string('search_type', 32)->default('web');
            foreach ($dimensions as $dimension) {
                $blueprint->text($dimension);
            }
            $blueprint->bigInteger('clicks')->default(0);
            $blueprint->bigInteger('impressions')->default(0);
            $blueprint->integer('contract_version');
            $blueprint->unsignedBigInteger('last_collection_run_id')->nullable();
            $blueprint->unsignedBigInteger('last_dataset_run_id')->nullable();
            $blueprint->timestampTz('first_collected_at');
            $blueprint->timestampTz('last_collected_at');
            $blueprint->text('source_timezone')->nullable();
            $blueprint->char('record_fingerprint', 64);
            $blueprint->json('metadata')->nullable();
            $blueprint->timestamps();

            $blueprint->unique(
                ['external_resource_id', 'site_url', 'reporting_date', 'search_type', ...$dimensions],
                $uniqueName,
            );
            $blueprint->index(['external_resource_id', 'reporting_date'], $uniqueName.'_date');
        });
    }
};