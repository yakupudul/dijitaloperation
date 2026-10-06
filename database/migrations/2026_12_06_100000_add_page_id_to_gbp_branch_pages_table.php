<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Şube sayfaları: the operator can point a profile at a page the site already has (when the automatic match missed it).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('gbp_branch_pages', 'page_id')) {
            Schema::table('gbp_branch_pages', function (Blueprint $table): void {
                $table->unsignedBigInteger('page_id')->nullable()->after('website_asset_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('gbp_branch_pages', 'page_id')) {
            Schema::table('gbp_branch_pages', function (Blueprint $table): void {
                $table->dropColumn('page_id');
            });
        }
    }
};
