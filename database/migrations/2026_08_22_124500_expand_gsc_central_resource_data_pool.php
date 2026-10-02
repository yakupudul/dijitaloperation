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

        // SQLite cannot drop a column that an index still references, so the
        // central indexes are dropped on every driver before search_type goes.
        if (in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
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

            if (Schema::hasIndex($table, $name)) {
                continue;
            }

            $this->dedupeResourceNaturalKey($table, $columns);

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

    /**
     * Legacy asset-bound collection keyed facts by digital_asset_id, so the same property
     * collected for two Digital Assets left two rows per resource-first natural key once
     * search_type was backfilled to 'web'. Keep the most recently collected row (newest id
     * on ties) so the resource-first unique index can be created. Idempotent: a table
     * without duplicates deletes nothing.
     *
     * @param  list<string>  $columns
     */
    private function dedupeResourceNaturalKey(string $table, array $columns): void
    {
        $partition = implode(', ', array_map(
            static fn (string $column): string => '"'.str_replace('"', '""', $column).'"',
            $columns,
        ));

        // A NULL key column never collides in a unique index, so those rows are left alone.
        $notNull = implode(' AND ', array_map(
            static fn (string $column): string => '"'.str_replace('"', '""', $column).'" IS NOT NULL',
            $columns,
        ));

        DB::statement(
            "DELETE FROM {$table} WHERE id IN ("
            .'SELECT id FROM ('
            ."SELECT id, ROW_NUMBER() OVER (PARTITION BY {$partition} "
            .'ORDER BY CASE WHEN last_collected_at IS NULL THEN 1 ELSE 0 END, last_collected_at DESC, id DESC) AS natural_key_rank '
            ."FROM {$table} WHERE {$notNull}"
            .') ranked WHERE natural_key_rank > 1)'
        );
    }

    private function createSitemapCentralIndex(): void
    {
        if (! Schema::hasTable('gsc_sitemap_snapshot') || Schema::hasIndex('gsc_sitemap_snapshot', 'gsc_smap_res_nk')) {
            return;
        }

        $columns = ['external_resource_id', 'site_url', 'sitemap_path', 'retrieved_at'];
        $this->dedupeResourceNaturalKey('gsc_sitemap_snapshot', $columns);
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
