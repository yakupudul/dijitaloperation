<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 7 (ADR-064): the only writes MoxDOP makes to external systems, each one approved by an Admin click,
 * executed on the queue, fully recorded (what was sent, what came back) and undoable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_write_actions', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 24); // google_ads | wordpress
            $table->string('action', 32); // negative_list_add | draft_create
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('advisor_item_id')->nullable()->constrained('advisor_items')->nullOnDelete();
            $table->foreignId('seo_task_id')->nullable()->constrained('seo_tasks')->nullOnDelete();
            $table->string('status', 16)->default('queued'); // queued|running|succeeded|partial|failed|undoing|undone|undo_failed
            $table->json('request_payload');
            $table->json('result')->nullable(); // provider ids needed for undo, counts, links
            $table->text('error')->nullable();
            $table->foreignId('requested_by')->constrained('users');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('undone_at')->nullable();
            $table->timestampsTz();

            $table->index(['advisor_item_id', 'id'], 'external_writes_item_idx');
            $table->index(['seo_task_id', 'id'], 'external_writes_task_idx');
            $table->index(['status', 'id'], 'external_writes_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_write_actions');
    }
};
