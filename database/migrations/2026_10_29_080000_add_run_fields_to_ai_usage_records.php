<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 8 "Promptlar": run history per AI operation needs the duration and the status of each call
 * (ok | failed — a provider attempt that failed over). prompt_version_id already exists (Faz 0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_usage_records', 'duration_ms')) {
                $table->unsignedInteger('duration_ms')->nullable();
            }
            if (! Schema::hasColumn('ai_usage_records', 'status')) {
                $table->string('status', 16)->default('ok');
            }
        });
        Schema::table('ai_usage_records', function (Blueprint $table): void {
            $table->index(['prompt_version_id', 'created_at'], 'ai_usage_prompt_version_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_records', function (Blueprint $table): void {
            $table->dropIndex('ai_usage_prompt_version_created_idx');
        });
        Schema::table('ai_usage_records', function (Blueprint $table): void {
            $table->dropColumn(['duration_ms', 'status']);
        });
    }
};
