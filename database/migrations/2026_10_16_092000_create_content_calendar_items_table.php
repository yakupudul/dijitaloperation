<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * İçerik takvimi: planned content per brand. Business Profile posts are published by MoxDOP when approved and due
 * (ADR-073); blog / social / other items are reminders that surface in the command center on their day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_calendar_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('digital_asset_id')->nullable()->constrained('digital_assets')->nullOnDelete();
            $table->string('channel', 16); // gbp_post | blog | social | newsletter | other
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('url', 500)->nullable();
            $table->string('action_type', 16)->nullable();
            $table->timestampTz('scheduled_for');
            $table->string('status', 16)->default('draft'); // draft | approved | published | failed | skipped
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('write_action_id')->nullable()->constrained('external_write_actions')->nullOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->string('external_ref', 300)->nullable();
            $table->string('error', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['status', 'scheduled_for']);
            $table->index(['brand_id', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_calendar_items');
    }
};
