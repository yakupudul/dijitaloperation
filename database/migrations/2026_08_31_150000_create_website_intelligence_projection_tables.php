<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_intelligence_projection_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('website_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->foreignId('trigger_collection_run_id')->nullable()->constrained('collection_runs')->nullOnDelete();
            $table->string('trigger', 48);
            $table->string('status', 32);
            $table->unsignedInteger('schema_version');
            $table->unsignedInteger('intelligence_registry_version');
            $table->date('period_start');
            $table->date('period_end');
            $table->json('source_watermarks')->nullable();
            $table->json('coverage_state')->nullable();
            $table->json('summary')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('error_code', 96)->nullable();
            $table->text('error_summary')->nullable();
            $table->timestampsTz();

            $table->index(['website_asset_id', 'status', 'created_at'], 'website_projection_run_asset_status_idx');
            $table->index(['trigger_collection_run_id'], 'website_projection_run_collection_idx');
        });

        Schema::create('website_page_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->foreignId('page_identity_id')->constrained('intelligence_page_identities')->cascadeOnDelete();
            $table->foreignId('projection_run_id')->constrained('website_intelligence_projection_runs')->cascadeOnDelete();
            $table->text('preferred_url');
            $table->json('source_states');
            $table->json('coverage_state')->nullable();
            $table->unsignedInteger('profile_version');
            $table->timestampTz('last_observed_at')->nullable();
            $table->timestampTz('projected_at');
            $table->timestampsTz();

            $table->unique(['website_asset_id', 'page_identity_id'], 'website_page_profile_identity_uq');
            $table->index(['website_asset_id', 'last_observed_at'], 'website_page_profile_observed_idx');
        });

        Schema::create('website_search_term_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->foreignId('search_term_identity_id')->constrained('intelligence_search_term_identities')->cascadeOnDelete();
            $table->foreignId('projection_run_id')->constrained('website_intelligence_projection_runs')->cascadeOnDelete();
            $table->text('canonical_text');
            $table->json('source_states');
            $table->json('coverage_state')->nullable();
            $table->unsignedInteger('profile_version');
            $table->timestampTz('last_observed_at')->nullable();
            $table->timestampTz('projected_at');
            $table->timestampsTz();

            $table->unique(['website_asset_id', 'search_term_identity_id'], 'website_term_profile_identity_uq');
            $table->index(['website_asset_id', 'last_observed_at'], 'website_term_profile_observed_idx');
        });

        Schema::create('website_entity_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->foreignId('entity_identity_id')->constrained('intelligence_entity_identities')->cascadeOnDelete();
            $table->foreignId('projection_run_id')->constrained('website_intelligence_projection_runs')->cascadeOnDelete();
            $table->string('entity_type', 48);
            $table->text('canonical_name');
            $table->json('source_states');
            $table->json('coverage_state')->nullable();
            $table->unsignedInteger('profile_version');
            $table->timestampTz('last_observed_at')->nullable();
            $table->timestampTz('projected_at');
            $table->timestampsTz();

            $table->unique(['website_asset_id', 'entity_identity_id'], 'website_entity_profile_identity_uq');
            $table->index(['website_asset_id', 'entity_type'], 'website_entity_profile_type_idx');
        });

        Schema::create('website_outcome_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->foreignId('business_action_identity_id')->constrained('intelligence_business_action_identities')->cascadeOnDelete();
            $table->foreignId('projection_run_id')->constrained('website_intelligence_projection_runs')->cascadeOnDelete();
            $table->string('action_key', 128);
            $table->string('display_name');
            $table->json('source_states');
            $table->json('coverage_state')->nullable();
            $table->unsignedInteger('profile_version');
            $table->timestampTz('last_observed_at')->nullable();
            $table->timestampTz('projected_at');
            $table->timestampsTz();

            $table->unique(['website_asset_id', 'business_action_identity_id'], 'website_outcome_profile_identity_uq');
            $table->index(['website_asset_id', 'action_key'], 'website_outcome_profile_action_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_outcome_profiles');
        Schema::dropIfExists('website_entity_profiles');
        Schema::dropIfExists('website_search_term_profiles');
        Schema::dropIfExists('website_page_profiles');
        Schema::dropIfExists('website_intelligence_projection_runs');
    }
};
