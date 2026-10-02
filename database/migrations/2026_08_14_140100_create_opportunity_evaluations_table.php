<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opportunity evaluation history. Historical truth lives here; opportunities store
 * the current projection. Mirrors finding_evaluations plus commercial-context snapshots.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('opportunity_evaluations')) {
            Schema::create('opportunity_evaluations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
                $table->string('rule_id', 128);
                $table->unsignedInteger('rule_version');
                $table->char('evaluation_fingerprint', 64);
                $table->string('condition_result', 32);
                $table->string('eligibility_disposition', 64);
                $table->string('block_reason', 64)->nullable();
                $table->dateTime('evaluated_at');
                $table->json('operand_snapshot')->nullable();
                $table->json('threshold_snapshot')->nullable();
                $table->string('freshness_state', 64)->nullable();
                $table->string('integrity_state', 64)->nullable();
                $table->string('completeness_state', 64)->nullable();
                $table->string('lifecycle_action', 32);
                $table->unsignedBigInteger('run_id')->nullable();
                $table->json('service_context_snapshot')->nullable();
                $table->json('goal_ids_snapshot')->nullable();
                $table->json('offering_ids_snapshot')->nullable();
                $table->json('market_context_snapshot')->nullable();
                $table->string('commercial_scope_state', 32)->nullable();
                $table->string('qualitative_priority', 32)->nullable();
                $table->timestamps();

                $table->unique('evaluation_fingerprint', 'opportunity_evaluations_fingerprint_unique');
                $table->index(['opportunity_id', 'evaluated_at'], 'opportunity_evaluations_opportunity_evaluated_index');
                $table->index('rule_id', 'opportunity_evaluations_rule_id_index');
            });
        }

        if (! Schema::hasTable('opportunity_evaluation_evidence')) {
            Schema::create('opportunity_evaluation_evidence', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('evaluation_id')->constrained('opportunity_evaluations')->cascadeOnDelete();
                $table->foreignId('evidence_id')->constrained('evidence')->cascadeOnDelete();
                $table->char('evidence_observation_fingerprint', 64);
                $table->timestamps();

                $table->unique(
                    ['evaluation_id', 'evidence_id'],
                    'opportunity_evaluation_evidence_unique'
                );
                $table->index('evidence_id', 'opportunity_evaluation_evidence_evidence_index');
            });
        }

        if (! Schema::hasTable('opportunity_evaluation_finding')) {
            Schema::create('opportunity_evaluation_finding', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('evaluation_id')->constrained('opportunity_evaluations')->cascadeOnDelete();
                $table->foreignId('finding_id')->constrained('findings')->cascadeOnDelete();
                $table->unsignedBigInteger('finding_evaluation_id')->nullable();
                $table->timestamps();

                $table->unique(
                    ['evaluation_id', 'finding_id'],
                    'opportunity_evaluation_finding_unique'
                );
                $table->index('finding_id', 'opportunity_evaluation_finding_finding_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_evaluation_finding');
        Schema::dropIfExists('opportunity_evaluation_evidence');
        Schema::dropIfExists('opportunity_evaluations');
    }
};
