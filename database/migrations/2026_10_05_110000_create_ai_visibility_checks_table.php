<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 10c: AI visibility — does an AI assistant name the brand when asked a customer-style question about its
 * services and areas? One row per question per check (run on click).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_visibility_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->uuid('batch');
            $table->string('prompt', 300);
            $table->string('status', 16)->default('queued');
            $table->text('answer')->nullable();
            $table->json('businesses')->nullable();
            $table->boolean('mentioned')->nullable();
            $table->unsignedTinyInteger('position')->nullable();
            $table->json('competitors_mentioned')->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('model', 120)->nullable();
            $table->text('error')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('checked_at')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'created_at']);
            $table->index('batch');
        });
        Schema::table('brand_intel_settings', function (Blueprint $table): void {
            $table->json('ai_visibility_prompts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('brand_intel_settings', function (Blueprint $table): void {
            $table->dropColumn('ai_visibility_prompts');
        });
        Schema::dropIfExists('ai_visibility_checks');
    }
};
