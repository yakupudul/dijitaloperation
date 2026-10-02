<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Üretim Arşivi: every AI output the operator asked for, as an append-only version history (gold data, never
 * deleted). Regenerating adds a version instead of losing the previous one; the operator marks versions as
 * used / published and rates them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_productions', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 60);
            $table->string('subject_type', 60);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('digital_asset_id')->nullable();
            $table->unsignedInteger('version');
            $table->string('title', 255)->nullable();
            $table->json('content');
            $table->char('content_hash', 64);
            $table->string('provider', 60)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('prompt_version', 60)->nullable();
            $table->string('status', 20)->default('new');
            $table->smallInteger('rating')->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('status_changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'subject_type', 'subject_id', 'content_hash'], 'ai_productions_subject_content_unique');
            $table->index(['subject_type', 'subject_id', 'version']);
            $table->index(['brand_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_productions');
    }
};
