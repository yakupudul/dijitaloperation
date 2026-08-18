<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 53 — privacy-qualified Sector Learning artifacts + restricted lineage.
 *
 * Consumer Sector Memory reads artifacts/revisions only.
 * Lineage table is restricted internal infrastructure (deletion/recompute/audit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sector_learning_artifacts', function (Blueprint $table) {
            $table->id();
            $table->string('sector_code', 64)->index();
            $table->string('stable_key', 191)->unique();
            $table->string('artifact_kind', 64)->index();
            $table->string('status', 32)->index();
            $table->unsignedBigInteger('current_revision_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('sector_learning_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artifact_id')->constrained('sector_learning_artifacts')->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('status', 32)->index();
            $table->json('dimension_contract');
            $table->json('time_scope');
            $table->string('metric_family', 64)->nullable();
            $table->string('action_category', 64)->nullable()->index();
            $table->json('aggregate_result');
            $table->string('cohort_band', 32);
            $table->json('limitations');
            $table->string('privacy_policy_version', 64);
            $table->string('aggregation_method_version', 64);
            $table->string('projection_version', 64);
            $table->string('aggregate_fingerprint', 64);
            $table->string('observational_label', 64);
            $table->text('summary_text');
            $table->json('privacy_assessment');
            $table->unsignedInteger('internal_distinct_brands');
            $table->unsignedInteger('internal_distinct_customers');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['artifact_id', 'revision_number']);
            $table->unique(['artifact_id', 'aggregate_fingerprint']);
            $table->index(['privacy_policy_version', 'status']);
        });

        Schema::table('sector_learning_artifacts', function (Blueprint $table) {
            $table->foreign('current_revision_id')
                ->references('id')
                ->on('sector_learning_revisions')
                ->nullOnDelete();
        });

        Schema::create('sector_learning_lineage_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('revision_id')->constrained('sector_learning_revisions')->cascadeOnDelete();
            $table->unsignedBigInteger('brand_experience_id')->index();
            $table->unsignedBigInteger('brand_experience_revision_id')->index();
            $table->unsignedBigInteger('brand_id')->index();
            $table->unsignedBigInteger('customer_id')->index();
            $table->string('contribution_fingerprint', 64);
            $table->decimal('effective_weight', 8, 6)->default(1);
            $table->timestamps();

            $table->unique(
                ['revision_id', 'brand_experience_revision_id'],
                'sl_lineage_revision_experience_rev_unique'
            );
            $table->index(['brand_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sector_learning_lineage_entries');

        Schema::table('sector_learning_artifacts', function (Blueprint $table) {
            $table->dropForeign(['current_revision_id']);
        });

        Schema::dropIfExists('sector_learning_revisions');
        Schema::dropIfExists('sector_learning_artifacts');
    }
};
