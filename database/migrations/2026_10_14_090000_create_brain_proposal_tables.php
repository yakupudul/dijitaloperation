<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service Brain, phase 1: one review queue for everything the system or AI prepares (account mappings, query →
 * service assignments, matching expressions, clusters, …) and a cache of text embeddings.
 * A proposal never changes anything by itself; an operator approves it (one by one or in bulk) and only then the
 * system applies it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brain_proposals', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 48)->index();
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('brand_id')->nullable()->index();
            $table->string('title', 500);
            $table->json('current')->nullable();
            $table->json('proposed');
            $table->text('reason')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('source', 16);
            $table->string('status', 16)->default('pending')->index();
            $table->string('fingerprint', 64);
            $table->foreignId('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
            $table->index(['kind', 'status', 'confidence']);
            $table->index(['fingerprint', 'status']);
        });

        Schema::create('brain_embeddings', function (Blueprint $table): void {
            $table->id();
            $table->string('text_hash', 64);
            $table->string('model', 120);
            $table->unsignedSmallInteger('dimensions');
            $table->longText('vector');
            $table->timestamp('created_at')->nullable();
            $table->unique(['text_hash', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brain_embeddings');
        Schema::dropIfExists('brain_proposals');
    }
};
