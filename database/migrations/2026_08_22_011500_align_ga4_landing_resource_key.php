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
        } catch (\Throwable) {
            // Existing equivalent index is acceptable on disposable/test databases.
        }
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
        } catch (\Throwable) {
            // No-op when the index is already absent.
        }
    }
};
