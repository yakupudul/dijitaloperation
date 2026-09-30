<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canlı AI işlemleri: every laravel/ai agent call opens a row (running) and closes it (done / failed) so the operator
 * sees AI work live (header "AI · N", Ayarlar › AI işlemleri › Canlı). Kept 7 days (DataRetentionService telemetry).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_live_operations', function (Blueprint $table): void {
            $table->id();
            $table->string('invocation_id', 64)->nullable();
            $table->string('operation', 120)->nullable();
            $table->string('label', 190);
            $table->string('agent', 190);
            $table->string('status', 16)->default('running');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('subject', 200)->nullable();
            $table->string('error', 300)->nullable();
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('finished_at')->nullable();

            $table->index(['status', 'started_at'], 'ai_live_status_started_idx');
            $table->index(['started_at'], 'ai_live_started_idx');
            $table->index(['invocation_id'], 'ai_live_invocation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_live_operations');
    }
};
