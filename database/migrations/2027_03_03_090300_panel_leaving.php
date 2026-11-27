<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The day the operator started a brand's leave: from then on its panel is read only and, while a balance is open, nothing is exported. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('panel_brand_settings') && ! Schema::hasColumn('panel_brand_settings', 'leaving_at')) {
            Schema::table('panel_brand_settings', function (Blueprint $table): void {
                $table->timestamp('leaving_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('panel_brand_settings') && Schema::hasColumn('panel_brand_settings', 'leaving_at')) {
            Schema::table('panel_brand_settings', function (Blueprint $table): void {
                $table->dropColumn('leaving_at');
            });
        }
    }
};
