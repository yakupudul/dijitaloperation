<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authoritative per-account import state for Meta history imports.
 *
 * One row per discovered Meta Ad Account (CoreExternalResource) under an Integration.
 * This is the single source of truth for account-level import progress — Run metadata
 * counts have historically drifted, so the operator-visible "N / M accounts ready"
 * label and parent Run status are derived from these rows, never from stale metadata.
 *
 * Never invents accounts: rows exist only for resources actually discovered as
 * available meta_ads CoreExternalResources for the Integration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ads_account_import_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('core_integration_id')->constrained('core_integrations')->cascadeOnDelete();
            $table->foreignId('core_external_resource_id')->constrained('core_external_resources')->cascadeOnDelete();
            // queued|discovering|fetching_metadata|preparing_insights|waiting_report|
            // downloading|normalizing|ready|partial|failed|needs_attention|waiting
            $table->string('status');
            $table->string('phase_label')->nullable();
            $table->date('earliest_date')->nullable();
            $table->date('latest_date')->nullable();
            $table->unsignedInteger('campaigns_total')->nullable();
            $table->unsignedInteger('campaigns_done')->nullable();
            $table->unsignedInteger('adsets_total')->nullable();
            $table->unsignedInteger('adsets_done')->nullable();
            $table->unsignedInteger('ads_total')->nullable();
            $table->unsignedInteger('ads_done')->nullable();
            $table->unsignedInteger('chunks_total')->nullable();
            $table->unsignedInteger('chunks_done')->nullable();
            $table->unsignedInteger('daily_facts_count')->default(0);
            $table->string('last_error_category')->nullable();
            $table->string('last_error_summary')->nullable();
            $table->foreignId('last_import_run_id')->nullable()->constrained('runs')->nullOnDelete();
            $table->dateTime('last_successful_at')->nullable();
            $table->timestamps();

            $table->unique('core_external_resource_id', 'meta_ads_account_import_states_resource_unique');
            $table->index(['core_integration_id', 'status'], 'meta_ads_account_import_states_integration_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ads_account_import_states');
    }
};
