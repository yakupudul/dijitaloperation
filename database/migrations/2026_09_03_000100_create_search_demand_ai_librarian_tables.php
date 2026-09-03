<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_query_library_items', function (Blueprint $table): void {
            $table->string('search_intent', 80)->nullable();
            $table->text('user_problem')->nullable();
            $table->string('decision_stage', 80)->nullable();
            $table->string('serp_intent_group')->nullable();
            $table->string('content_target_cluster')->nullable();
            $table->string('classification_source', 48)->nullable();
            $table->unsignedTinyInteger('classification_confidence')->nullable();
            $table->string('classification_version', 120)->nullable();
            $table->timestampTz('classified_at')->nullable();
            $table->foreignId('classified_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(
                ['search_intent', 'decision_stage'],
                'query_library_intent_stage_idx',
            );
        });

        Schema::create('search_demand_ai_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('operation_type', 32);
            $table->foreignId('service_catalog_item_id')->nullable()->constrained('service_catalog_items')->nullOnDelete();
            $table->string('status', 24)->default('queued');
            $table->json('input_payload');
            $table->char('input_fingerprint', 64);
            $table->string('agent_signature', 160);
            $table->json('skill_signatures');
            $table->char('skill_fingerprint', 64);
            $table->string('route_key', 160);
            $table->string('route_signature', 500);
            $table->string('provider', 80)->nullable();
            $table->string('model', 160)->nullable();
            $table->unsignedInteger('total_candidates')->default(0);
            $table->unsignedInteger('pending_candidates')->default(0);
            $table->unsignedInteger('approved_candidates')->default(0);
            $table->unsignedInteger('rejected_candidates')->default(0);
            $table->boolean('abstained')->default(false);
            $table->text('abstention_reason')->nullable();
            $table->foreignId('reused_from_run_id')->nullable()->constrained('search_demand_ai_runs')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('error_code', 120)->nullable();
            $table->text('error_summary')->nullable();
            $table->timestampsTz();

            $table->index(['operation_type', 'status'], 'search_demand_ai_run_operation_status_idx');
            $table->index(['input_fingerprint', 'status'], 'search_demand_ai_run_input_status_idx');
        });

        Schema::create('search_demand_ai_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('search_demand_ai_run_id')->constrained('search_demand_ai_runs')->cascadeOnDelete();
            $table->foreignId('source_search_query_library_item_id')->nullable()->constrained('search_query_library_items')->nullOnDelete();
            $table->foreignId('service_catalog_item_id')->nullable()->constrained('service_catalog_items')->nullOnDelete();
            $table->char('candidate_fingerprint', 64);
            $table->text('original_text');
            $table->text('proposed_text');
            $table->string('service_alias')->nullable();
            $table->string('demand_family')->nullable();
            $table->string('search_intent', 80)->nullable();
            $table->text('user_problem')->nullable();
            $table->string('decision_stage', 80)->nullable();
            $table->string('serp_intent_group')->nullable();
            $table->string('content_target_cluster')->nullable();
            $table->string('location_scope', 32)->default('none');
            $table->string('location_value')->nullable();
            $table->boolean('is_branded_suspected')->default(false);
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->boolean('abstained')->default(false);
            $table->text('abstention_reason')->nullable();
            $table->text('rationale')->nullable();
            $table->string('status', 24)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('applied_item_id')->nullable()->constrained('search_query_library_items')->nullOnDelete();
            $table->json('raw_output')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['search_demand_ai_run_id', 'candidate_fingerprint'],
                'search_demand_ai_candidate_fingerprint_uq',
            );
            $table->index(
                ['search_demand_ai_run_id', 'status'],
                'search_demand_ai_candidate_run_status_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_demand_ai_candidates');
        Schema::dropIfExists('search_demand_ai_runs');

        Schema::table('search_query_library_items', function (Blueprint $table): void {
            $table->dropIndex('query_library_intent_stage_idx');
            $table->dropConstrainedForeignId('classified_by');
            $table->dropColumn([
                'search_intent',
                'user_problem',
                'decision_stage',
                'serp_intent_group',
                'content_target_cluster',
                'classification_source',
                'classification_confidence',
                'classification_version',
                'classified_at',
            ]);
        });
    }
};
