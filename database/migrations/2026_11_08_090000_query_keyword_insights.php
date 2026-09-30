<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sorgular › Eşleme kelimeleri: why a service line of the Silinecekler pool is proposed (`reason`: conflict = two
 * services' keywords hit the query, sector = the current service belongs to another sector) and the "Kelime önerileri"
 * the operator dismissed per sector ("Yok say").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('query_review_items', function (Blueprint $table): void {
            $table->string('reason', 12)->nullable();
        });
        Schema::create('query_keyword_dismissals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sector_id')->constrained('service_categories')->cascadeOnDelete();
            $table->string('ngram', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['sector_id', 'ngram'], 'query_keyword_dismissals_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('query_keyword_dismissals');
        Schema::table('query_review_items', function (Blueprint $table): void {
            $table->dropColumn('reason');
        });
    }
};
