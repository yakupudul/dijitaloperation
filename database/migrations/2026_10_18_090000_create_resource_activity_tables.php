<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activity-aware collection: one row per collected provider account/property with its activity tier
 * (active / idle / dormant) computed from stored facts, the operator pause flag ("Duraklatıldı (müşteri kararı)")
 * and the timestamps the planner needs (weekly light check, last full collection, resume backfill start).
 * collection_activity_passes logs each planning pass so the operator can see how many dataset requests were avoided.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_activity', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_resource_id')->unique()->constrained('core_external_resources')->cascadeOnDelete();
            $table->string('provider', 24);
            $table->string('tier', 12)->default('active');
            $table->date('last_active_on')->nullable();
            $table->timestampTz('tier_since')->nullable();
            $table->timestampTz('operator_paused_at')->nullable();
            $table->foreignId('operator_paused_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('last_light_check_at')->nullable();
            $table->timestampTz('last_full_collection_at')->nullable();
            $table->date('backfill_from')->nullable();
            $table->timestampTz('last_deep_restatement_at')->nullable();
            $table->timestampTz('refreshed_at')->nullable();
            $table->timestampsTz();

            $table->index(['provider', 'tier'], 'resource_activity_provider_tier_idx');
        });

        Schema::create('collection_activity_passes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_resource_id')->nullable()->constrained('core_external_resources')->nullOnDelete();
            $table->string('provider', 24);
            $table->string('tier', 12);
            $table->string('mode', 12);
            $table->unsignedInteger('planned_datasets')->default(0);
            $table->unsignedInteger('skipped_datasets')->default(0);
            $table->json('detail')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index('created_at', 'collection_activity_passes_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_activity_passes');
        Schema::dropIfExists('resource_activity');
    }
};
