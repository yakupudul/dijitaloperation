<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brand-level factual intelligence context (operator-owned).
 * One-to-one with brands; optional — Brand may exist without context.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('brand_intelligence_contexts')) {
            return;
        }

        Schema::create('brand_intelligence_contexts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->text('business_summary')->nullable();
            $table->string('business_model', 64)->nullable();
            $table->json('products_services')->nullable();
            $table->json('priority_offerings')->nullable();
            $table->json('target_audiences')->nullable();
            $table->json('target_markets')->nullable();
            $table->json('business_goals')->nullable();
            $table->json('conversion_goals')->nullable();
            $table->text('positioning')->nullable();
            $table->json('differentiators')->nullable();
            $table->json('known_competitors')->nullable();
            $table->text('important_constraints')->nullable();
            $table->string('source', 32)->default('operator');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('brand_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_intelligence_contexts');
    }
};
