<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intelligence_page_identities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('website_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->char('identity_hash', 64)->unique();
            $table->text('preferred_url');
            $table->char('preferred_url_hash', 64);
            $table->string('scheme', 16);
            $table->string('host');
            $table->text('path');
            $table->string('resolution_status', 32);
            $table->string('normalization_version', 32);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampsTz();

            $table->unique(['website_asset_id', 'preferred_url_hash'], 'intel_page_asset_preferred_url_uq');
            $table->index(['website_asset_id', 'resolution_status'], 'intel_page_asset_resolution_idx');
        });

        Schema::create('intelligence_page_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_identity_id')->constrained('intelligence_page_identities')->cascadeOnDelete();
            $this->addSourceColumns($table);
            $table->string('source_semantic', 64);
            $table->string('alias_kind', 48);
            $table->text('observed_url');
            $table->char('observed_url_hash', 64);
            $table->text('join_url');
            $table->char('join_url_hash', 64);
            $this->addResolutionColumns($table);
            $table->timestampsTz();

            $table->index(['page_identity_id', 'source_semantic'], 'intel_page_alias_identity_semantic_idx');
            $table->index(['join_url_hash', 'resolution_status'], 'intel_page_alias_join_resolution_idx');
        });

        Schema::create('intelligence_search_term_identities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->char('identity_hash', 64)->unique();
            $table->text('canonical_text');
            $table->text('folded_text');
            $table->string('language_code', 32)->nullable();
            $table->string('locale', 32)->nullable();
            $table->string('market_code', 32)->nullable();
            $table->string('resolution_status', 32);
            $table->string('normalization_version', 32);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampsTz();

            $table->index(['brand_id', 'resolution_status'], 'intel_term_brand_resolution_idx');
            $table->index(['brand_id', 'language_code', 'market_code'], 'intel_term_brand_market_idx');
        });

        Schema::create('intelligence_search_term_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('search_term_identity_id')->constrained('intelligence_search_term_identities')->cascadeOnDelete();
            $this->addSourceColumns($table);
            $table->string('term_kind', 48);
            $table->text('observed_text');
            $table->text('normalized_text');
            $table->text('folded_text');
            $this->addResolutionColumns($table);
            $table->timestampsTz();

            $table->index(['search_term_identity_id', 'term_kind'], 'intel_term_alias_identity_kind_idx');
            $table->index(['provider_or_source', 'term_kind'], 'intel_term_alias_provider_kind_idx');
        });

        Schema::create('intelligence_entity_identities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->char('identity_hash', 64)->unique();
            $table->string('entity_type', 48);
            $table->text('canonical_name');
            $table->text('normalized_name');
            $table->string('country_code', 8)->nullable();
            $table->string('language_code', 32)->nullable();
            $table->string('resolution_status', 32);
            $table->string('normalization_version', 32);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampsTz();

            $table->index(['brand_id', 'entity_type'], 'intel_entity_brand_type_idx');
            $table->index(['brand_id', 'resolution_status'], 'intel_entity_brand_resolution_idx');
        });

        Schema::create('intelligence_entity_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entity_identity_id')->constrained('intelligence_entity_identities')->cascadeOnDelete();
            $this->addSourceColumns($table);
            $table->string('source_semantic', 64);
            $table->string('external_entity_id')->nullable();
            $table->string('alias_kind', 48);
            $table->text('observed_name');
            $table->text('normalized_name');
            $this->addResolutionColumns($table);
            $table->timestampsTz();

            $table->index(['entity_identity_id', 'source_semantic'], 'intel_entity_alias_identity_semantic_idx');
            $table->index(['provider_or_source', 'external_entity_id'], 'intel_entity_alias_provider_external_idx');
        });

        Schema::create('intelligence_business_action_identities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->char('identity_hash', 64)->unique();
            $table->string('action_key', 128);
            $table->string('action_kind', 48);
            $table->string('display_name');
            $table->text('semantic_definition')->nullable();
            $table->string('status', 32);
            $table->unsignedInteger('definition_version')->default(1);
            $table->timestampsTz();

            $table->unique(['brand_id', 'action_key'], 'intel_action_brand_key_uq');
            $table->index(['brand_id', 'status'], 'intel_action_brand_status_idx');
        });

        Schema::create('intelligence_business_action_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_action_identity_id')->constrained('intelligence_business_action_identities')->cascadeOnDelete();
            $this->addSourceColumns($table);
            $table->string('source_semantic', 64);
            $table->string('signal_class', 48);
            $table->string('provider_action_id')->nullable();
            $table->text('observed_name');
            $table->text('normalized_name');
            $this->addResolutionColumns($table);
            $table->timestampsTz();

            $table->index(['business_action_identity_id', 'signal_class'], 'intel_action_alias_identity_signal_idx');
            $table->index(['provider_or_source', 'provider_action_id'], 'intel_action_alias_provider_action_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intelligence_business_action_aliases');
        Schema::dropIfExists('intelligence_business_action_identities');
        Schema::dropIfExists('intelligence_entity_aliases');
        Schema::dropIfExists('intelligence_entity_identities');
        Schema::dropIfExists('intelligence_search_term_aliases');
        Schema::dropIfExists('intelligence_search_term_identities');
        Schema::dropIfExists('intelligence_page_aliases');
        Schema::dropIfExists('intelligence_page_identities');
    }

    private function addSourceColumns(Blueprint $table): void
    {
        $table->foreignId('source_digital_asset_id')->nullable()->constrained('digital_assets')->nullOnDelete();
        $table->foreignId('external_resource_id')->nullable()->constrained('core_external_resources')->nullOnDelete();
        $table->foreignId('collection_run_id')->nullable()->constrained('collection_runs')->nullOnDelete();
        $table->foreignId('dataset_run_id')->nullable()->constrained('collection_dataset_runs')->nullOnDelete();
        $table->char('source_fingerprint', 64)->unique();
        $table->string('provider_or_source', 64);
        $table->string('source_class', 48);
        $table->string('source_dataset_id')->nullable();
        $table->string('source_record_key')->nullable();
    }

    private function addResolutionColumns(Blueprint $table): void
    {
        $table->string('match_method', 48);
        $table->string('resolution_status', 32);
        $table->string('source_timezone', 64)->nullable();
        $table->string('market_code', 32)->nullable();
        $table->string('language_code', 32)->nullable();
        $table->timestampTz('first_observed_at');
        $table->timestampTz('last_observed_at');
        $table->json('metadata')->nullable();
    }
};
