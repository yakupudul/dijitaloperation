<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospect_report_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prospect_research_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('prospect_sales_intelligence_id')->nullable()->constrained('prospect_sales_intelligence')->nullOnDelete();
            $table->string('projection');
            $table->string('locale', 8)->default('en');
            $table->string('title');
            $table->json('content_payload');
            $table->string('content_checksum', 64);
            $table->string('idempotency_key')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at');
            $table->timestamp('created_at');

            $table->unique(['prospect_id', 'idempotency_key']);
            $table->index(['prospect_id', 'projection']);
        });

        Schema::create('prospect_report_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prospect_report_snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('artifact_type')->default('pdf');
            $table->string('renderer_version');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('checksum', 64);
            $table->unsignedInteger('byte_size')->default(0);
            $table->timestamp('created_at');

            $table->unique(['prospect_report_snapshot_id', 'renderer_version']);
        });

        Schema::create('prospect_report_share_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prospect_report_snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('locator_token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sales_search_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('service_definition_code')->nullable();
            $table->string('language', 16)->nullable();
            $table->string('country', 8)->nullable();
            $table->string('location')->nullable();
            $table->json('include_concepts');
            $table->json('exclude_concepts')->nullable();
            $table->unsignedTinyInteger('minimum_intent_confidence')->default(60);
            $table->boolean('active')->default(true);
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('active');
            $table->index('service_definition_code');
        });

        Schema::create('sales_intent_radar_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_search_profile_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->string('provider')->nullable();
            $table->string('provider_reality')->nullable();
            $table->unsignedInteger('query_count')->default(0);
            $table->unsignedInteger('signal_count')->default(0);
            $table->boolean('paid_call')->default(false);
            $table->decimal('reported_cost_usd', 10, 4)->nullable();
            $table->json('query_plan')->nullable();
            $table->json('error_summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['sales_search_profile_id', 'status']);
        });

        Schema::create('sales_intent_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_search_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_intent_radar_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type');
            $table->string('source_url');
            $table->string('source_title')->nullable();
            $table->text('observed_snippet');
            $table->text('fetched_source_excerpt')->nullable();
            $table->string('source_verification_state');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('discovered_at');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->string('intent_category')->nullable();
            $table->string('service_definition_code')->nullable();
            $table->unsignedTinyInteger('intent_confidence')->nullable();
            $table->string('purchase_stage')->nullable();
            $table->string('classification_status');
            $table->text('classification_reason')->nullable();
            $table->json('negative_signals')->nullable();
            $table->string('identity_status')->default('unknown');
            $table->unsignedTinyInteger('identity_confidence')->nullable();
            $table->string('detected_company_name')->nullable();
            $table->string('detected_domain')->nullable();
            $table->foreignId('prospect_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('new');
            $table->string('fingerprint', 64);
            $table->json('provenance')->nullable();
            $table->timestamps();

            $table->unique('fingerprint');
            $table->index(['sales_search_profile_id', 'status']);
            $table->index('intent_confidence');
        });

        Schema::create('sales_intent_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_search_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sales_intent_radar_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sales_intent_signal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('prospect_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_intent_activities');
        Schema::dropIfExists('sales_intent_signals');
        Schema::dropIfExists('sales_intent_radar_runs');
        Schema::dropIfExists('sales_search_profiles');
        Schema::dropIfExists('prospect_report_share_grants');
        Schema::dropIfExists('prospect_report_artifacts');
        Schema::dropIfExists('prospect_report_snapshots');
    }
};
