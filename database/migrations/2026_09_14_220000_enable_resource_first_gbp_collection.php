<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['runs', 'gbp_location_snapshots', 'gbp_performance_daily', 'gbp_search_keywords_monthly', 'gbp_reviews', 'gbp_media', 'gbp_posts', 'gbp_attribute_snapshots', 'gbp_service_snapshots', 'gbp_place_action_links', 'gbp_verification_snapshots'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('digital_asset_id')->nullable()->change();
            });
        }
        Schema::table('resource_automations', function (Blueprint $table): void {
            $table->unsignedBigInteger('gbp_run_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        // Preserve resource-first data; null asset ownership cannot be safely reversed.
        Schema::table('resource_automations', function (Blueprint $table): void {
            $table->dropColumn('gbp_run_id');
        });
    }
};
