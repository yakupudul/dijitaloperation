<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ga4_landing_page_daily')
            || ! Schema::hasColumn('ga4_landing_page_daily', 'external_resource_id')
            || ! Schema::hasColumn('ga4_landing_page_daily', 'property_id')
            || ! Schema::hasColumn('ga4_landing_page_daily', 'reporting_date')
            || ! Schema::hasColumn('ga4_landing_page_daily', 'landingPage')) {
            return;
        }

        $index = 'ga4_landing_page_daily_resource_landing_nk_unique';
        if (Schema::hasIndex('ga4_landing_page_daily', $index)) {
            return;
        }

        $this->dedupeResourceLandingKey();

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS "'.$index.'" '
                .'ON "ga4_landing_page_daily" '
                .'("external_resource_id", "property_id", "reporting_date", "landingPage")'
            );

            return;
        }

        try {
            Schema::table('ga4_landing_page_daily', function ($table) use ($index): void {
                $table->unique(
                    ['external_resource_id', 'property_id', 'reporting_date', 'landingPage'],
                    $index,
                );
            });
        } catch (Throwable) {
            // Existing equivalent index is acceptable on disposable/test databases.
        }
    }

    /**
     * Rows of a GA4 resource rebound between Digital Assets differ only by digital_asset_id
     * and would abort the resource-only unique index. Keep the most recently collected row
     * per (resource, property, date, landingPage); rows with a NULL key column cannot
     * collide and are left untouched. Idempotent.
     */
    private function dedupeResourceLandingKey(): void
    {
        DB::statement(
            'DELETE FROM "ga4_landing_page_daily" WHERE id IN ('
            .'SELECT id FROM ('
            .'SELECT id, ROW_NUMBER() OVER (PARTITION BY "external_resource_id", "property_id", "reporting_date", "landingPage" '
            .'ORDER BY last_collected_at DESC, id DESC) AS natural_key_rank '
            .'FROM "ga4_landing_page_daily" '
            .'WHERE "external_resource_id" IS NOT NULL AND "property_id" IS NOT NULL AND "reporting_date" IS NOT NULL AND "landingPage" IS NOT NULL'
            .') ranked WHERE natural_key_rank > 1)'
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('ga4_landing_page_daily')) {
            return;
        }

        $index = 'ga4_landing_page_daily_resource_landing_nk_unique';

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS "'.$index.'"');

            return;
        }

        try {
            Schema::table('ga4_landing_page_daily', function ($table) use ($index): void {
                $table->dropUnique($index);
            });
        } catch (Throwable) {
            // No-op when the index is already absent.
        }
    }
};
