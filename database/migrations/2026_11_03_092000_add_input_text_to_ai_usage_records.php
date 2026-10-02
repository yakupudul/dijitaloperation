<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 8 "Örnekte dene": the rendered input (data message) of each registry-prompted AI run is kept 90 days
 * (DataRetentionService clears older ones), so a draft prompt can be tried on a real past input.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_usage_records') && ! Schema::hasColumn('ai_usage_records', 'input_text')) {
            Schema::table('ai_usage_records', function (Blueprint $table): void {
                $table->text('input_text')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ai_usage_records', 'input_text')) {
            Schema::table('ai_usage_records', fn (Blueprint $table) => $table->dropColumn('input_text'));
        }
    }
};
