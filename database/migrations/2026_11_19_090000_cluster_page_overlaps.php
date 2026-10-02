<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Other pages of the site that answer the same cluster (AI match "also_page_ids"): the overlap the operator sees. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_cluster_pages', function (Blueprint $table): void {
            $table->json('overlap_page_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('brand_cluster_pages', function (Blueprint $table): void {
            $table->dropColumn('overlap_page_ids');
        });
    }
};
