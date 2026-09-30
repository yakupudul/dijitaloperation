<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sorgu kural motoru (`QueryRuleEngine`, kurallar `config/moxdop-query-rules.php`): each query's variant key (queries
 * that mean the same: "implant" = "diş implantı" = "dis implant"), topic key (the variant key without facet words:
 * "implant fiyatları" → topic "implant", facet "fiyat") and whether it heads its variant group (most impressions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queries', function (Blueprint $table): void {
            $table->string('variant_key', 500)->nullable();
            $table->string('topic_key', 500)->nullable();
            $table->string('facets', 200)->nullable();
            $table->boolean('variant_head')->default(true);
            $table->index(['sector_id', 'variant_key'], 'queries_sector_variant_idx');
            $table->index(['sector_id', 'topic_key'], 'queries_sector_topic_idx');
        });
    }

    public function down(): void
    {
        Schema::table('queries', function (Blueprint $table): void {
            $table->dropIndex('queries_sector_variant_idx');
            $table->dropIndex('queries_sector_topic_idx');
            $table->dropColumn(['variant_key', 'topic_key', 'facets', 'variant_head']);
        });
    }
};
