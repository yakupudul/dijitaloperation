<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A crawled page replaces its link edges (latest state): the delete looks them up by page.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('website_link_edge') || Schema::hasIndex('website_link_edge', 'website_link_edge_asset_source_idx')) {
            return;
        }

        Schema::table('website_link_edge', function (Blueprint $table): void {
            $table->index(['digital_asset_id', 'source_url'], 'website_link_edge_asset_source_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('website_link_edge') || ! Schema::hasIndex('website_link_edge', 'website_link_edge_asset_source_idx')) {
            return;
        }

        Schema::table('website_link_edge', function (Blueprint $table): void {
            $table->dropIndex('website_link_edge_asset_source_idx');
        });
    }
};
