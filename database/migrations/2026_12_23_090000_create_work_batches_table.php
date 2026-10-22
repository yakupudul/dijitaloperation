<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aşama 4 (2026-10-22): one approval or send the operator started is one row of Akış — a bulk approval of a thousand
 * desk rows, the daily send, "Hepsini siteye gönder". The writes it made point at it; `retried_at` marks a failed write
 * the operator sent again from Akış, so it no longer counts as an open error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 32);
            $table->string('channel', 16)->nullable();
            $table->string('title', 160);
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->unsignedInteger('brand_count')->default(0);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('done')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->json('failures')->nullable();
            $table->unsignedInteger('decision_wait_seconds')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['created_at', 'id'], 'work_batches_created_idx');
            $table->index(['status', 'id'], 'work_batches_status_idx');
        });

        Schema::table('external_write_actions', function (Blueprint $table): void {
            $table->foreignId('work_batch_id')->nullable()->constrained('work_batches')->nullOnDelete();
            $table->timestampTz('retried_at')->nullable();
            $table->index(['work_batch_id', 'status'], 'external_writes_batch_idx');
            $table->index(['created_at', 'id'], 'external_writes_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('external_write_actions', function (Blueprint $table): void {
            $table->dropIndex('external_writes_batch_idx');
            $table->dropIndex('external_writes_created_idx');
            $table->dropConstrainedForeignId('work_batch_id');
            $table->dropColumn('retried_at');
        });
        Schema::dropIfExists('work_batches');
    }
};
