<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website SEO market for DataForSEO Labs (country + language).
 * Operators choose human-readable names; stable provider codes are stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('digital_assets', function (Blueprint $table): void {
            if (! Schema::hasColumn('digital_assets', 'seo_market_location_code')) {
                $table->unsignedInteger('seo_market_location_code')->nullable()->after('target_countries');
            }
            if (! Schema::hasColumn('digital_assets', 'seo_market_location_name')) {
                $table->string('seo_market_location_name')->nullable()->after('seo_market_location_code');
            }
            if (! Schema::hasColumn('digital_assets', 'seo_market_language_code')) {
                $table->string('seo_market_language_code', 16)->nullable()->after('seo_market_location_name');
            }
            if (! Schema::hasColumn('digital_assets', 'seo_market_language_name')) {
                $table->string('seo_market_language_name')->nullable()->after('seo_market_language_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('digital_assets', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('digital_assets', 'seo_market_location_code') ? 'seo_market_location_code' : null,
                Schema::hasColumn('digital_assets', 'seo_market_location_name') ? 'seo_market_location_name' : null,
                Schema::hasColumn('digital_assets', 'seo_market_language_code') ? 'seo_market_language_code' : null,
                Schema::hasColumn('digital_assets', 'seo_market_language_name') ? 'seo_market_language_name' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
