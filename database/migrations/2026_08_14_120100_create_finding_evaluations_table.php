<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finding evaluation history. Historical truth lives here; findings store current projection.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('finding_evaluations')) {
            return;
        }

        Schema::create('finding_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('finding_id')->constrained('findings')->cascadeOnDelete();
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
            $table->timestamps();

            $table->unique('evaluation_fingerprint', 'finding_evaluations_fingerprint_unique');
            $table->index(['finding_id', 'evaluated_at'], 'finding_evaluations_finding_evaluated_index');
            $table->index('rule_id', 'finding_evaluations_rule_id_index');
        });

        if (! Schema::hasTable('finding_evaluation_evidence')) {
            Schema::create('finding_evaluation_evidence', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('finding_evaluation_id')->constrained('finding_evaluations')->cascadeOnDelete();
                $table->foreignId('evidence_id')->constrained('evidence')->cascadeOnDelete();
                $table->char('evidence_observation_fingerprint', 64);
                $table->timestamps();

                $table->unique(
                    ['finding_evaluation_id', 'evidence_id'],
                    'finding_evaluation_evidence_unique',
                );
                $table->index('evidence_id', 'finding_evaluation_evidence_evidence_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finding_evaluation_evidence');
        Schema::dropIfExists('finding_evaluations');
    }
};
